# Rider account API

This is the implemented local contract for Flutter account access. It uses the
same users, courier profiles, application validation, private document storage
and registration service as the web application. It adds no parcel operations.

## Routes under `/api/v1`

| Method / route | Request | Result |
|---|---|---|
| POST `/auth/tokens` | `email`, `password`, `device_name` | `data.token`, `token_type`, `expires_at`, `user` |
| GET `/rider/me` | Bearer token | `data` containing own account |
| DELETE `/auth/tokens/current` | Bearer token | Revokes this device token; `message` |
| GET `/rider/registration/options` | None | Vehicle types, Manila adult birthday limit, document/identifier limits, demographics, password policy and current policy documents/versions |
| POST `/rider/registration/email/send` | `email` | Delivered-code result, cooldown, expiry; never the code |
| POST `/rider/registration/email/verify` | `email`, six ASCII digits in `code` | Single-use registration verification `token` |
| POST `/rider/applications` | Multipart application and documents | Created pending courier, verified email and own-account session |

The application requires a personal name, `birthday`, `email`, Philippine `phone`,
`address`, `city`, `vehicle_type`, `plate_number`, `license_number`, password/confirmation,
`otp_token`, `device_name` and private `id_document`, `driver_license`,
`or_cr_document` uploads. The shared validator governs optional province,
municipality, barangay, sex and postal code. New courier applications require
the licence identifier on both web and native paths. Sex is optional and uses
`Female`, `Male` or `Other`; optional postal code is exactly four ASCII digits
stored as a string. Images/PDF use the 5 MB-per-file policy (5,242,880 bytes),
matching content and extension. Images must decode within 10,000 pixels per
side and 20 million pixels total. Required document checks run before account creation.
The server assigns the immutable courier role and pending approval statuses.

Credentials are the existing account credentials, with case-insensitive email
lookup. Invalid credentials produce 422; invalid input has Laravel `errors`.
Wrong-role, closed, suspended, inactive, unknown or underage-ineligible accounts
receive 403. Pending/rejected applications may see only their own holding data.
An approved account flag is account eligibility, not placement, duty or authority
to perform a parcel action.

## Registration validation handoff

Existing `name`-only applications remain supported. A client may instead supply
`first_name`, `last_name` and optional `middle_name`. If any of those fields is
present, first and last names are required and the server constructs `name`
from the normalized parts, ignoring a conflicting supplied display name.
First and last names allow 2–50 characters each. A supplied middle name allows
1–50 characters; it may be omitted. Clients may use `middle_initial` instead:
one Latin letter and an optional period, at most 2 characters. When present,
that alias takes precedence over `middle_name`, is validated at preflight and
submission, and is stored in the existing middle-name column. It requires first
and last names even if no other name component was supplied. Existing full
middle names are preserved.
The combined name allows 2–100 characters including spaces. Latin letters with
accents, spaces, apostrophes, hyphens and periods are accepted; digits, unsupported symbols,
controls and visually empty names are rejected with field errors. A failed
name check does not consume the verified registration token or store uploads.

New registration emails trim outer spaces and store the entire address in
lowercase. Existing sign-in addresses remain unchanged and ownership/sign-in
comparisons stay case-insensitive. Internal whitespace, controls and more than
255 characters are rejected. Courier mobile numbers must normalize to
`+639XXXXXXXXX`; short, overlong, non-ASCII or non-mobile numbers are rejected.

The options response includes `data.password_policy.min_length = 12` and
`max_length = 128`. New applications and password resets require 12–128
characters, including Unicode characters, with uppercase, lowercase, number
and punctuation/symbol. Spaces alone do not satisfy the symbol requirement;
control characters are rejected and passphrase spaces are preserved.
Existing shorter sign-in passwords remain
usable. The web backend defaults to Argon2id so the end of a long password is
verified; existing bcrypt credentials and OTP challenges still verify.

Options now include `password_policy.uppercase`, `lowercase`, `number` and
`symbol`, all true, and `registration_policies` containing `required`,
`terms_version`, `privacy_version`, `terms_url` and `privacy_url`. Resolve the
relative document URLs against the backend origin. Show a required unchecked
acknowledgement and send `policy_accepted`, `terms_version` and `privacy_version`
with the multipart application. Missing/false acknowledgement or stale versions
receive 422 field errors without consuming the verified token or storing files.
Reload the policy options and ask for fresh acknowledgement after a version change;
never automatically tick consent to make an old client pass. The backend records
its own time and source atomically with the account. Existing account login does
not require invented historical acknowledgement.

Options expose `sex_options`, `sex_required = false`, `postal_code_length = 4`
and `postal_code_required = false`; show the controls in the initial native
application, not only later correction. Plate input is at most eight characters
including one optional separator, with supported vehicle-specific formats.
Licence input uses one letter and ten digits, compact or grouped as
`N01-12-345678`; options expose lengths 11/13. See the
[registration field review](auth-plan/REGISTRATION_FIELDS.md) for formats and
their evidence-review boundary. The Flutter application must adopt these
contracts separately; this backend delivery does not modify its source.

Email submission requires a complete address with a qualified domain. Custom
domains and the existing `@bagoo.test` demo addresses remain supported; there is
no provider allowlist or DNS check. Format acceptance does not prove inbox
ownership. The registration code still has to be delivered and verified. OTP
requests reject an address already owned as an original identity or additional
contact address, including case-equivalent and legacy identity records.

The native client should use the advertised limits, retain raw invalid phone
input for correction, show the API's field errors and preserve safe progress
after a rejected request. These backend changes do not edit the Flutter project
or certify its screens. First web login can rehash a legacy bcrypt credential;
an older native token bound to the previous password fingerprint may then need
a fresh sign-in. Deployment details are in the
[authentication rollout](auth-plan/ROLLOUT.md).

## Account and session boundaries

Own-account JSON allowlists `id` (string), `name`, `email`, `email_verified`,
`role`, `status`, `kyc_status`, `kyc_feedback`, `can_access_portal`, `access_state`.
It contains no password, private document paths, foreign profile or operational
cash/parcel data. Session responses and errors use private `no-store` headers.

Sanctum stores hashed tokens. This adapter accepts the actual bearer on every
request, with narrow account/logout abilities, explicit expiry and a stored
password fingerprint. It never accepts a browser cookie as a native bearer.
Expiry, password changes and newly denied account gates invalidate access;
denied existing account tokens are deleted. Logout revokes only the presented
device. Default lifetime is 1,440 minutes, configurable with
`RIDER_TOKEN_MINUTES` (bounded to 1–10,080 minutes). Prune expired tokens with
the standard `sanctum:prune-expired` command in deployment scheduling.

Registration verifies the email token before storage and consumes it inside
the same database transaction as account creation. Failed persistence removes
new private files. Web submission retains its existing redirects, browser
session and verification-email fallback; both transports share account creation.
No client-selected role, approval, account, hub or company identifier is accepted
through the native adapter.

Browser origins are explicitly configured by `RIDER_BROWSER_ORIGINS`; default
is empty, with no wildcard and no credentialed cookies. Flutter browser account
testing is limited to an explicitly enabled debug loopback build with synthetic
accounts and memory-only tokens. Production native tokens use platform storage.

## Run the isolated local demo

From this backend branch, with its normal dependencies/image available:

```sh
python3 tool/rider_dev_inbox.py
```

In another terminal:

```sh
python3 tool/run_rider_account_demo.py
```

The actual Laravel app listens on loopback port 8089, backed by a separate
`storage/app/rider-demo.sqlite` with synthetic accounts. Existing PostgreSQL and
seeded accounts are untouched. The SMTP receiver accepts only `.test` recipients;
the local inbox on port 8028 shows delivered codes. Neither helper logs codes or
credentials. The demo account follows the existing DatabaseSeeder convention:
`rider@bagoo.test` / `Password1234`. New applications remain pending review.

Run `python3 tool/check_rider_account_demo.py` to check live login/revocation,
SMTP verification/private upload registration, native-created account web login
and web-created account native login/logout. This uses actual requests and the
same local database; it is distinct from isolated PHP fixtures.

Before deployment, apply the additive token migration, configure real email
delivery, HTTPS and expiry/pruning, and test the approved API origin. Local
acceptance does not certify deployment or Android device behavior.

See [Sanctum](https://laravel.com/framework/docs/13.x/sanctum) for the underlying
token API. The account gate and wire resources here are Bagoo-specific.
