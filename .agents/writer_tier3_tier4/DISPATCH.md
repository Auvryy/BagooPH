# Task: Milestone Tier 3 Cross-Feature & Tier 4 Real-World Workload E2E Tests

You are a Test Writer / Worker for BagooPH E2E Testing Track.
Working Directory: /home/andy/Projects/bagoo/.agents/writer_tier3_tier4

Read:
1. /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md
2. /home/andy/Projects/bagoo/PROJECT.md
3. /home/andy/Projects/bagoo/TEST_INFRA.md
4. /home/andy/Projects/bagoo/.agents/explorer_e2e_survey/survey.md
5. Existing test helpers in `tests/Feature/E2E/Support/` and Tier 1 tests in `tests/Feature/E2E/Tier1/`

Your Responsibilities:
Implement all Tier 3 Cross-Feature tests (7 tests) and Tier 4 Real-World Workload tests (5 tests) = 12 tests total:

1. `tests/Feature/E2E/Tier3/CrossFeaturePairwiseTest.php` (7 tests):
   - `test_t3_01_kyc_approval_to_seller_fulfillment_pipeline`: F2 (KYC Approval) + F3 (Order Checkout & Packaging)
   - `test_t3_02_seller_packaging_release_to_courier_dispatch_broadcast`: F3 (Order Packaging) + F4 (Courier Dispatch Board)
   - `test_t3_03_courier_pickup_scan_to_logistics_hub_barangay_sorting`: F4 (Courier Dispatch) + F5 (Logistics Hub Checkpoints)
   - `test_t3_04_courier_doorstep_delivery_to_commission_distribution`: F4 (Courier Delivery) + F6 (Commission Ledger)
   - `test_t3_05_fast_forward_progression_syncs_buyer_timeline_and_checkpoint_trail`: F7 (Simulator) + F3/F4/F5 (Buyer Timeline & Checkpoints)
   - `test_t3_06_voucher_discounted_checkout_propagates_to_split_ledger`: F3 (Voucher Checkout) + F6 (Commission Split)
   - `test_t3_07_logistics_hub_reassignment_updates_courier_dispatch_and_audit_trail`: F5 (Logistics Hub Override) + F4 (Courier Board) + Audit Trail

2. `tests/Feature/E2E/Tier4/RealWorldWorkloadTest.php` (5 tests):
   - `test_t4_01_complete_metro_manila_multi_role_e2e_order_lifecycle`: Complete 5-role end-to-end choreography (Buyer KYC -> Checkout 2 variants with voucher COD -> Seller reviews & packs & thermal waybill -> Courier claims job -> Courier pickup barcode scan -> Logistics hub intake & Barangay San Antonio sorting bin -> Courier out-for-delivery & doorstep photo proof & COD settlement -> Commission ledger 90%/10%/₱60 -> Buyer live tracking timeline complete).
   - `test_t4_02_multi_seller_cart_independent_fulfillment_and_settlement`: Buyer orders from 2 distinct shops simultaneously, independent fulfillment & courier pickups, isolated commission ledgers.
   - `test_t4_03_courier_delivery_failure_exception_and_hub_rerouting`: Courier delivery failure -> Hub intake exception checkpoint -> Reassignment to new courier -> Successful doorstep delivery with photo proof & settled ledger.
   - `test_t4_04_rapid_fast_forward_simulator_stress_and_state_sync`: 5 distinct orders simultaneously fast-forwarded through all 7 stages; assert zero deadlocks, clean checkpoint trails, exact ledger balance across all 5 orders.
   - `test_t4_05_kyc_rejection_feedback_resubmission_and_first_sale_workflow`: Seller registration rejection with feedback -> Resubmission -> Admin approval -> Product listing -> Buyer purchase -> Merchant fulfillment.

Execute:
- `php artisan test tests/Feature/E2E/Tier3 --do-not-cache-result`
- `php artisan test tests/Feature/E2E/Tier4 --do-not-cache-result`
- `php artisan test tests/Feature/E2E --do-not-cache-result`
Ensure all tests pass 100%.

Write your report to `/home/andy/Projects/bagoo/.agents/writer_tier3_tier4/report.md` and `handoff.md`, then send a message back.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. An auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.
