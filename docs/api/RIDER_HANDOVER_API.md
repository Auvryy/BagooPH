# Native rider handover reporting and recovery

H04 adapts the verified [handover services](../handover-plan/README.md) to the
existing `/api/v1` bearer API. Riders report missed scans and acknowledge their
own independently authorized possession. Seller confirmation, company/platform
review and authorization, and actual hub receipt keep their existing web
authority. The [executable contract](rider-operations.openapi.json) includes these
five methods. Current evidence and readiness belong in
[the roadmap](../CORE_FLOW_ROADMAP.md); Flutter and deployed device acceptance
remain separate.

[Exercised response examples](examples/rider-handover/README.md) show reporting,
review detail, pickup/manifest grants, acknowledgement and command reconciliation.

## Session and discovery

Send `Authorization: Bearer <TOKEN>` and `Accept: application/json`. Current
account eligibility, credentials, token expiry/revocation and resource authority
are checked server-side. Browser cookies cannot replace a bearer token.

New eligible sign-ins receive `rider:operations:handover` when the H01-H03 schema
is ready. Older read tokens may read authorized reports but cannot write without
this new ability. Sign in again to obtain it. Pending, unverified, inactive,
suspended, closed or unapproved accounts gain no write authority from a flag.

Account responses advertise `handover_api_version: 1` when the schema is ready.
Home exposes `handover_api_version` (`1` or `null`) and capabilities
`handover_issues` and `handover_reporting`. Version discovery and current
permission are separate. Per-grant `actions.acknowledge` is a display hint; the
command rechecks the holder and authorization.

Inactive/suspended holders keep the restricted web recovery sign-in at
`<WEB_BASE_URL>/custody-recovery/sign-in`. H04 does not restore their native
sessions. Home's `native_restricted_recovery` remains false.

## Methods and types

| Method under `/api/v1` | Input | Result |
|---|---|---|
| GET `rider/handover-issues` | Optional `status`, `page`, `per_page` | Involved summaries and numeric pagination. |
| POST `rider/handover-issues` | Multipart report, handover ability and idempotency key | Original committed report result. |
| GET `rider/handover-issues/{issue}` | Involved issue ID | Observations, custody summaries, review history and permitted recovery. |
| GET `rider/handover-issues/{issue}/evidence` | Involved issue ID | Authorized hash-verified document bytes. |
| POST `rider/handover-recoveries/{grant}/acknowledge` | JSON acknowledgement, handover ability and idempotency key | Original committed possession event. |

IDs are positive ASCII decimal strings without leading zeros through the signed
64-bit maximum. Preserve them as strings. Times use UTC ISO 8601 with `Z`.
Handover versions are whole counters from 1 to 1,000,000, paired with a
64-character lowercase hexadecimal `source_token`. Input also accepts canonical
decimal strings for the counter; arrays, booleans, decimal/exponent encodings,
leading zeros and overflow are rejected. These counters are separate from task
revision hashes. Use the integer supplied by current detail in JSON requests.

Lists default to `status=open`, page 1 and 20 entries. Status accepts `open`,
`recovered`, `dismissed` or `all`. Per-page is 1-50; page is 1-10,000. Issue ID
descending provides stable ordering. Pagination returns `page`, `per_page`,
`total` and `last_page`, with no external URLs. Refresh and deduplicate by ID when
insertions move offset pages. Unknown or unrelated resources return 404.

## Report body and field feedback

Use multipart form data; let the client generate its boundary. An upload must
not be labelled JSON.

| Field | Accepted value |
|---|---|
| `barcode` | Actual involved waybill, at most 255 visible ASCII characters. Permitted outer spaces and case normalize to its uppercase code. |
| `kind` | `missed_scan`. The owning seller reports/confirms missed readiness in the existing web flow. |
| `reported_holder` | Meaningful plain text, 2-100 characters; observation does not prove assignment. |
| `reported_location` | Meaningful plain text, 5-255 characters. |
| `notes` | Meaningful plain text, 10-1,000 characters. |
| `evidence` | One non-empty actual JPG/JPEG/PNG/WEBP/PDF, at most 5,242,880 bytes (5 MB). Content must match its extension; images retain the shared 10,000-pixel-side and 20-million-pixel limits. |

Permitted text normalizes to NFKC and trims outer spaces before fingerprinting.
Hidden controls and unsafe markup are rejected. Show formats and the 5 MB limit
beside the upload control. Display returned field errors, preserve safe entered
values and distinguish rejection from session loss. Unexpected fields, actor or
receiver choices, server timestamps and body `request_token` are rejected. The
header supplies the domain request token.

Reporting begins `pending_review` and changes no custody, stock, cash or hold.
Independent confirmation makes the issue `needs_recovery` with a shared hold.
Only actual authorized hub receipt makes it `recovered`; supported dismissal
makes it `dismissed`.

## Detail, evidence and acknowledgement

Detail separates `reported` from `recorded.original` and `recorded.current`.
Recorded summaries contain commercial status, operational stage, custody kind
and label. JSON omits raw snapshots, unrelated buyer contacts/addresses,
identity documents and storage paths. History retains event ID, type, actor
role, plain notes and actual recording time.

`evidence.download_path` is an absolute path on the configured backend origin.
Resolve it against that origin without duplicating `/api/v1`. Fetch it with the
bearer header, never a public storage URL. Successful bytes use the verified
MIME type, attachment name, private no-store, nosniff and request-ID headers.
Decode an error before attempting to display image/PDF bytes. Missing or altered
evidence cannot become a successful download.

`recovery` is null before authorization. Otherwise it contains the grant
ID/reference/mode, `holder_is_me`, named receiving hub, expiry,
acknowledged/closed state, optional blocker and `actions.acknowledge`. Only the
named holder receives its source token. Manifest and linked recovery references
appear when applicable; the original case route stays in its web workspace.
Riders have no review, authorization, withdrawal or hub-receipt API action.

Use current detail values for acknowledgement:

```json
{
  "expected_version": 3,
  "source_token": "<SOURCE_TOKEN_FROM_CURRENT_DETAIL>",
  "barcode": "<ACTUAL_WAYBILL>",
  "notes": "I physically hold this parcel and will follow its authorized receiving route.",
  "possession_verified": true
}
```

Confirmation must be a true JSON boolean. Acknowledgement records actual current
possession under the existing mode; it keeps the hold and cannot prove buyer
delivery. An eligible handler must actually receive the parcel at the named hub.
The existing expiry, changed source/evidence, account/network, original manifest
and Mother Hub checks apply to both web and native commands.

## Commands and failures

Each write requires one lowercase UUID `Idempotency-Key`. Retain the exact
normalized intent, chosen file bytes and extension until the outcome is known.
Report action/resource is `handover.report` / `handover-report`.
Acknowledgement uses `handover.acknowledge` / `handover-recovery-<GRANT_ID>`.

Success uses the existing command envelope. `data` contains key, action,
resource, `state=committed`, original small `result`, recording/expiry times,
`retry_expired` and `replayed`; the envelope also has `request_id`. Report result
contains issue ID, reference, state, version and held flag. Acknowledgement adds
grant ID, event ID and `event_type=holder_acknowledged`. Refresh detail after
success: a journal result preserves the original action, not today's live state.

After timeout/restart, query owned `GET rider/commands/{key}` and refresh detail.
`COMMAND_UNKNOWN` means no committed result is visible, including while another
request is unfinished; it does not prove failure. Retry the same key and payload.
Never create another custody intent merely because a response was lost. Exact
replay may survive actual hub receipt or grant expiry within the seven-day retry
window. Changed payloads return `IDEMPOTENCY_CONFLICT`; after seven days retained
GET shows `retry_expired=true`, while writes return `COMMAND_EXPIRED`.

Domain effects and journal result commit together. Late token/account loss or
write failure rolls back the report/event/checkpoint/journal and removes unused
upload bytes; committed evidence stays retained. Acknowledgement keeps existing
responsibility until actual receipt. Ordinary held pickup, departure and outcomes
continue returning `HANDOVER_HOLD`.

Errors contain `code`, `message`, `errors`, `request_id`, no-store and nosniff.
Handle 401 session loss, 403 denied account/ability, 404 hidden/unavailable
resource, 409 source/intent conflict, 422 field errors, 429 cooldown/Retry-After
and 503 unavailable schema. Debug, SQL, credentials and documents are never
returned. Respect the existing 30/minute native IP and 10/minute write budgets;
pause background polling and honor cooldowns.

## Deployment and Flutter handoff

Publish/merge this backend branch through the user-owned Git workflow. Deploy
the merged backend and apply ordinary H01-H03 migrations before expecting
version 1. H04 adds no migration and needs no database reset or reseed. Keep PHP
and proxy limits above the 5 MB file plus form overhead; Laravel retains the
5,242,880-byte evidence limit.

After deployment check login, Home capabilities, involved reporting/history,
private evidence, native holder acknowledgement and actual handler receipt.
The route must continue through a Mother Hub and real delivery before buyer
completion; cash reconciliation stays separate. Local SQLite checks do not
certify simultaneous PostgreSQL requests or devices.

Copy this to the Flutter rider AI after the backend branch is available:

```text
H04 adds Bagoo's native handover reporting and known-holder acknowledgement.
Work in the rider Flutter repository and preserve its existing style and main
navigation. Read the backend docs/api/RIDER_HANDOVER_API.md and the updated
docs/api/rider-operations.openapi.json before integrating.

After backend deployment, sign in again for rider:operations:handover. Check
handover_api_version and Home's handover_issues/handover_reporting capabilities.
Add a Handover issues entry from Home and relevant task detail. Use involvement
scope and server action flags; keep observations separate from recorded custody.

Under /api/v1, implement GET rider/handover-issues and GET
rider/handover-issues/{issue}; multipart POST rider/handover-issues with barcode,
kind=missed_scan, reported_holder, reported_location, notes and evidence; bearer
GET rider/handover-issues/{issue}/evidence; and JSON POST
rider/handover-recoveries/{grant}/acknowledge using expected_version, source_token,
barcode, notes and possession_verified=true from current authorized detail.

Show JPG/JPEG/PNG/WEBP/PDF and 5 MB beside the upload control and display field
errors. Preserve IDs as strings and source tokens unchanged. Acknowledgement
keeps the hold until the named hub handler actually receives the parcel. Seller
confirmation, review, authorization and hub receipt stay in the web portals.

Retain one lowercase UUID Idempotency-Key and exact intent/file for each write.
After a lost response, reconcile GET rider/commands/{key}, refresh detail and
retry only the same intent. Refresh current state after success; journal results
are original history. Respect cooldowns and pause background polling.
Inactive/suspended holders keep restricted web recovery sign-in; do not restore
normal native access or treat duty-off as suspension.

Verify files, validation, denied access, stale/expired grants, lost responses,
resume refresh and actual hub receipt on a device. The backend checks do not
certify Flutter/device or Azure readiness.
```
