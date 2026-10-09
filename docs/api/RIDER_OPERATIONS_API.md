# Native Rider operations API v1

Use the existing first-party HTTPS origin and `/api/v1`. Bearer authentication is
required; browser cookies, CSRF and Inertia are not native credentials. Preserve
[account access](../RIDER_ACCOUNT_API.md) and [Settings v1](RIDER_SETTINGS_API.md).
This contract is backend-owned. The executable [OpenAPI](rider-operations.openapi.json)
contains implemented routes; future scope is in [the delivery plan](RIDER_NATIVE_PLAN.md).
Local readiness and actual deployment evidence live in the repository roadmap.

New login/account responses add `operations_api_version: 1` only when existing
Settings schema, command records, checkpoints and COD accounts are available.
New tokens receive narrow `rider:operations:read` and `rider:operations:work`
abilities. Older tokens must sign in again; account, holding, logout and Settings
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

Lists default to 20, allow at most 50, and use stable time/ID ordering. Pagination
returns numbers rather than external URLs. Missing placement is an honest
Home eligibility state, not a guessed hub. Off duty blocks new claims while
preserving owned tasks. Capacity uses the shared backend limit. Capability/action
flags are display hints; commands recheck their prerequisites under locks.

Every write requires a fresh lowercase UUID `Idempotency-Key`. Reuse exactly
that key and payload for a retry. Keys are actor-scoped and payload/action/resource
fingerprinted. Assignment/evidence and command result commit in the same database
transaction; the unique actor/key constraint serializes identical intents.
Same-intent replay returns the original small result with `replayed: true`.
Changed intent returns 409 `IDEMPOTENCY_CONFLICT`. Keys stay retained, with a
seven-day replay window; an expired write returns `COMMAND_EXPIRED`. Owned GET
reconciliation still exposes the retained result and `retry_expired: true`.

After a timeout or restart, query the owned command first and refresh the linked
resource. `COMMAND_UNKNOWN` means no committed result is visible, including while
another transaction is still uncommitted. It does not prove failure. Retry only
the same retained intent; never silently create a new cash/custody key. Command
results contain original operational references, not contacts or proof paths;
opening a task always reauthorizes current assignment. No pending row is
committed as a successful result.

Success has `data` and a safe `request_id`; failures have `code`, `message`,
`errors` and `request_id`. `X-Request-ID` accepts only UUIDs and is correlation,
not idempotency. Responses include private no-store, no-cache and nosniff headers.
401 means session loss; 403 denied account/ability; 404 hidden/unknown resource;
409 stale resource, intent or domain conflict; 422 field/unknown-field errors;
429 a numeric Retry-After/cooldown; 503 unavailable schema/service. Debug exception,
SQL, token, password and document content are never part of operational errors.

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
