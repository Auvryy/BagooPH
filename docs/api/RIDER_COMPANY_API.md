# Native rider company joining

RC03 exposes the shared company request, offer and acceptance services under
`/api/v1`. An approved, active rider with a verified sign-in email can begin
without an existing company or hub. Company administrators review requests and
issue offers in the web Network workspace. This API grants no company review,
fleet management, approval, transfer or departure authority.

The [OpenAPI contract](rider-operations.openapi.json) defines all ten company
methods. [Exercised examples](examples/rider-company/README.md) cover discovery,
empty/pending history, exact offers, terminal outcomes, acceptance, saved
assignment, reconciliation and errors. The [delivery plan](../rider-company-plan/03-native-contract-and-acceptance.md)
defines the gate; current implementation evidence belongs in the
[roadmap](../CORE_FLOW_ROADMAP.md).

## Discovery and permissions

Use `Authorization: Bearer <TOKEN>` and `Accept: application/json`. Cookies do
not replace bearer authentication. Account responses advertise
`company_api_version: 1` only when the required account, enrollment and command schema
exists. Home returns version `1` or `null` and `capabilities.company_joining`.
An unassigned approved account can read Home and discover company joining.

New currently eligible sign-ins receive `rider:company:read` and
`rider:company:write`. These require explicit abilities: an older token,
including a wildcard token, never acquires them automatically. Read-only company
tokens can read owned history and reconcile company commands, but cannot write.
Company-only tokens gain no parcel, settings, messaging or cash permissions.
Sign in again after deployment or approval if the new abilities are missing.

Version discovery describes installed support. Home's company capability means
the current token can read this workspace; it does not promise permission to
apply. Read Assignment's `actions.apply` and each application's current actions.
All actions recheck current server authority. Pending, rejected, suspended,
closed, unverified and expired/revoked sessions gain no new permissions.

## Methods

| Method under `/api/v1` | Ability | Result |
|---|---|---|
| GET `rider/companies` | Company read | Eligible companies with open recorded intake. |
| GET `rider/companies/{company}` | Company read | Current company and bounded service areas. |
| GET `rider/company-requests` | Company read | Own applications, newest first. |
| POST `rider/company-requests` | Company write | Original committed application receipt. |
| GET `rider/company-requests/{application}` | Company read | Own exact offer, actions and retained history. |
| POST `rider/company-requests/{application}/withdraw` | Company write | Withdraw own pending or unexpired offered request. |
| POST `rider/company-requests/{application}/accept` | Company write | Accept the exact current offer and save placement together. |
| POST `rider/company-requests/{application}/decline` | Company write | Decline own current unexpired offer. |
| GET `rider/company-assignment` | Company read | Saved membership, original provenance and current eligibility. |
| GET `rider/company-commands/{key}` | Company read | Own original enrollment result, including an expired retry window. |

Resource IDs in responses and paths are positive ASCII decimal strings without
leading zeros, through `9223372036854775807`. Preserve them as strings. Body IDs
and counters accept canonical decimal strings or whole JSON integers in that
range. Decimal/exponent encodings, booleans, arrays and overflow reject. Times
use UTC ISO 8601 with `Z`; domain history keeps six fractional digits and command
or observation times use three. Do not round times when deciding whether an
offer is current: the server clock decides.

Both lists accept `page` 1–10,000 and `per_page` 1–50, defaulting to 1 and 20.
Responses contain `items` and numeric `pagination` (`page`, `per_page`, `total`,
`last_page`) without navigation URLs. Refresh and deduplicate IDs if insertions
move offset pages. Company order is name then ID; application order is ID
descending. Out-of-range pages return an empty list with the requested page.

Company lists also accept optional `search` and `province`, each at most 100
characters of meaningful plain text. Search treats `%` and `_` literally and
matches company names without case sensitivity. Province matches a recorded
eligible service area. Each company returns at most 50 distinct province/city
summaries and `areas_truncated`; these summaries do not promise route serviceability.
Closed intake and ineligible companies disappear from the directory/detail.
An owned application's original company summary remains in its history.

Unexpected fields reject on reads and writes. Enrollment has no upload, salary,
employment-contract or private-document input. It never exposes original KYC
files, storage paths, birth dates, private review notes or another rider's data.

## Apply, review and decide

Show Assignment's `sharing_summary` before collecting explicit acknowledgement.
The receiving company sees only the retained name, registered work-contact
email/phone, locality, vehicle type and approval summary. Application body:

```json
{
  "company_id": "1",
  "intake_version": 1,
  "sharing_acknowledged": true,
  "note": "Available for scheduled work in Santa Cruz."
}
```

Use the actual company ID and intake version from current directory/detail.
`note` is optional; supplied meaningful text must be 5–500 characters. Text uses
the shared NFKC normalization and outer-space trimming; raw and normalized
length limits, hidden controls and markup are checked before saving. Omitted,
null or permitted blank optional text becomes null. One open request is allowed
across companies, and an already associated rider cannot request a transfer.
Applying and receiving an offer leave membership, duty and parcel custody unchanged.

The company owner proposes a hub, optional covered barangay, personal or
available fleet vehicle and readable working arrangements in the web portal.
Native detail returns exact `offer`, `terms_hash`, request `version`, issue/expiry
times, prior offers and decisions. Each history array keeps the latest 50 entries
in chronological order; `history_limits` signals truncation. Earlier immutable
records remain retained. List summaries omit these arrays.

Acceptance uses the displayed request version and exact offer:

```json
{
  "expected_version": 2,
  "offer_id": "1",
  "terms_hash": "<TERMS_HASH_FROM_CURRENT_OFFER>",
  "acknowledged": true
}
```

Both acknowledgement fields require a true JSON boolean; `1` and `"true"` do
not count. Withdraw and decline accept `expected_version` and optional `reason`
of 5–500 meaningful characters. Do not send body `request_token`, rider/company
overrides, managed placement, status or server timestamps.

The server rechecks exact terms, current approval/intake/identity sources,
eligible hub, covered barangay and available vehicle. A change-and-restore
cycle requires a fresh offer. Offers reserve no vehicle and expire seven calendar
days after issue. At the expiry boundary acceptance fails, even without the
scheduler. Reads show `effective_status=expired` without writing; the existing
expiry command or next application records the actual terminal reconciliation.

Requests retain `pending`, `offered`, `accepted`, `declined`, `withdrawn`,
`rejected`, `cancelled` or `expired` outcomes. Original offer replacement and
decision history stays visible. Terminal outcomes permit another request while
the rider remains unassigned. A new attempt uses a new intent and current inputs.

## Saved membership and readiness

Assignment returns saved company/hub/vehicle/barangay, `on_duty`, current
`eligibility.operational`, `blocked_reason`, sharing summary and available actions.
`provenance.kind` distinguishes `unassigned`, `review_required`,
`existing_assignment` and `accepted_offer`. Accepted provenance includes original
request/offer IDs and actual acceptance time. Existing seeded assignments keep
their real records with null acceptance evidence; no historical consent is invented.

Acceptance saves consent, first placement, immutable history, decision, notice
intents and command result atomically. It leaves duty off. The rider deliberately
uses the existing duty action when operationally eligible. Same-company moves
continue through existing company controls and custody guards; current assignment
may differ from the original accepted hub. Historical acceptance does not restore
a suspended company or rider. An eligible rider can still read saved membership
when the company becomes ineligible; a suspended rider loses native access.

Owned company notices can link to native `company_request` or
`company_assignment` targets when the token has explicit company read permission.
Old tokens retain their notices without gaining those destinations. Notification
read/acknowledgement keeps its existing operational abilities and cooldowns.
Clients must safely ignore unknown target kinds when consuming additive responses.

## Lost responses and private errors

Every write requires one lowercase UUID `Idempotency-Key`, retained with the
normalized intent until the outcome is known. Never generate a new key merely
because a response was lost. Actions/resources are:

| Action | Resource |
|---|---|
| `company.submit` | `company-<COMPANY_ID>` |
| `company.accepted`, `company.declined`, `company.withdrawn` | `company-request-<APPLICATION_ID>` |

Success returns `data` containing `idempotency_key`, `action`, `resource`,
`state=committed`, original small `result`, `recorded_at`, `expires_at`,
`retry_expired` and `replayed`; the envelope also has `request_id`. Receipt
fields are application `id`, `reference`, original `status`/`version`,
`submitted_at`, `withdrawn_at` and nullable `offer_id`. Refresh detail and
Assignment after success; this receipt describes the original action.

After timeout/restart, reconcile owned `GET rider/company-commands/{key}` and
refresh current resources before retrying the same key and payload.
`COMMAND_UNKNOWN` means no committed result is visible, including an unfinished
competing request; it does not prove failure. The shared journal separates
company and operational reconciliation routes and never returns another actor's
result. Exact retries return the original result/time within seven days, even
after later company suspension. They cannot restore readiness or extend an offer.
Changed action/resource/payload returns `IDEMPOTENCY_CONFLICT`. After seven days,
GET retains the result with `retry_expired=true`; writes return `COMMAND_EXPIRED`.
Refresh and make an explicit new intent only when the current resource allows it.

Company routes return private JSON even without an Accept header. Errors contain
`code`, `message`, field `errors` and `request_id`, with no-store, nosniff and
`X-Request-ID`. Handle 401 session loss, 403 denied account/ability, 404 hidden or
unknown resources, 409 changed source/intent, 422 invalid fields, 429 cooldown,
503 unavailable schema/database and generic 500 failures. No SQL, trace,
credentials or private evidence is returned. Respect the existing 30/minute IP
budget, shared 10/minute write limit and `Retry-After`; pause background polling.

## Rollout and Flutter handoff

RC03 adds no migration. Publish/merge through the user-owned Git workflow, then
deploy the ordinary backend with RC01/RC02 additive migrations, route/cache
refresh and the existing scheduler. No database reset, reseed or synthetic
legacy acceptance is needed. Check fresh sign-in, discovery, owned history,
exact acceptance, Assignment and explicit duty after rollout.

SQLite checks prove deterministic stale/competing input and rollback behavior;
they do not establish simultaneous PostgreSQL requests, live scheduler delivery,
Azure deployment or rendered Flutter/device readiness. Verify those separately.

Copy this to the Flutter rider AI once the backend branch is available:

```text
RC03 adds Bagoo's native rider company joining contract. Read backend
docs/api/RIDER_COMPANY_API.md, docs/api/rider-operations.openapi.json and the
docs/api/examples/rider-company examples. Work in the separate rider Flutter
repository and preserve its existing navigation, page style and controls.

After backend deployment, sign in again for explicit rider:company:read/write.
Discover company_api_version in account/Home and Home's company_joining flag.
Keep joining accessible to approved unassigned riders. Read company-assignment
for actual membership, provenance, readiness, sharing summary and apply action.

Implement the ten documented company methods. Show bounded company/service-area
summaries, owned requests and exact offer terms. Require true boolean sharing
and offer acknowledgement. Preserve string IDs, versions and terms_hash. Display
field errors and use current server actions; company review stays on the web.

Retain one Idempotency-Key and exact intent for each write. After a lost response,
reconcile company-commands/{key}, refresh detail/Assignment and retry the same
intent. A historical success does not mean the account/company is eligible now.
Acceptance leaves duty off. Existing assignments have no invented acceptance.
Honor expiry, bounded histories, cooldowns and sign-in requirements.

Verify the actual device journey: unassigned login, discovery, application,
company web offer, exact acceptance, refresh/resume, explicit duty and existing
pickup/delivery. Test refusal, stale terms, denied sessions and lost responses.
Backend checks do not certify the Flutter implementation or live deployment.
Handle company_request/company_assignment notice targets and retain a safe
fallback for unknown notification kinds.
```
