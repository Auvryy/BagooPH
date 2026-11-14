# B14: ETA calculation and state-aware monitoring

This contract settles topics `9`, `18`, `20`, `21`, `25`, and the staging-pause part of `27` in [the brainstorming checklist](BAGOO_PH_BRAINSTORMING_CHECKLIST.md), on top of `B06`–`B13` in [the build order](BUILD_ORDER.md). Current implementation status and test evidence belong in [the roadmap](../CORE_FLOW_ROADMAP.md).

## Reconciled timing decisions

1. **Asia/Manila business clock**: Cutoff rules (`16:00` PHT same-day dispatch cutoff), daily counters ("Dispatched today", 7-day movement charts), counter-pickup deadlines, and ETA windows are evaluated in `Asia/Manila` (`+08:00`), stored in UTC, and rendered in Philippine time across buyer, seller, rider, hub, admin, and public tracking screens.
2. **No false certainty, auto-refund, or auto-liability from timers**: Passing time emits warnings, opens review queues or investigation cases, and closes eligible expired negotiation windows without fabricating physical scans, declaring theft/loss, debiting sellers/couriers, or releasing unverified refunds (`B04`, `B06`, `B11`–`B13`).
3. **Asymmetric forward vs reverse clocks (Topic 25 vs B12)**: Forward inter-hub transit carries a `48h` SLA (`forward_interhub_transit`), whereas approved reverse consolidation/transit (`reverse_consolidation_transit`) carries a `96h` SLA (`+48h` buffer). In accordance with `B12`, even a single approved return item (`>= 1`) remains dispatchable immediately without holding for a 20–30 parcel batch minimum.
4. **No automated 48h seller-response auto-approval (Topic 18 vs B11)**: Buyer returns keep the `7d` request, `72h` seller response, `72h` buyer appeal, and `7d` drop-off windows already persisted in `buyer_return_cases`. Automated scheduler handling warns before expiry, escalates overdue seller responses to admin review without approving refunds, and closes expired buyer appeal/drop-off windows while releasing unfulfilled `buyer_return_unit_claims`.

## Route-aware dynamic ETA calculation (`EtaCalculationService`)

Purchased orders replace the fixed `now()->addDays(3)` guess and `'Within 24 Hours'` storefront fallback with a server-computed ETA range (`estimated_delivery_min_at`, `estimated_delivery_max_at`) paired with backward-compatible `estimated_delivery_at` (= upper bound `estimated_delivery_max_at`) and immutable baseline snapshots inside `deliveries.eta_basis`:

| Segment / Route Topology | Min–Max Hours Added | Notes |
|---|---|---|
| Cutoff buffer (`>= 16:00` Asia/Manila) | `+12h .. +24h` | Applied at purchase if placed after 16:00 PHT |
| Seller prep (`placed` / `confirmed`) | `12h .. 36h` | Shrinks to `6h .. 24h` (`preparing`) and `2h .. 12h` (`ready_for_pickup`) |
| First-mile pickup (`assigned_pickup` / `picked_up`) | `2h .. 12h` | Shrinks to `2h .. 8h` once `picked_up` awaits origin hub intake |
| Same-child bypass (`same_hub_bypass = true`) | `4h .. 12h` | Local sorting & staging only; skips Mother Hub leg |
| Same-Mother-Hub regional route (`3` hubs) | `24h .. 48h` | Proportionally reduced as remaining unvisited hops decrease |
| Cross-Mother-Hub regional route (`4` hubs) | `48h .. 84h` | Proportionally reduced as remaining unvisited hops decrease |
| Legacy `v1` un-snapshotted route | `36h .. 72h` | Fallback estimate when `route_hub_ids` is absent |
| Final-mile doorstep (`sorted` / `assigned_to_rider` / `out_for_delivery`) | `6h .. 24h` (`1h .. 8h` out for delivery) | Respects destination hub & barangay dispatch |
| Self-pickup counter (`hub_self_pickup`) | `2h .. 8h` (`0h` once counter staged) | Completes projected window at counter readiness |
| Active Force-Majeure / Outage pause (`sla_pauses`) | `+elapsed paused seconds` (+`24h` buffer while ongoing) | Shifts projected ETA and marks `eta_status = 'paused'` |
| Overdue / stalled beyond `estimated_delivery_max_at` | Honest revision (`now + remaining`, tagged `delayed`) | Never leaves stale past delivery promises unflagged |

- **Checkout preview**: `LogisticsRoutingEngine::previewRoute()` and `LogisticsCheckoutService::withRoutes()` return read-only ETA previews per shop (`estimated_min_at`, `estimated_max_at`, `label`, `note`, plus active pause notice if any scope hub/province is paused). Previews write no database records.
- **State recalculation**: Order lifecycle updates, rider claim/reassignment, custody scans, retry approvals, and pause resumes recompute `estimated_delivery_min_at`, `estimated_delivery_max_at`, `estimated_delivery_at`, and `eta_basis` (retaining `initial_min_at` / `initial_max_at` for audit).
- **Public privacy boundary**: Public tracking (`/track/{tracking_number}`) shows only the projected ETA range and neutral delay/weather notice—never internal staff reasons, investigator identities, or customer addresses.

## State-aware SLA clock matrix (`sla_clocks` + `sla_clock_events`)

Each active subject holds distinct keyed clocks (`subject_type`, `subject_id`, `clock_kind`) with append-only state transition events (`started`, `paused_by_pause`, `paused_by_hold`, `resumed`, `warned`, `breached`, `stopped`):

| `clock_kind` | Subject | Start trigger | Pause conditions | Stop triggers | Warning / Breach thresholds |
|---|---|---|---|---|---|
| `seller_acceptance_pack` | `order` | Paid order placed (`placed`, `confirmed`, `preparing`) | Pending `OrderCancellationRequest`; active `sla_pauses` matching seller hub/province/company/platform | `ready_for_pickup`, `cancelled`, `completed`, `returned` | `24h` acceptance warn, `36h` packing warn, `48h` overdue escalation flag |
| `pickup_dispatch_wait` | `delivery` | `order.status = ready_for_pickup` | Pending `OrderCancellationRequest`; `HandoverHold`; active `sla_pauses` matching `origin_bayan_hub_id`/province/company/platform | `picked_up` scan, `cancelled`, `returned` | `12h` pool warn, `24h` Pickup Delayed, `36h` urgent escalation, `48h`/`72h` B15 resolution |
| `hub_idle_sorting` | `delivery` | Arrival in hub custody (`arrived_at_origin_hub`, `arrived_at_mother_hub`, `arrived_at_destination_hub`, `sorted_to_line_haul`) | **`sorted_to_barangay_bin` (`STAGED_FOR_FINAL_MILE`)**, `ready_for_hub_pickup`, `HandoverHold`, `ParcelRecovery`, active `sla_pauses` | Outbound dispatch (`in_transit_*`, `out_for_delivery`, `customer_collected`, `delivered`, `cancelled`, `returned`); resets when arriving at a new hub | `2h` floor warn, `12h` manager alert, `24h` stalled-hub alert, `48h` B15 investigation open |
| `final_mile_staging` | `delivery` | `sorted_to_barangay_bin`, `assigned_to_rider`, or `out_for_delivery` | Failed delivery approved future `retry_at`, `HandoverHold`, active `sla_pauses` covering destination hub/province/company/platform | `delivered`, `customer_collected`, `delivery_failed`, `return_to_sender`, `cancelled` | `12h` unassigned staging warn, `24h` overdue rider departure / out-for-delivery stalled alert |
| `forward_interhub_transit` | `delivery` | `picked_up` (>24h pickup-to-hub gap) or dispatched linehaul/shuttle manifest (`in_transit_to_mother_hub`, `in_transit_to_destination_hub`) | Active `sla_pauses` matching origin/dest hub, province, company, or platform | Arrival scan (`arrived_at_*`), `cancelled`, `returned` | `24h` pickup-to-origin warn / `36h` transit warn; `48h` inter-hub scan timeout opens B15 investigation |
| `counter_pickup_holding` | `pickup_claim` | Counter ready staging (`ready_for_hub_pickup`) | Active `sla_pauses` matching destination hub/province/company/platform (extends counter expiry by paused duration on resume) | Collected, `holding_expired` / RTS, `cancelled` | Day `3` and Day `6` notices; Day `7` (`Asia/Manila`) holding expiry |
| `return_decision_window` | `buyer_return_case` | `pending_seller` (`72h` response) or `rejected` (`72h` appeal) or `approved` (`7d` drop-off) | Active platform/hub `sla_pauses` for seller/buyer drop-off hub | Final resolution (`approved`/`rejected`/`expired`/`denied`/drop-off recorded) | Pre-expiry reminder at `-24h`; overdue seller response escalates to Admin review (no auto-approve); overdue buyer appeal/drop-off auto-closes and releases unit claims |
| `reverse_consolidation_transit` | `delivery` / `buyer_return_parcel` | `return_to_sender`, `return_in_transit`, or buyer return hub intake/transit | Active `sla_pauses`; `HandoverHold`; seller-return staged at origin hub awaiting seller receipt | `returned` / seller return receipt / refund close | `48h` warn; `96h` asymmetric reverse timeout opens B15 missing-parcel investigation |

## Force-majeure and outage pauses (`sla_pauses` + `SlaPauseService`)

- **Authorized actors**: Platform Admins may pause/resume `platform`, `province`, `company`, or `hub` scopes. Logistics Company Managers may pause/resume their own `company` or `hub` scopes.
- **Deterministic idempotency**:
  - Activating a pause marks every running overlapping `sla_clocks` row `paused` (`paused_at`, `active_pause_id`) and shifts `deliveries.eta_basis['eta_status'] = 'paused'`.
  - Ending/resuming a pause computes exact paused duration (`max(0, endedAt - pausedAt)`), applies it **at most once per pause ID** via unique `sla_clock_events.idempotency_key`, shifts `warning_at`, `breach_at`, `counter pickup expires_at`, and `estimated_delivery_min_at`/`estimated_delivery_max_at`/`estimated_delivery_at` forward, and resumes non-terminal clocks cleanly.

## Idempotent monitoring scheduler and dry-run mode

- `monitoring:scan-sla {--dry-run} {--limit=100}` runs every minute via Laravel scheduler (`withoutOverlapping()`).
- **Dry-run mode** (`--dry-run` option or admin dry-run preview endpoint) inspects clocks under read-consistent rules and outputs planned warnings, B15 escalation triggers, B15 investigation candidates, and return window closures **without** writing status changes or emitting user notifications.
- **Live execution safety**: Acquires `DB::transaction` row locks in deterministic order (`Order` -> `Delivery` -> `SlaClock`), re-checks live state (`cancelled`, `completed`, scanned custody, live `OrderCancellationRequest`, active `sla_pauses`) before taking action, and deduplicates notice/event keys so duplicate scheduler ticks or concurrent custodian scans never emit duplicate warnings or false timeout actions.
