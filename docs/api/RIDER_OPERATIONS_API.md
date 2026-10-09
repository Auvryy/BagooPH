# Native Rider operations API v1

Use the existing first-party HTTPS origin and `/api/v1`. Bearer authentication is
required; browser cookies, CSRF and Inertia are not native credentials. Preserve
[account access](../RIDER_ACCOUNT_API.md) and [Settings v1](RIDER_SETTINGS_API.md).
This contract is backend-owned. The executable [OpenAPI](rider-operations.openapi.json)
contains implemented routes; future scope is in [the delivery plan](RIDER_NATIVE_PLAN.md).
Local readiness and actual deployment evidence live in the
[repository roadmap](../CORE_FLOW_ROADMAP.md#native-rider-operations-october-9-2026).
The [sanitized examples](examples/rider-operations/README.md) show exercised success
shapes and illustrative empty/denied/stale/error responses.

New login/account responses add `operations_api_version: 1` only when existing
Settings schema, command records, checkpoints and COD accounts are available.
New tokens receive narrow `rider:operations:read`, `rider:operations:work`,
`rider:operations:messages`, `rider:operations:notifications` and
`rider:operations:cash` abilities. Older tokens must sign in again; account, holding, logout and Settings
shapes remain compatible. Holding accounts cannot use operational routes.
Every command revalidates the fresh account and bearer inside its transaction.

| Method / relative route | Request | Response under `data` |
|---|---|---|
| GET `rider/home` | None | Own duty, placement, eligibility/denial, capacity, counts, feature flags and limits |
| GET `rider/pickup-jobs` | Optional `page`, `per_page` | Available pickup previews and pagination |
| GET `rider/tasks` | Required `phase=pickup` or `final_mile`; optional page/per_page | Own active task resources and pagination |
| GET `rider/tasks/{task}` | Phase reference such as `pickup-12` or `final_mile-12` | Current owned task; no completed-task access through this route |
| PATCH `rider/duty` | JSON `on_duty` boolean; Idempotency-Key | Committed command result with original desired duty |
| POST `rider/pickup-jobs/{job}/claim` | JSON `expected_version`; Idempotency-Key | Original committed assignment result |
| GET `rider/commands/{key}` | Actor-owned UUID | Committed original result, timestamps and retry-expired flag |
| POST `rider/tasks/{task}/pickup` | JSON scan body; work ability | Committed seller handoff result |
| POST `rider/tasks/{task}/depart` | JSON scan body; work ability | Committed destination-hub departure result |
| POST `rider/tasks/{task}/deliver` | Multipart recipient/COD/proof body; work ability | Committed delivery/checkpoint result; buyer completion is separate |
| POST `rider/tasks/{task}/fail` | Multipart failure/proof body; work ability | Committed failure result; recorded attempt and return instructions are in refreshed task/history |
| GET `rider/trips` | Page/per_page, q, phase, payment, from_date, to_date | Retained assignment history, attribution/date basis and pagination |
| GET `rider/trips/{trip}` | `trip-<assignment checkpoint ID>` | Owned original assignment, checkpoints and attempts |
| GET `rider/trips/{trip}/checkpoints/{checkpoint}/proof` | Original owned trip and checkpoint | Private hash-verified JPEG/PNG/WebP bytes |
| GET `rider/trips/{trip}/attempts/{attempt}/proof` | Original owned trip and attempt | Private hash-verified JPEG/PNG/WebP bytes |
| GET `rider/conversations` | Page/per_page | Current phase/assignment/participant-bound thread summaries |
| GET `rider/conversations/{thread}/messages` | Page/per_page | Selected thread and newest-first messages |
| POST `rider/conversations/{thread}/messages` | JSON text; messages ability | Original committed message |
| POST `rider/conversations/{thread}/read` | JSON through_message_id; messages ability | Committed displayed boundary |
| GET `rider/notifications` | Page/per_page | Owned durable notices, unread count and authorized destinations |
| POST `rider/notifications/{notice}/read` | Empty JSON object; notifications ability | Committed owned read IDs |
| POST `rider/notifications/read-through` | JSON displayed_ids; notifications ability | Exact displayed owned read IDs |
| GET `rider/cash` | Page/per_page, optional q | Own retained cash accounts; all-owned-journal held total and unavailable earnings |
| GET `rider/cash/{account}` | Owned cash account ID | Original journal facts, permitted recipients and history |
| POST `rider/cash/{account}/offer` | JSON cash-offer body; cash ability | Original offer/event/version; awaiting recipient receipt |

Identifiers are strings containing positive ASCII decimal digits, no leading zero,
through signed 64-bit maximum `9223372036854775807`. Task references carry the
phase separately from persisted operational and commercial status. UUID keys are
lowercase. Malformed/overflow resource IDs return 404 before lookup. Revisions
are opaque 64-character lowercase hex strings; store them unchanged.

Task JSON separates `phase`, `commercial_status`, `operational_stage`, `custody`,
`stop`, `payment` and `actions`. An available preview exposes seller collection
data and no buyer destination/contact/payment. Owned pickup detail remains
seller/origin-hub scoped; final-mile uses the frozen order destination and its
permitted instructions. Unknown data is null, unknown custody is explicit, and
no KYC or proof storage path appears. IDs remain strings in nested payloads.
Money uses exact decimal integer-cent strings in PHP, never floats; payment
status is not remittance or earnings. Timestamps are UTC ISO 8601 with `Z`.

Lists default to 20, allow at most 50, and use stable ordering. Pages are bounded
from 1 through 10,000. Task queues use their shared assignment/time and ID order;
Trips/conversations use assignment time and ID descending; messages use ID
descending; notices use creation time and UUID descending; cash uses account ID
descending. Insertions can move later offset pages, so refresh the first page and
deduplicate by resource ID rather than treating page numbers as a snapshot. Pagination
returns numbers rather than external URLs. Missing placement is an honest
Home eligibility state, not a guessed hub. Off duty blocks new claims while
preserving owned tasks. Capacity uses the shared backend limit. Capability/action
flags are display hints; commands recheck their prerequisites under locks.

Every write requires a fresh lowercase UUID `Idempotency-Key`. Reuse exactly
that key and payload for a retry. Keys are actor-scoped and payload/action/resource
fingerprinted. Assignment/evidence and command result commit in the same database
transaction; the unique actor/key constraint serializes identical intents.
On PostgreSQL the command's actor foreign key is checked at commit, after the
shared domain locks, so independent same-actor commands do not acquire an early
account key-share lock in the opposite order. Actor existence remains enforced.
Same-intent replay returns the original small result with `replayed: true`.
Changed intent returns 409 `IDEMPOTENCY_CONFLICT`. Keys stay retained, with a
seven-day replay window; an expired write returns `COMMAND_EXPIRED`. Owned GET
reconciliation still exposes the retained result and `retry_expired: true`.

After a timeout or restart, query the owned command first and refresh the linked
resource. `COMMAND_UNKNOWN` means no committed result is visible, including while
another transaction is still uncommitted. It does not prove failure. Retry only
the same retained intent; never silently create a new cash/custody key. Command
results contain original references, displayed read IDs or the rider's own outgoing
message, never recipient contacts or proof paths;
opening a task always reauthorizes current assignment. No pending row is
committed as a successful result.

Success has `data` and a safe `request_id`; failures have `code`, `message`,
`errors` and `request_id`. `X-Request-ID` accepts only UUIDs and is correlation,
not idempotency. Responses include private no-store, no-cache and nosniff headers.
401 means session loss; 403 denied account/ability; 404 hidden/unknown resource;
409 stale resource, intent or domain conflict; 422 field/unknown-field errors;
429 a numeric Retry-After/cooldown; 503 unavailable schema/service. Debug exception,
SQL, token, password and document content are never part of operational errors.
After a competing outcome, the losing request can receive 404 if the terminal
task is already absent, or 409 if it read the earlier version. Refresh history
and reconcile the retained intent instead of treating either response as permission
to submit a different outcome.

The existing IP budget is 30 requests/minute across native v1; writes additionally
allow 10/minute. Foreground refresh starts at 30 seconds, pauses in the background,
and respects cooldowns. Avoid separate polling for every visual component.

Flutter integration: sign in again, check the operational version and Home flags,
decode IDs/money exactly, render unavailable/empty/denied/failed distinctly, refresh
after resume/action, retain one pending intent and reconcile uncertain results.
Claim confirms assignment; collection and authenticated origin-hub intake remain
separate. Native pre-custody release, restricted recovery and earnings are disabled.
This backend handoff does not claim Android, camera, email or mobile integration.

Deployment requires the additive `2026_10_09_010000_create_rider_commands_table`
migration alongside the existing source migrations. Publish/review first, then
follow the established deployment/verify script, refresh caches/workers and check
the actual HTTPS revision and bearer routes. Retained command records prevent
destructive schema rollback. No backend release is established by a local commit.


Parcel bodies and proof

Pickup/depart bodies contain `expected_version`, the actual submitted `barcode`,
and optional `notes` (plain text, at most 500 characters). The shared scanner trims
ASCII spaces and uppercases ASCII waybills before exact matching; unsafe controls,
Unicode lookalikes and another parcel's code reject. Pickup is available only for
the owned pickup phase. Departure/delivery/failure use the owned final-mile phase.
A stored tracking number is not substituted for submitted scan evidence.

Delivery adds `proof_image_file`, `recipient_name` (2–255 characters),
`recipient_relationship` (`buyer`, `household`, `authorized_recipient`),
`cash_received`, `change_given` and `cash_confirmed`. Use multipart/form-data and
let the HTTP library create its boundary. Peso inputs are strings, at most nine
whole digits and two decimal places; the shared COD service validates the exact
received-minus-change amount. Multipart confirmation may use `1`; JSON uses true.
New delivery is currently COD-only; other payment methods have a false deliver
hint and `UNSUPPORTED_PAYMENT_OUTCOME`. Do not fabricate a non-COD success.

Failure adds `reason`, required `notes`, `location_name` (up to 255 characters),
and `proof_image_file`. Allowed reasons are `customer_unreachable`,
`customer_unavailable`, `address_clarification`, `unsafe_conditions`,
`cod_unavailable`, `customer_refused`; the refreshed task supplies their labels,
recorded attempt count, limit of three and return hub. A failure records one real
attempt and keeps return custody with the rider until the destination hub receives
it. The rider cannot approve retry, change the attempt count or record hub intake.

Proof must contain genuine JPEG/PNG/WebP bytes and be at most 5,242,880 bytes.
New website and native writes use server-named private files through one shared
service. Original bytes fingerprint retries; a compressed/re-encoded replacement
is a different intent. Save those original bytes while an outcome is uncertain.
Rejected or rolled-back outcomes remove their unused new file. Ordinary model and
native JSON omit storage paths. Retained proof routes authorize the original trip,
child, actor and accepted file hash on every read; changed bytes return
`EVIDENCE_CHANGED`, missing or foreign proof returns 404. Download relative proof
URLs from the same origin with the bearer; do not follow cross-origin redirects.
Proof responses carry the same privacy/correlation headers as JSON.

Older public POD files are not advertised as native proof. Moving new writes to
private storage does not revoke an already published historical URL. Before a
release, audit older `/storage/delivery-proofs/` files and references, archive them
privately under an explicitly reviewed retention procedure and remove public
copies only after backup and reference/hash verification. No historical cleanup
or deletion is implied by this branch. Attempt proof already uses private storage.

Trips and disclosure

Trip identity is `trip-<original assignment checkpoint ID>`, distinct from the
current phase-plus-parcel task reference. Original actor attribution uses retained
assignment/checkpoint state and survives hub-approved reassignment. History
includes only that assignment interval and that rider's relevant events/proof;
future riders' outcomes and private evidence do not become the original rider's
history. Ambiguous legacy records without retained assignment evidence are absent.

`q` is a plain-text tracking/order search (1–100 characters), `phase` is pickup or
final_mile, `payment` is all/cod/prepaid. `prepaid` means recorded non-COD payment,
not confirmed earnings or a new prepaid delivery writer. `from_date`/`to_date` are
optional inclusive Asia/Manila assignment dates, translated to UTC with an exclusive
next-day upper boundary. Either may be supplied alone; reversed pairs reject.
The response names `date_basis: assignment_recorded_at`.

History separates original `outcome` from current commercial/operational state.
Recipient and collection evidence come from the original owned collection, while
handoff address is the original recorded checkpoint location. Missing collection,
recipient, address or time facts remain null. `collected_cents` is original recorded
COD, never remittance or earnings. Off-duty users retain authorized responsibilities
and history; ordinary suspended/restricted access does not gain recovery authority.

Messages and notice reads

Threads have `<phase>-<delivery ID>-<assignment checkpoint ID>-<participant ID>`
identity. The server selects seller for pickup and buyer for final-mile from the
current authorized assignment. IDs in an obsolete draft are retained: never replace
its participant on send. A removed assignment is hidden with 404; a changed selected
phase/participant returns `STALE_CONVERSATION` or a domain conflict. Sending after
the permitted active phase rejects even when read history remains available.
Messages are the existing order/actor-pair conversation within the selected phase;
a retry assignment to the same pair retains that conversation rather than inventing
a new independent chat store. The shared two-second identical-text guard remains.

Send only `text`, plain normalized text of 1–1,000 characters. The command result
contains the committed message ID, text, sender, read state and UTC recording time.
Read only with a string `through_message_id` belonging to that displayed selected
thread; it covers incoming IDs through that boundary, not a different thread or a
newer message. Reads and sends have their own retained command intents.

Notifications use committed durable backend events and the existing delivery
scheduler, not frontend-generated rows. A target is null when no native resource
can be authorized; opening an available target rechecks authority. Single-read
accepts an empty JSON object. Read-through requires 1–50 distinct lowercase UUIDs
in a `displayed_ids` JSON list. This intentionally avoids a timestamp/UUID cutoff
that could acknowledge an unseen later notice created in the same clock tick.
All supplied IDs must be owned; a foreign ID rejects the whole transaction.

Cash and remittance

Cash reads use retained responsibilities and the latest append-only journal source.
`own_held_cents` is this rider's outstanding balance; `own_remitted_cents` sums the
applied amounts actually received from that rider. An offer changes neither fact
until the recipient's separate authenticated receipt. Collected, pending, excess,
difference and reconciled facts remain distinct. The list summary covers all owned
journals, even when q/page narrows the displayed rows; it names that basis explicitly.
History exposes source/event references, exact cent strings and discrepancy
resolution without raw private collection evidence. Detail supplies permitted hub
recipients. Unsupported earnings are `{available:false, confirmed_cents:null}`.

An offer accepts `expected_version` (retained journal sequence as an ID-range
string), `recipient_id`, `amount` (exact peso string) and `evidence_reference`
(plain reference, 3–120 characters). The bearer owns the holder responsibility;
the shared finance service validates current recipient, network, amount and source
version under locks. Clients cannot send holder, approval, ledger state, commission,
timestamps, request_token or private file paths. Replayed offers retain the original
event and `awaiting_recipient_receipt` result. There is no rider receipt,
reconciliation, banking-transfer, earnings, payout or withdrawal route.

Flutter integration by batch

| Batch | Consumer acceptance |
|---|---|
| 1 | Renew token; check version and Home capabilities/eligibility; replace unavailable Home queues; consume server capacity and action hints; preserve preview privacy; persist/reconcile one claim intent; refresh owned detail after claim/duty/resume. |
| 2 | Map each distinct command; submit the scanned matching waybill; retain original private proof bytes and exact cash strings; show success only after committed result/reconciliation; refresh return instructions; leave hub/retry/buyer actions to their owning portals. |
| 3 | Decode trip assignment IDs, exact cents and nullable facts; use Manila assignment-day filters and stable pagination; preserve reassigned original history; download owned relative proof with bearer and no external redirects. |
| 4 | Keep selected assignment/phase/participant in drafts; persist send/read intent; acknowledge only rendered selected message boundary and exact displayed notice IDs; render unsupported/stale targets honestly; pause polling in background and share the 30/minute budget. |
| 5 | Render held/remitted/pending/difference/reconciled facts separately; keep earnings unavailable; use the supplied eligible recipient and version for offer; reconcile an uncertain offer before retry; require a separately recorded hub receipt. |
| Conditional | Stop Mode and Doorstep Guide consume authorized task stops/frozen destination/actual notes. Personal parcel-location tags are planned device-only, account plus assignment scoped, with logout/reassignment/terminal cleanup owned by Flutter. Server tags, reviewed-identity/recovery extensions and any new earnings writer need a separate chosen contract. |

Apply all additive source migrations, including the command table and the finance
source migrations this branch inherits. Preserve private local proof storage and
backups across deployment, refresh route/config caches and the existing notification
workers/scheduler, run `./bagoo.sh deploy` then `./bagoo.sh verify`, and independently
record the actual revision plus fresh HTTPS bearer reads and rejected access. Existing
account/Settings remain separate regression gates. User publication/rollout and
actual PostgreSQL claim/outcome races, mobile adapters, physical Android, camera,
mailbox and buyer/hub acceptance are distinct gates; local fixture tests cannot
establish them. See the roadmap for actual observed evidence and pending work.
The [disposable race verifier](../../scripts/verify-rider-races/README.md) reproduces
only its expressly authorized local PostgreSQL checks; it never uses the normal
development or deployed database.
