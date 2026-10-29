# Native buyer Google sign-in

This is the web-owned cookie/session contract. Native Google SDK integration and
owner provider configuration are separate prerequisites. Existing browser OAuth,
email sign-in, OTP and buyer approval rules retain their current behavior.

## Transport and readiness

Use the same buyer origin, cookie jar and CSRF bootstrap as ordinary buyer actions.
These routes always return JSON, including validation, CSRF, throttle and server
errors. They never return a provider redirect or an Inertia page. Request
`Accept: application/json`; submit JSON with `Content-Type: application/json` and
the current `X-XSRF-TOKEN`/`X-CSRF-TOKEN` using the existing adapter. Preserve cookies
from successful responses. A successful sign-in rotates session ID and CSRF; obtain
the current CSRF value before the next write.

All responses are `private, no-store`, `Vary: Cookie`, with `nosniff`. Native routes
on a known worker subdomain return 403 `BUYER_ORIGIN_REQUIRED`, without redirecting.
No bearer token or permissive CORS exemption is added.

Google is unavailable until `BUYER_NATIVE_GOOGLE_ENABLED=true`, approved audiences
and native authorized parties are configured, and the native routes exist. Missing
or malformed configuration fails closed. Each client list accepts at most 16
distinct identifiers ending in `.apps.googleusercontent.com`; configure the server
OAuth audience separately from approved Android/iOS authorized-party client IDs.
The capability indicates configured server support, not provider liveness, SDK
installation, release certification or physical-device acceptance.

## Routes

| Method and path | Input | Success | Limit |
| --- | --- | --- | --- |
| POST `/auth/google/native` | `id_token`; optional `remember`, `account_password` | 200: exactly `{"authenticated":true}` or `{"registration_required":true}` | 10/minute |
| GET `/auth/google/native/registration` | Original guest session | 200 registration object below | 30/minute |
| POST `/auth/google/native/register` | Original session, nonce and buyer fields | 200: exactly `{"authenticated":true}` | 10/minute |

Throttle buckets are separate, by guest IP or authenticated user. A 429 includes
`Retry-After`. Mutation routes use the existing session lock, held for 10 seconds
with a 5 second acquisition wait; a lock/storage failure returns safe503. Deploy
with a shared supported cache/lock store and the existing session runtime.

Exchange fields:

- `id_token`: required string, at most 16,384 characters and bytes; never persist,
  log or attach it to URLs. It is not an OAuth access token.
- `remember`: optional boolean, default false; Laravel accepts its usual boolean
  representations, but native clients should send a JSON boolean.
- `account_password`: optional nullable string, at most 255 characters. Preserve
  spaces. It is used only for the first link to an existing email/password buyer;
  it is never sent to Google. Keep it in memory only.

Only verified claims establish identity. Request email, role, Google subject,
approval and status fields cannot change identity or account privileges.

## Verification and account rules

The maintained `firebase/php-jwt` verifier accepts RS256 signatures against the
fixed HTTPS Google JWKS endpoint. It validates issuer, approved audience, expiry,
issued/not-before times, subject, valid email and boolean verified-email claims.
An `azp` claim must be approved when present; multiple audiences require it.
Token-provided key URLs and unsupported critical headers are rejected.

Trusted keys honor provider no-store/no-cache and max-age, capped at six hours
(default five minutes when no max-age is supplied). Unknown keys can trigger one shared refresh per 60 seconds. Network access
uses verified TLS, no redirects, a 3 second connection timeout and 5 second total
timeout, with no implicit retries. Missing/unusable keys, provider outages and
cache failures return503. Tokens and provider response details are excluded from
error responses and exception logs for these routes, including debug mode.

A linked Google subject signs into its existing eligible buyer and preserves the
original Bagoo email even if the provider email changes. A subject already linked
to another role cannot be converted into a buyer. An unlinked existing buyer email
requires its Bagoo password before the subject is attached. Missing proof returns
409 `GOOGLE_ACCOUNT_LINK_REQUIRED`; incorrect proof returns 422 with
`errors.account_password`. A conflicting existing Google link returns 409
`GOOGLE_IDENTITY_CONFLICT`. No automatic email-only linking occurs here.

This safeguard follows [Google's account-linking guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token):
verification of some external email addresses alone is insufficient current
ownership proof. Existing browser OAuth behavior is covered by regression tests
and is not migrated by this native endpoint.

An existing authenticated buyer may repeat the same linked identity successfully.
A different subject returns 409 `ACCOUNT_SWITCH_REQUIRED`; sign out explicitly
before switching. Other-role sessions and closed/ineligible buyers return403.
Restricted buyers retain their existing holding/history access and current status;
successful authentication never grants checkout or operational approval.

## New-buyer registration

New identities receive `registration_required` without creating an account.
GET the registration contract in the same session:

```json
{
  "identity": {"name":"Synthetic Buyer","email":"native-buyer@bagoo.test"},
  "registrationNonce":"<SESSION_BOUND_NONCE>",
  "expiresAt":"<UTC_UNIX_SECONDS>",
  "birthDateLimits": {
    "today":"<TODAY_YYYY-MM-DD>",
    "past_maximum":"<YESTERDAY_YYYY-MM-DD>",
    "adult_maximum":"<18_YEARS_AGO_YYYY-MM-DD>"
  },
  "policies": {
    "required":true,
    "terms_version":"2026-10-12", "privacy_version":"2026-10-12",
    "terms_url":"/terms/2026-10-12", "privacy_url":"/privacy/2026-10-12"
  }
}
```

The expiry placeholder represents a JSON **integer** UTC Unix timestamp, not a
string in a real response. The nonce is 40 alphanumeric characters, with a maximum
lifetime of 600 seconds and never beyond the verified ID token's expiry. Names and
email are non-null strings; optional provider avatar is not part of this response.
The policy values above are illustrative current versions: always use returned
versions/URLs. Birth limits use Philippine calendar dates.

Completion accepts `registration_nonce` (required string, maximum 64 characters),
`first_name`, `last_name` (required 2–50 character names; combined name at most 100),
optional `middle_initial` (one/two letters, optional period), `sex` (`Female`,
`Male`, `Other`), `birthday` (real `YYYY-MM-DD`, age 18 or older), and accepted
`policy_accepted` plus current `terms_version`/`privacy_version`.

Optional `phone` is at most 24 characters before canonical Philippine mobile and
uniqueness validation. Optional `address` is 5–500 characters; `city`, `province`,
`municipality`, `barangay` use 2–255 character location labels; `postal_code` is
four ASCII digits. If supplying an address, use the existing canonical region
contract: `region` is a ten-digit code and province/city/barangay must belong to it.
Omit the whole optional address rather than supplying inconsistent partial values.
No private ID upload is required for this Google identity exchange; ordinary buyer
verification remains a separate, unchanged requirement.

The server uses `GoogleRegistrationService`, existing validation/address/age and
policy services. Native pending identity uses `registration.native_google`,
separate from browser Google and OTP challenges. Wrong purpose/session/nonce or
expiry returns 422; field errors leave a still-valid challenge available for a
corrected explicit submission. A successful transaction consumes the challenge,
records policy acceptance and creates only an active buyer with `kyc_status=none`.
Remember uses the original exchange choice. Email/subject uniqueness protects
against two sessions creating duplicate identities; real PostgreSQL concurrency
acceptance remains separate from SQLite tests.

## Errors, retries and uncertainty

| Status | Meaning and action |
| --- | --- |
| 403 | Wrong role/origin, closed or ineligible account; do not switch roles |
| 409 | Explicit link/switch conflict, or `ALREADY_AUTHENTICATED` on registration; inspect code/current session |
| 419 | CSRF/session expired; clear stale native state, bootstrap again, never replay a write automatically |
| 422 | `message` and `errors` map of field to string array; correct fields or restart an expired Google challenge |
| 429 | Respect `Retry-After`; preserve ordinary email sign-in |
| 503 | `SERVICE_UNAVAILABLE`, generic message; keep email sign-in available |

There is no universal idempotency key. Session locking and database uniqueness do
not authorize automatic POST replay. If a response is lost, read `/auth/session`
first. An authenticated result is resolved through the existing allowed buyer
destination and authenticated props. Otherwise GET the native registration
contract; preserve a valid challenge or explicitly restart Google when expired.
A successful completion repeated while authenticated returns 409 rather than
creating another account. No cancellation endpoint is introduced; restarting
exchange replaces only the native challenge, while expiry/session logout clears it.

See the [fixture pack](buyer-mobile-contract-handoff/README.md),
[association contract](BUYER_APP_ASSOCIATIONS.md) and
[combined integration report](BUYER_MOBILE_INTEGRATION.md) for executed evidence,
environment status, deployment order and remaining prerequisites.
