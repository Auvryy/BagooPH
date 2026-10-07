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
| GET `/rider/registration/options` | None | Vehicle types, Manila adult birthday limit, document limits |
| POST `/rider/registration/email/send` | `email` | Delivered-code result, cooldown, expiry; never the code |
| POST `/rider/registration/email/verify` | `email`, six ASCII digits in `code` | Single-use registration verification `token` |
| POST `/rider/applications` | Multipart application and documents | Created pending courier, verified email and own-account session |

The application requires `name`, `birthday`, `email`, Philippine `phone`,
`address`, `city`, `vehicle_type`, `plate_number`, password/confirmation,
`otp_token`, `device_name` and private `id_document`, `driver_license`,
`or_cr_document` uploads. The shared validator governs optional province,
municipality, barangay, postal and license identifiers. The Flutter wizard
requires the license identifier as the existing courier web form does.
Images/PDF use the existing 5 MB-per-file policy, validated from content.
The server assigns the immutable courier role and pending approval statuses.

Credentials are the existing account credentials, with case-insensitive email
lookup. Invalid credentials produce 422; invalid input has Laravel `errors`.
Wrong-role, closed, suspended, inactive, unknown or underage-ineligible accounts
receive 403. Pending/rejected applications may see only their own holding data.
An approved account flag is account eligibility, not placement, duty or authority
to perform a parcel action.

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
