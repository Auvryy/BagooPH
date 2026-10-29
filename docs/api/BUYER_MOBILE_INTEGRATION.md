# Buyer mobile web integration

The native buyer app uses the existing Laravel cookie/CSRF session, Inertia page
props and JSON actions. These contracts remain in place. This document describes
the web-owned additions; source support, isolated acceptance, publication,
deployment and physical-device acceptance are separate states.

## Capability contract, version 1

`GET /buyer/mobile-capabilities` returns a JSON object directly. Authentication
and a submitted CSRF token are not required. A guest session cookie may be issued
by the ordinary web middleware. The response is `private, no-store`, not a public
catalogue cache entry.

```json
{
  "contract_version": 1,
  "features": {
    "native_google_sign_in": false,
    "device_push": false
  }
}
```

Both flags are required JSON booleans. Unknown versions, malformed replies,
missing flags, redirects or failed requests must not enable optional controls.
Discovery failure must not prevent ordinary email sign-in, shopping or the native
notification inbox. There is no user identity, provider client identifier or
credential in the response. Query parameters cannot enable features.

Requests are limited to 60 per minute per IP in a dedicated throttle bucket.
429 carries `Retry-After`; clients should keep optional features unavailable and
use bounded retry. Existing session recognition has its own throttle bucket.
The ordinary closed-account middleware may clear a closed session and return 403.

Google remains false unless the explicitly enabled configuration has valid
approved server audiences and native authorized parties, and both native server
routes are installed. The enable switch defaults to false. This is capability
negotiation, not proof that an owner has completed physical-device acceptance.
Push is deferred by the owner and is always false in this version.

The [disabled fixture](buyer-mobile-contract-handoff/capabilities/disabled.json)
is an envelope containing request metadata and `response.body`. Tests compare
that body and its cache policy with an executed route response. It contains no
Cookie, Set-Cookie or CSRF value.

## Existing transport and buyer contracts

Keep `GET /auth/session` as the session-recognition authority. Its guest reply is
`{"authenticated":false}`; an authenticated reply has `authenticated`, `code`
and the same-origin `redirect`, without a role field. The current app verifies
the buyer and numeric owner ID from the allowed destination's authenticated props.

Initial page reads bootstrap the ordinary session/CSRF values. Inertia reads
retain component/props/url/version behavior. Existing JSON/multipart writers keep
CSRF and server validation. Session expiry, owner/role denial, stock, original
prices, company eligibility, wallet/COD and order/return transitions remain
server-owned. No endpoint here converts a pending buyer into an approved buyer.
Do not globally replace web sessions with rider tokens or permissive CORS.

Future chat pagination, separate read acknowledgements, conditional private reads,
public catalogue revisions and image renditions remain individually deferred.
Current chat GET read side effects and current web/mobile payloads are preserved.

## Native Google and HTTPS associations

The selected next additions are verified native Google exchange/registration and
owner-configured buyer-only Android/iOS associations. Their exact implemented
schemas, rejection/retry behavior and fixtures will be added here with acceptance
evidence. Until then these are planned contracts, not deployed endpoints.

Google SDK integration and platform signing/domain configuration remain mobile
and owner work. Provider credentials never belong in app assets or fixtures.
Server verification follows [Google's ID-token verification guidance](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

## Publication and environment evidence

Current implementation branch: `feat/buyer-mobile-web-integration`, based on
merged web main `4e6d6e3`. Localhost and Azure require separate acceptance. No live
orders, payments, emails or messages may be created for an audit without designated
test data and authorization. Public handoffs use `<BUYER_ORIGIN>` and synthetic
fixtures; deployed origin/configuration details are exchanged privately.

Push, provider configuration, release identities, Google SDK/device acceptance and
future efficiency migrations are not implied by a local capability response.
