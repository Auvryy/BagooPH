# H04 rider handover API boundary

The native adapter uses the verified H01 reporting, H02 pickup recovery and H03
hub recovery services. It creates no alternative custody or approval rules.
Its proposed wire shapes become an accepted executable contract only alongside
implemented routes and verified response samples.

## Rider actions and authority

- An approved active courier may read involved reports and their retained review
  history, download authorized private evidence, report a missed scan and
  acknowledge their own independently authorized actual possession.
- Company/platform review and authorization, seller fulfillment and actual hub
  receipt remain in their existing role-owned web workflows. A rider token never
  receives those powers.
- Inactive/suspended holders retain the existing restricted web sign-in. This
  batch does not restore ordinary native tokens or operational access.
- Reads use `rider:operations:read`. Writes require the separate
  `rider:operations:handover` ability on a newly issued eligible rider session.
  Recheck the account, credential fingerprint, expiry, revocation and resource
  authority when each command commits.

## Proposed native surface

| Method and path under `/api/v1` | Purpose |
|---|---|
| GET `rider/handover-issues` | Bounded involved reports, including retained history. |
| POST `rider/handover-issues` | Multipart missed-scan report with genuine private evidence. |
| GET `rider/handover-issues/{issue}` | Current review, actual custody summaries and permitted recovery. |
| GET `rider/handover-issues/{issue}/evidence` | Authorized, hash-verified document bytes. |
| POST `rider/handover-recoveries/{grant}/acknowledge` | Known holder's matching-waybill acknowledgement. |

Identifiers remain positive ASCII decimal strings without leading zeros through
the signed 64-bit maximum. Timestamps use UTC ISO 8601 with `Z`. Handover issue
versions are bounded whole numbers from 1 through 1,000,000, accompanied by an
opaque 64-character source token; they are not task revision hashes. Lists use
the existing numeric pagination envelope, default 20 and maximum 50 entries.

Normalize permitted visible barcode spaces/case and meaningful plain text before
forming retry fingerprints. Reject unexpected fields, hidden characters, forged
identifiers, invalid versions and unsafe or overlong text. Reports use the
existing non-empty 5 MB JPG/JPEG/PNG/WEBP/PDF content-and-extension policy.

## Retry and privacy boundary

Every write uses the existing actor-scoped `Idempotency-Key` command journal.
Forward that key as the domain request token. A committed result and its domain
effects commit together. Same-key exact retries return the original small
result; different payloads conflict. A lost response is reconciled through the
owned `rider/commands/{key}` route before retrying the same intent. Never create
a new intent merely because the response was lost.

Discard an upload whose outer command rolls back, including when credentials
are revoked after the domain report succeeds. Preserve evidence for committed
reports and exact replay. Native JSON uses explicit allowlists, never raw model
serialization, storage paths, source snapshots, private identity files, buyer
contact/address data or unrelated roles' records. Evidence stays behind bearer
authorization with private no-store and nosniff headers.

## Verification and handoff

Verify current bearer and resource authority, scoped list/history/evidence,
upload/text/version boundaries, stale sources, altered evidence, expired or
withdrawn grants, exact and changed retries, lost-response reconciliation and
transaction/upload rollback. Exercise seller observation, independent review,
native holder acknowledgement, actual handler receipt and canonical Mother Hub
continuation without changing stock, cash or buyer completion. Keep ordinary
held movement blocked through its existing services.

Document implemented shapes and actual examples, deployment prerequisites and
a copy-paste Flutter integration handoff. The Flutter repository belongs to its
own task/session. SQLite checks do not prove PostgreSQL simultaneous requests,
and backend acceptance does not prove rendered device or Azure readiness.
