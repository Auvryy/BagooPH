# Native Rider backend delivery plan

The existing Laravel application owns assignment, custody, cash and account rules.
The native client consumes JSON adapters over those same services. Pickup and
final-mile remain phases of one courier role. Account and Settings v1 retain
their existing routes and response shapes.

Current implementation evidence, readiness, scoped ratings and deployment limits
belong in [CORE_FLOW_ROADMAP.md](../CORE_FLOW_ROADMAP.md). The accepted wire contract
belongs in [RIDER_OPERATIONS_API.md](RIDER_OPERATIONS_API.md); future operations
must not appear in its executable specification until implemented.

| Batch | Existing source to reuse | Adapter and dependencies | Priority / estimated backend effort |
|---|---|---|---|
| 1: Home, duty, pickup queues/detail and claims | CourierOperationsService, LogisticsEligibilityService, immutable DeliveryCheckpoint | Narrow token abilities, source revisions, private previews, actor-scoped transactional commands and result reconciliation. Claim is assignment; origin-hub intake remains a hub action. | Core / 4–6 hours |
| 2: Physical parcel work and exceptions | OrderStateMachineService, WaybillScanInputService, DeliveryRecoveryService, CodCashService | Distinct pickup/depart/deliver/fail commands, genuine submitted waybill, private image proof, recipient and exact cash. Fix shared proof handling before exposing delivery. Hub return/retry/RTS stay web-owned. | Core / 6–10 hours |
| 3: Trips and evidence | Retained assignment/custody checkpoints and attempt records | Stable assignment references, attribution surviving reassignment, bounded history, Manila-day/payment/search filters, purpose-scoped evidence reads. Ambiguous legacy assignments remain unavailable. | Core / 3–5 hours |
| 4: Communication | CourierMessagingService, NotificationCenterService, durable lifecycle/governance notices | Phase/participant-bound threads, bounded messages, exact displayed read boundary, send retries, owned durable inbox/read actions. No new realtime or push provider. | Core / 4–7 hours |
| 5: Cash | CodCashService, CodCashViewService, append-only accounts/events | Exact cent strings, retained responsibilities, allowed recipient and offer-only rider initiation. Hub receipt and platform reconciliation stay separate. | Core / 3–5 hours |
| 6: Conditional additions | Existing task stops, frozen checkout destination, account/Settings APIs | Stop Mode uses task detail. Doorstep instructions expose only actual authorized stored data. Personal compartment labels start device-only, scoped by account plus assignment reference; app cleanup is mobile-owned. Server tags, native correction/recovery/password-reset flows require a separately chosen product contract. | Conditional / 2–4 hours for a selected contract; implementation estimated after selection |

These are planning ranges including focused checks and handoff, not measured
history or promised completion times. Reserve an additional 4–6 hours for combined
regression, contract review and fixes. Use bounded sequential batches through
October 9–16 as a planning assumption, leaving the October 4–November 20 project
window and November 21 presentation assumption intact. Publication, Azure rollout
and physical Android acceptance require their own time and evidence.

The source audit resolves several earlier proposals: pickup claims use
`pickup-jobs`, task references include their assignment phase, and Settings uses
the already accepted `rider/settings` routes. There is no duplicate profile API,
generic status setter, rider-owned hub intake or final-mile self-assignment.
ProofOfDeliveryValidator is a legacy helper, not the current custody writer;
the state machine, actual file validation and frozen order destination govern.

Pre-custody release is unavailable until a shared reasoned release policy exists.
Native restricted recovery remains on the established narrow website workflow;
ordinary suspended tokens are revoked. Rider earnings remain unavailable until
an authoritative earnings writer exists. COD, shipping charges and seller
commission cannot supply a guessed earnings value.

For every delivered batch, hand off its local commits and routes, OpenAPI and
sanitized fixtures, fields/abilities/limits/retry rules, exact checks and gaps,
additive schema/config instructions, and a Flutter integration checklist. A local
test result is distinct from publication, migration, deployment or device proof.
Actual PostgreSQL races require independent connections to an authorized isolated
test database. Ordinary repository tests remain restricted to SQLite `:memory:`.
Running the [disposable PostgreSQL verifier](../../scripts/verify-rider-races/README.md)
requires an explicit exception for that isolated database only. Record the actual
run and its limits in the roadmap; SQLite results alone cannot satisfy this gate.

No live GPS, ETA, route optimization, AI dispatch, offline-success queue,
role conversion, payout/withdrawal or new external service enters this plan.
