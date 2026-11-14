# B17 — Throughput Scanning, Acceptance Rehearsal & Logistics UI Clarity Contract

**Status:** Active build specification (`B17`, final brainstorming package)  
**Branch:** `feat/brainstorm-b17-throughput-ux-acceptance`  
**Source Topics:** Topics 14, 15, 16, 19, 20 (+ full B00–B16 acceptance alignment & Teacher UI clarity review)  
**Prerequisites Verified on `main`:** `B01`–`B16` (latest merged PR #165 at `1555f36`)

---

## 1. Core Decisions (Retaining B03, B07, B08, B09, B14–B16 Invariants)

1. **No Blind Auto-Progression on Read/Inspect (`B07` / `B08` Preserved):**
   - `POST /hub/scan` (`mode=inspect`) and `POST /api/v1/logistics/scan` (`mode=inspect`) remain strictly read-only.
   - Validation rules `'continuous' => 'prohibited'`, `'auto_advance' => 'prohibited'`, and `action not_in:AUTO_PROGRESS` remain enforced (`422` with zero mutations).
   - `ContinuousScanAutomationService::canAutomate()` continues returning `false` and `executeAutomatedProgression()` continues throwing `DomainException`.
   - Saved `localStorage` flags on the client MUST NEVER convert `mode=inspect` into automatic custody confirmation.

2. **Safe Low-Click Scanning Modes (`Topic 14` & `Topic 20` Solved Safely):**
   Operators in `ScanStation` (or authenticated scanner clients via `/api/v1/logistics/scan/batch`) choose among three explicit live-session modes:
   - **Verify First (Default — 2-Step):** `inspect` first -> operator reviews custody/route/DWS -> deliberate `confirm`. Preserves exact backwards compatibility with `B07` and all existing clients.
   - **1-Scan Direct Confirm (Explicit Station Opt-In):** Operator explicitly toggles `1-Scan Direct` inside the live workstation session (never auto-loaded from saved preferences) and may pin an expected station lane action (e.g., `ANY_PERMITTED_PARCEL_STEP`, `RECEIVE_FROM_PICKUP_RIDER`, `SORT_TO_LINE_HAUL`, `STAGE_FOR_PICKUP`, `RECEIVE_FAILED_DELIVERY`). Each barcode scan (hardware wedge, Enter key, or camera) generates a fresh UUID `request_token` + `client_scanned_at` and submits one confirmed direct-scan item to `POST /hub/scan/batch`.
     - **Hard Safety Boundaries:** Rejects `BRT-` returns, `BAG-` / `RMB-` master bags (bag seals and parcel receipts MUST use explicit bag receiving + manifest receiving), manifest-only movements, `INSPECT_WAYBILL` / `AWAIT_*`, held/quarantined parcels (`ShipmentLossCustody`, `MisrouteTrapService`, `HandoverHoldService`), unauthorized actors (`canScan === false`), two-human violations (`ParcelCustodyGuard`), and lane-action mismatches without altering custody.
   - **Offline / Batch Scan Queue & Replay:** Operator scans parcels continuously into an indexed client queue (`request_token`, `barcode`, `client_scanned_at`, optional `location_barcode`, optional `expected_action`) even while offline or during burst unloading. Clicking **Sync Queue** (or calling `POST /hub/scan/batch` / `POST /api/v1/logistics/scan/batch`) transmits the queued items in order.
     - **Per-Item Transaction & Replay Ledger:** Every queued item executes in its own atomic transaction using `HubBatchScanService`, locking `Order -> Delivery -> Network` canonically (`B03` L55).
     - **Idempotent Replay:** Immutable `scan_batch_requests` and `scan_batch_items` enforce `unique(['actor_id', 'request_token'])` + SHA-256 `request_fingerprint`. Replaying the same `(actor_id, request_token, request_fingerprint)` returns `outcome: 'duplicate_replay'` with zero double checkpoints or status drift. Reusing a `request_token` for a different payload fails explicitly (`409` / rejected item).
     - **Partial Failure Isolation:** An error on item #3 (`rejected`) does not roll back confirmed items #1–#2 or block items #4–#N.

3. **Fast Inline Mother Bag Slotting (`Topic 15` / `Topic 16` Without Fake Seals):**
   - When `ScanStation` displays a parcel awaiting regional sorting/consolidation (`mother_bag_slotting` & valid routing target), offer an inline **1-click "Pack into open Mother Bag"** action backed by `POST {basePath}/mother-bags/pack` (`request_token`), while requiring operator outer-package readings whenever single-unit purchased declarations do not apply (`B08`), and keeping `seal`, `open`, and manifest `receive` explicit.

4. **Measured Throughput & N+1 Query Optimization (`B08` / `B09` Contract):**
   - Unverified hardcoded `"0.2–0.5s"` speed claims are prohibited in UI/docs. Every batch replay reports actual measured `elapsed_ms` and `scans_per_sec` from the server.
   - Optimize hot-path lookups and workstation queries:
     - Indexed exact `tracking_number` lookup attempted first before `order_number` fallback (avoiding unconditional `orWhereHas` scan on every parcel scan).
     - Cache per-hub `canScan` across workstation rows and eager load `latestOriginalCheckpoint` + `assignedRider.courierProfile` on `/hub/deliveries` to eliminate `HubParcelWorkstationService::guidance()` N+1 lazy loads.
     - Preload destination hubs & fleet candidates in `DispatchStagingService::corridors()` to eliminate per-corridor `findOrFail` / fleet N+1 lookups on `/hub/scan` and `/hub/corridor-staging`.
     - Compact batch-item result payload in `HubBatchScanService` avoids loading unused item/buyer/DWS candidate subgraphs during bulk queue sync.

---

## 2. Additive Schema (`2026_11_14_120000_create_b17_scan_batches_tables.php`)

- `scan_batch_requests`:
  - `id` (bigIncrements)
  - `batch_reference` (`uuid`, unique)
  - `logistics_company_id` (constrained `logistics_companies`)
  - `hub_id` (constrained `logistics_hubs`)
  - `actor_id` (constrained `users`)
  - `source_channel` (`string(16)`: `'web'` | `'api'`)
  - `direct_mode` (`boolean`)
  - `pinned_action` (`string(100)`, nullable)
  - `submitted_count`, `confirmed_count`, `duplicate_count`, `rejected_count`, `elapsed_ms` (`unsignedInteger`)
  - `created_at` (`timestamp`)
- `scan_batch_items`:
  - `id` (bigIncrements)
  - `scan_batch_request_id` (constrained `scan_batch_requests`)
  - `actor_id` (constrained `users`)
  - `hub_id` (constrained `logistics_hubs`)
  - `request_token` (`uuid`)
  - `request_fingerprint` (`string(64)`)
  - `barcode_submitted` (`string(255)`)
  - `delivery_id` (nullable constrained `deliveries`)
  - `expected_action` (`string(100)`, nullable)
  - `action_applied` (`string(100)`, nullable)
  - `outcome` (`string(32)`: `'confirmed'` | `'duplicate_replay'` | `'rejected'`)
  - `status_before` (`string(100)`, nullable)
  - `status_after` (`string(100)`, nullable)
  - `checkpoint_reference` (`string(100)`, nullable)
  - `error_message` (`string(500)`, nullable)
  - `client_scanned_at` (`timestamp`, nullable)
  - `server_processed_at` (`timestamp`)
  - `latency_ms` (`unsignedInteger`)
  - `created_at` (`timestamp`)
  - Unique index: `unique(['actor_id', 'request_token'])` (enforces cross-request idempotent replay).
- **DB Retention Triggers:** Both tables enforce immutable SQLite + PostgreSQL triggers rejecting `UPDATE` and `DELETE` once inserted, matching `B08`/`B16` audit guarantees.

---

## 3. Logistics UI Clarity, De-Cluttering & Role Guidance Remediation

To directly resolve the stakeholder / instructor critique (*"too much red, too cluttered/compact, unclear categories & what each user should do next"*):

1. **Balanced Semantic Hierarchy (De-Redding Without Monochrome/Obsidian):**
   - `#E00D42` stays reserved strictly for **primary submit/confirm buttons** and **critical errors/holds** rather than flooding every secondary panel and background box in crimson.
   - Avoid monochrome black-and-white / obsidian fills and preserve clear clickable button contrast aligned with Bagoo brand semantics instead of red background saturation everywhere.
   - Completed pipeline steps (`HubStepRail` `done` & `HubConveyorRail` completed stages) use semantic **emerald** so status progression stands out clearly.
   - Zero `font-mono` classes and zero emoji icons across all logistics/courier/shared UI components.

2. **Spatial De-Cluttering & Progressive Disclosure:**
   - Remove redundant `"Logistics Workspace"` decorative chips and compact `text-[9px]`/`text-[10px]` badge overload in favor of clear `12px–14px` readable typography and generous spacing.
   - Separate secondary creation/configuration/reference panels (DWS manual calculator, Mother Bag manual creation, Manifest draft composer, historical audit trails) into clean tabs or collapsible accordions rather than stacking them permanently on top of daily queues.

3. **Category Grouping & Role-Specific "What to Do Next":**
   - Group Hub & Rider worklists around actionable task buckets (**Needs Action Now**, **Waiting / In Transit**, **Completed / History**) rather than mixed flat lists.
   - Provide an explicit **"What to do next" Role Quick-Guide** banner/selector tailored by logistics role:
     - **Pickup & Delivery Rider:** Accept assigned/open pickups -> scan waybill at seller -> deliver to Origin Bayan Hub (cannot receive own parcel) OR complete final-mile POD + COD collection.
     - **Local Hub Handler:** Scan incoming rider parcels -> sort into Barangay Bins (doorstep) / Counter shelf (self-pickup) / Mother Bags (inter-hub) -> stage & confirm carrier handover.
     - **Mother Hub Sorter:** Verify arriving Mother Bag seal -> unpack & scan parcels -> re-sort into destination Mother Bags / loose freight -> load onto regional linehaul manifest.
     - **Logistics Company Manager:** Monitor SLA alerts, fleet assignments, hub capacity, and COD cash settlements (hub floor scanning remains restricted to assigned `HubHandler` operators).
