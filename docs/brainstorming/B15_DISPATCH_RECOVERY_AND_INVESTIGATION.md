# B15: Dispatch recovery and missing-parcel investigation

This contract settles topics `7`, `9`, `10`, `18`, `21`, and `25` in [the brainstorming checklist](BAGOO_PH_BRAINSTORMING_CHECKLIST.md), extending [B14 monitoring](B14_ETA_AND_STATE_MONITORING.md) and preserving [B04 money/liability](B04_MONEY_AND_LIABILITY.md), [B07 handovers](B07_INDIVIDUAL_HANDOVERS.md), [B09 dispatch](B09_SORTING_AND_DISPATCH.md), [B10 completion/COD](B10_DELIVERY_COMPLETION_AND_CASH.md), and [B13 evidenced refunds](B13_REFUND_EXECUTION_AND_FUNDING.md).

## Key distinctions and deferred items

1. **No available rider vs No service coverage (`coverage_outcome`)**:
   - `no_service_coverage`: The company has inactive/invalid origin or destination Bayan Hub mapping, missing Mother Hub path, disabled self-pickup capability, or non-contiguous non-road geography (`LogisticsRoutingEngine::resolveRoute()`).
   - `pickup_paused`: A Bayan Hub or specific covered barangay currently has an explicit temporary operational pickup pause (`hub_pickup_pauses`).
   - `no_available_rider`: Valid route coverage exists, **but** zero operational riders with pickup capacity (`< 5` active pickups, no active final-mile duty conflict, `is_available = true`) are currently available at `origin_bayan_hub_id`.
   - Checkout blocks on `no_service_coverage` and active explicit `pickup_paused` scopes while exposing honest `coverage_status` (`available` | `no_available_rider` | `pickup_paused` | `no_service_coverage`) so minimal hubs without live on-duty riders during dispatch wait are surfaced accurately rather than mislabeled out of coverage.
2. **Unsupported surge pay remains deferred**: Step 3 of the pickup escalation ladder (`24h–36h`) elevates parcel priority (`urgent`) and logs an explicit audit note (`surge_deferred_note`), without inventing unapproved courier monetary payouts or distorting `B04` company/rider earnings formulas.
3. **No manager self-sweep bypassing named courier custody (`B07` / `B09`)**: Step 4 (`36h–48h`) alerts the origin hub/company manager to sweep stalled ready parcels and assign/activate an eligible company rider or vehicle driver (`isEligibleCourier()`), without permitting a hub manager account to forge a rider pickup scan or confiscate another hub's vehicle.
4. **No unilateral 24h cross-company/hub rerouting without a route contract (`B03` / `B09`)**: Topic 21's 24h stalled-hub condition raises persistent stalled-hub alerts, recovery guidance, and manual controlled review without silently rewriting `deliveries.route_hub_ids` or `logistics_company_id`.
5. **Scan timeout opens an investigation case, not blame or refund**: Timeout detection creates or updates a traceable `MissingParcelInvestigation` (`proves_loss = false`, `liability_determined = false`). Financial loss disposition (`ShipmentLossReview` / `ShipmentFinancialPlan`) still requires independent admin confirmation and evidence (`B13`). External insurance funding and security deposit bonds remain deferred (`B04` / `B13`), with explicit `insurer_funding_status = 'deferred_no_insurer_pool'` and `compensation_status` tracking.

## First-mile pickup escalation ladder (`dispatch_escalations`)

Tracked per unassigned/unpicked `ready_for_pickup` parcel (`deliveries.status in ['unassigned', 'assigned', 'assigned_pickup']`, `order.status = 'ready_for_pickup'`, paused by Force Majeure / cancellation request / `HandoverHold`):

| Step | Window from `ready_for_pickup` | Actions & State |
|---|---|---|
| `open_pool` (`0–12h`) | Starts on `ready_for_pickup` | Visible in FIFO pickup pool (`CourierOperationsService::queue(..., 'available')`). At `12h` unpicked -> advance to `direct_assignment` or alert origin hub. |
| `direct_assignment` (`12–24h`) | `>= 12h` unpicked (or `assigned_pickup` >12h uncollected by same rider) | Attempts capacity-aware push assignment to lowest-load operational `CourierProfile` (`is_available = true`, `activePickupCount < 5`, no final-mile blocking work) at `origin_bayan_hub_id`. Updates tracking sub-stage hint `dispatch_delay_label = 'Pickup Delayed — Reassigning Rider'` at `24h` without changing the 13 canonical statuses. If 0 eligible riders -> sets `coverage_outcome = 'no_available_rider'`. |
| `priority_bounty_deferred` (`24–36h`) | `>= 24h` unpicked | Elevates queue priority (`is_urgent = true`), emits `Pickup Delayed — Reassigning Rider` customer/seller delay notice, and records deferred surge note (`surge_deferred_note`). Re-attempts capacity-aware rider matching when a rider opens capacity. |
| `hub_emergency_sweep` (`36–48h`) | `>= 36h` unpicked | Alerts Origin Bayan Hub Manager & Company Operations (`notification_deliveries` + Hub Dashboard SLA attention panel) to rebalance fleet / open hub pickup capacity or pause affected barangay/hub pickup temporarily. |
| `unpicked_fallback` (`48–72h`) | `>= 48h` (escalated) / `>= 72h` (eligible safe auto-cancel) | Records coverage distinction (`no_available_rider` vs `pickup_paused` vs `no_service_coverage`). Reached `72h` strictly unclaimed (`courier_id = null`, `status = 'unassigned'`, no custody beyond seller, no hold, no pending cancellation, valid COD pending or verified Wallet reservation/payment) may cancel via `OrderLifecycleService` penalty-exempt path (`penalty_exempt = true`), restoring product stock & reversing Wallet/voucher reservation once cleanly. Ambiguous/claimed parcels remain flagged for manual Hub/Admin resolution. |

## Capacity-aware rider reassignment (`DispatchRecoveryService::reassignRider`)

1. **First-mile pre-handover reassignment (`unassigned`, `assigned`, `assigned_pickup`)**: Allowed only before `picked_up` custody transfer (`custody_after.kind` absent or `'seller'`). Locks `Order`, `Delivery`, network, and old/new `CourierProfile` (`user_id` ascending order). Verifies new rider belongs to same company + `origin_bayan_hub_id`, `is_available`, `< MAX_ACTIVE_PICKUPS_PER_RIDER (5)`, no active final-mile work (unless releasing back to open pool `rider_id = null`). Records append-only `pickup_reassigned` checkpoint and notifies previous rider, new rider, and seller.
2. **Final-mile pre-departure reassignment (`sorted_to_barangay_bin` or `assigned_to_rider` before `out_for_delivery`)**: Allows Hub Manager / Company Admin to move an assigned parcel from an overburdened/off-duty rider to another eligible destination rider before physical departure (`Delivery::MAX_PARCELS` workload check, barangay/company/hub match, `explicit reassign = true` + `reason`).
3. **Post-custody immutability**: Parcels already `picked_up` or `out_for_delivery` (`custody_after.kind = 'courier'`) cannot be silently reassigned; recovery must flow through `HandoverIssue` / `HandoverRecoveryGrant` / `RestrictedCustodyRecoveryService`.

## Stalled-hub & overload monitoring (`Stall & Coverage Telemetry`)

- Evaluates `sla_clocks` (`hub_idle_sorting`, `forward_interhub_transit`, `final_mile_staging`, `pickup_dispatch_wait`), `BarangayRiderDensityEngine` final-mile load (`40` heavy, `> 60` critical overflow), and active pickup pool queue depth per Bayan/Mother hub.
- Persists and displays stalled counts, overloaded hubs, and temporary `hub_pickup_pauses` controls in `Hub/Dashboard`, `Hub/Deliveries`, and `Admin/Logistics`.

## Missing-parcel investigation lifecycle (`missing_parcel_investigations`)

Distinct additive tables (`missing_parcel_investigations` and append-only `missing_parcel_investigation_events`) integrate cleanly with `ExceptionOversight` and `ShipmentLossQueue` without relaxing `exception_decisions` DB CHECK constraints:

1. **Opening**:
   - **Automatic (`scan_timeout` / `hub_stall` / `pickup_transit_gap`)**: Opened idempotently by `StateMonitoringService` when `forward_interhub_transit` (`48h`), `reverse_consolidation_transit` (`96h`), `hub_idle_sorting` (`48h`), or `picked_up` pre-intake (`24h`) breaches without an active Force-Majeure pause. Sets `status = 'investigating'`, `proves_loss = false`, `liability_determined = false`.
   - **Manual / Manifest Discrepancy (`manifest_missing` / `manual_report`)**: Opened manually by Logistics / Admin or linked when an arriving manifest records `report-discrepancy` (`kind = 'missing'`).
2. **Investigation & Finding**:
   - Authorized Company Ops or Platform Admin may `assign` an investigator (`investigator_id`) and record `finding` updates (`missing_parcel_investigation_events`) citing actual checkpoints, facility sweeps, and bag/manifest references.
3. **Resolutions**:
   - `recovered` (or auto-closed `'recovered'` if physical custody/recovery scan arrives): Parcel re-enters `ParcelRecoveryService` / `HandoverRecoveryService` / normal next scan; zero financial penalty created.
   - `confirmed_lost`: Admin-only confirmation (`independent` reviewer check when possible) setting `proves_loss = true` and `liability_determined = true`:
     - If `v2` manifest `'missing'` observation exists -> links to `ShipmentLossReview` (`/shipment-finances/missing/{observation}/review`) to create/track `ShipmentFinancialPlan`, `WalletLossReserve`, or Cash Compensation.
     - Tracks explicit `compensation_status` (`pending_review` -> `linked_financial_plan` / `deferred_legacy_manual`) and **`insurer_funding_status = 'deferred_no_insurer_pool'`** (`B04` / `B13`).
   - `closed_unfounded`: Duplicate/false timeout cleared once normal custody scan or cancellation explanation is verified.

## Acceptance evidence plan

- Targeted Unit tests: `EtaCalculationService` range math, `Asia/Manila` `16:00` cutoff boundary, Force-Majeure shift math, `no_available_rider` vs `no_service_coverage`.
- Targeted Feature tests: `B14` clock start/stop/pause/resume (including `STAGED_FOR_FINAL_MILE` pause), duplicate scheduler `--dry-run` vs live runs, changed-state protection (live cancellation/scan race), Manila dashboard counts; `B15` 4-step pickup ladder + deferred surge note + 72h penalty-exempt cancellation reversal, first-mile & final-mile reassignment concurrency/capacity, pickup-pause checkout feedback, stalled-hub alerts, and timeout -> `MissingParcelInvestigation` -> `recovered` vs `confirmed_lost` + public tracking privacy.
- Frontend Node tests: ETA disclosure in checkout/tracking/order views, Hub & Admin monitoring/reassignment/investigation/force-majeure UI workflows.
