# Buyer mobile web acceptance: October 29, 2026

Web branch: `feat/buyer-mobile-web-integration`, based on merged main `4e6d6e3`.
This report distinguishes implemented server contracts from publication and
actual native/provider acceptance. It contains no production account records.

## Delivered server changes

| Commit | Outcome |
| --- | --- |
| `91795f5` | Guest-safe capability v1, explicit disabled defaults and session compatibility |
| `02a887d` | Verified native Google exchange/registration, explicit existing-account password proof, safe JSON errors and executed fixtures |
| `7eebac8` | Owner-configured stateless Android/iOS HTTPS associations and buyer path rules |

The combined [contract](BUYER_MOBILE_INTEGRATION.md),
[Google schema](BUYER_NATIVE_GOOGLE.md), [association setup](BUYER_APP_ASSOCIATIONS.md)
and [fixture pack](buyer-mobile-contract-handoff/README.md) supply exact request,
response, validation, expiry, cache, error, retry and enable/rollback rules.
No migration or new background worker is required. Locked Composer dependencies
must be installed during deployment.

## Executed checks

| Selection | Result | Timing / scope |
| --- | --- | --- |
| Initial capability + existing session/browser Google | 49 passed / 669 assertions | First committed capability slice |
| Broader mobile/auth/identity/age/private-document selection | 239 passed / 3,459 assertions | Before the final JWKS freshness refinement and two fixture additions |
| Buyer access/Bag/replay/orders/discovery/chat + notice preview/bulk-read | 210 passed / 4,941 assertions | Same runtime source, before verifier-only freshness refinement |
| New-era checkout/local COD/wallet/delivery/return selections | 15 passed / 648 assertions | Required route, payment and completion gates preserved |
| Final buyer-mobile + existing Google/browser policy/session selection | 129 passed / 1,223 assertions | After final source and fixture changes |
| PHP formatter, Composer strict validation and Git whitespace | Passed | Affected source/tests and locked package consistency |

These selections overlap and must not be added into a full-suite total. Tests
used isolated SQLite `:memory:`. Synthetic RSA keys existed only in process
memory; Google HTTP was faked with stray requests forbidden. No PostgreSQL reset,
production write, real payout, email, order or message was used. The unchanged
frontend was not rebuilt and no browser/device automation was performed.

The first combined long test process exhausted PHP's 128 MiB test-process memory
while generating existing verification-document fixtures. The completed broader
groups ran as separate processes with a local test-only 768 MiB limit inside a
1.5 GiB, 0.75 CPU Docker cap. No production memory setting was changed. Initial
fixture/helper mistakes were corrected against actual responses: provider fakes,
key-header construction, valid closed-account state, closed-response cache policy
and the actual buyer Home redirect. The completed results above replace those
failed attempts; the failed attempts are not passing evidence.

Reproduce the focused contract suite from the web repository with the existing
local image and locked dependencies:

```bash
docker run --rm --cpus=0.75 --memory=1024m --memory-swap=1024m \
  --pids-limit=128 --volume "$PWD:/var/www/html" --workdir /var/www/html \
  --env APP_ENV=testing --env DB_CONNECTION=sqlite --env DB_DATABASE=:memory: \
  --entrypoint php bagoo-app:local -d memory_limit=768M vendor/bin/phpunit \
  tests/Feature/BuyerMobile tests/Feature/Auth/GoogleOAuthTest.php \
  tests/Feature/Auth/GoogleRegistrationPolicyTest.php \
  tests/Feature/Auth/WebSessionTransitionTest.php
```

Real PostgreSQL concurrent race acceptance and the full unrelated PHP suite were
not executed. Existing database uniqueness, transactions and session locks are
covered structurally and by isolated conflict/replay checks, not represented as
a real multi-process PostgreSQL proof.

## Environment observations

| Environment / surface | Observed result | Meaning |
| --- | --- | --- |
| Localhost guest `/auth/session` | 200 JSON, authenticated=false, private/no-store | Existing public session recognition works |
| Localhost capabilities | 200 JSON v1, Google=false, push=false, private/no-store | Disabled contract available through the real local HTTP server |
| Localhost native registration GET | 503 JSON, private/no-store, no redirect | Expected disabled Google state with current configuration |
| Localhost association documents | 404 JSON, private/no-store, no redirect | Expected disabled identity publication |
| Azure candidate buyer origin: session/capabilities | HTTP403 on both read-only requests | Deployed source and contract availability cannot be verified from this session |

Private environment addresses are deliberately omitted. Local source was observed
through its existing server; no release identifier was returned by the public
probe. Azure's deployed commit is **unknown**, not inferred from local Git or a
403 response. The branch has not been published, merged or deployed by this web
session. No Google token exchange against a real provider/account is claimed.

## Cross-agent handoff and remaining work

Both existing agents authored introductions and acknowledged the capability and
Google schema through the owner-authorized ignored local channel. The mobile
agent reported strict parsing/direct-JSON discovery, 330 isolated mobile tests,
analysis and an anonymous localhost catalogue/session probe passing. Those are
the mobile agent's reported results; this web session did not run Flutter tests
or modify its source.

Additional executed web fixtures found a concrete mobile compatibility gap:
`/auth/session` returns `/buyer` for approved/pending Home destinations, while the
inspected mobile `currentSession()` allowlist accepted `/` and selected account/
order destinations but omitted `/buyer`. The web redirect is preserved and the
actual fixture is now tested. This was sent to the existing mobile agent for an
exact-alias repair retaining same-origin, buyer-role and numeric-owner checks.
At this report's cutoff, mobile repair acknowledgement/commit is still pending;
authenticated native restoration must not be claimed passed on that older adapter.

The owner/mobile prerequisites are approved Google audience and native client IDs,
SDK integration including the password-proof prompt and native registration,
production HTTPS/link host, approved Android release fingerprints, Apple Team ID/
bundle entitlement, and installed-device/provider tests. The current mobile
release-signing template and Linux preview do not establish these prerequisites.
After user publication/deployment, verify the actual Azure buyer origin with
designated disposable accounts and preserve its session/CSRF and existing domain
rules. A 403 probe is not authority to alter unrelated hosting resources.

Device push is explicitly deferred. Versioned chat/read separation, private
conditional reads, public revision metadata and image renditions remain separate
future work; current contracts continue to govern them.

Scoped **web API readiness: 2/10 → 8/10**. The improvement is
versioned discovery, verified server identity/registration and configurable buyer
associations with executed failure/recovery checks. This is not a rating of native
Google/device/Azure readiness or the overall commerce/delivery lifecycle.
