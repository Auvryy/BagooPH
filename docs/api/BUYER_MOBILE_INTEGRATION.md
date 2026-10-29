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

Requests are limited to 60 per minute per guest IP or authenticated user in a dedicated throttle bucket.
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
The existing approved/pending Home destination is `/buyer`, alongside the public
`/` Home alias. A session adapter that accepts only `/` must add the exact `/buyer`
destination while retaining same-origin and authenticated buyer/owner checks.

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

The [native Google contract](BUYER_NATIVE_GOOGLE.md) defines the implemented
verified ID-token exchange, explicit account-link password proof, session-bound
registration, field limits, expiry, errors and uncertainty recovery. It reuses
existing buyer validation, age, policy and approval services. Existing browser
OAuth retains its own pending purpose. No migration is introduced.

The [association contract](BUYER_APP_ASSOCIATIONS.md) defines the implemented
stateless Android/iOS JSON documents, strict HTTPS buyer-host checks, approved
identity configuration, path rules and device acceptance still required.
Defaults advertise no provider or application identity. No debug certificate,
Apple team or production host is silently selected.

Google SDK integration, approved provider settings, platform signing/domain
configuration and physical-device acceptance remain mobile and owner work.
Credentials never belong in app assets or fixtures.

## Existing surface inventory and compatibility

These existing routes retain their current behavior; this branch does not
introduce alternative buyer writers or change order/payment authority.

| Area | Existing surface / rule | Relevant isolated regression |
| --- | --- | --- |
| Session and account | `/auth/session`, ordinary login/logout, role-scoped identity, pending/restricted/closed states | Authentication, guest-page, session-transition, identity, pending-workspace and access-alignment tests |
| Registration and evidence | Existing policy, 18+ and private document contracts; optional canonical address | Google policy, adult registration, private verification document tests |
| Discovery | Home/search/product/shop, stable filter/page selection, saved products | Buyer discovery/saved-product tests and the anonymous localhost probe |
| Bag and checkout | Owned selection; current stock, variant, voucher and server quote; serving company/hub and measurements | Bag selection, checkout replay and new-era checkout tests |
| Orders and delivery | Owned history/action flags; historical receipt rules and version-two evidenced automatic completion | Order workspace and new-era local delivery tests |
| Payment and return | Current COD/virtual wallet, original payment evidence, balanced settlement and return eligibility | New-era local COD, wallet and return cases; no live financial mutation |
| Chat and notices | Current image ownership/validation, GET read side effect, displayed-ID and bulk snapshot acknowledgement | Marketplace chat image, buyer preview and bulk-read tests |

Existing checkout/session/read recovery is retained: checkout uses its issued
`checkout_token`; client-supplied money, owner, status and route snapshots are not
authoritative. Wallet/chat commands retain their current request keys. A failed
read may be retried within existing limits; an uncertain writer requires its
documented read-only reconciliation and explicit user decision. Bag/profile
actions do not gain blanket replay guarantees.

Private evidence remains authenticated and same-origin; the native adapter
requires image/PDF MIME and bounds reads to 20 MiB. That is a download limit, not an
upload entitlement. Current chat images use JPEG/PNG/WebP, maximum 5 MiB, and text
maximum 1,000 characters; profile avatars use existing image types, maximum 3 MiB.
Buyer returns require 1–5 JPEG/PNG/WebP photos, each at most 5 MiB; item quantities
remain 1–99, at most 100 request lines. These are existing validation rules, not
new upload endpoints. Existing evidence URLs and private metadata must never be
added to a public catalogue cache.

The fixture pack supplies executed guest/account-state/session, capability,
Google and association responses. It is a focused connection pack, not an export
of every catalogue/order/payment page. The existing named regression suites are
the evidence for those unchanged contracts. Live production data and provider
tokens are never used to construct fixtures.

## Publication and environment evidence

Current implementation branch: `feat/buyer-mobile-web-integration`, based on
merged web main `4e6d6e3`. Localhost and Azure require separate acceptance. No live
orders, payments, emails or messages may be created for an audit without designated
test data and authorization. Public handoffs use `<BUYER_ORIGIN>` and synthetic
fixtures; deployed origin/configuration details are exchanged privately.

Push, provider configuration, release identities, Google SDK/device acceptance and
future efficiency migrations are not implied by a local capability response.

## Deployment order and rollback

1. Review/publish the web commits and deploy that same source. Install locked
   Composer dependencies in the existing application build; native verification
   needs `firebase/php-jwt`. No database migration, push worker or new scheduler
   is required by this integration.
2. Keep both new enable switches false initially. Retain existing session/CSRF,
   HTTPS, trusted-proxy and cache/lock settings. Refresh the application route and
   configuration caches with the existing deployment commands; do not seed or
   reset production to test these routes.
3. Verify guest `/auth/session` and disabled capability JSON separately on
   localhost and the actual Azure buyer origin. Confirm status, MIME, private
   cache policy and absence of login redirects before native use.
4. Have the owner supply approved non-secret Google audience/native client IDs
   and release link identities through private environment configuration. Match
   these to the mobile SDK, manifest and entitlement settings. Enable each
   feature only after its own configuration review.
5. Use designated disposable accounts for real Google/link/session acceptance;
   keep email fallback available. Verify Android/iOS cold/warm links and native
   post-login ownership checks separately from Linux preview.

Disable Google with `BUYER_NATIVE_GOOGLE_ENABLED=false`; disable associations
with `BUYER_MOBILE_LINKS_ENABLED=false`, then refresh cached configuration. No
new schema must be rolled back. Existing linked identities remain ordinary
account records and are not deleted. Platform association caches may persist;
native allowlists/server authorization continue to guard opened destinations.
An older mobile build can ignore discovery and continue its existing contracts.

## Acceptance evidence

The companion [acceptance report](BUYER_MOBILE_INTEGRATION_ACCEPTANCE.md) records
the actual source/tests, environment observations, prerequisites and limitations.
Each status there is scoped: source support is distinct from merge, deployment,
provider execution and installed-device verification.
