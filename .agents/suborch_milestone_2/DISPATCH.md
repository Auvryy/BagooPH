## 2026-08-27T08:43:03Z

You are the Sub-Orchestrator for Milestone M2: Unified 7-Stage Order Lifecycle, Packaging & Waybill Dispatch.
Your working directory is: /home/andy/Projects/bagoo/.agents/suborch_milestone_2

MANDATORY: Read /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md and /home/andy/Projects/bagoo/PROJECT.md before starting work.
Also read Milestone M1 handoff at /home/andy/Projects/bagoo/.agents/suborch_milestone_1/handoff.md.

Scope of Milestone M2:
1. Feature 3 (Unified 7-Stage Order Checkout & Packaging Lifecycle):
   - Buyer Checkout: Placing order creates `Order` with initial status `pending` (not skipping to processing), with selected variants (color, size, sku) persisted, payment method, address, voucher applied.
   - Seller Cockpit (`/seller/orders`):
     * Merchant reviews incoming `pending` orders.
     * Clicks "Approve & Pack" / "Packaging" -> transitions order to `packaging` (or `processing`).
     * Thermal Waybill: Generates printable 4x6 thermal shipping label modal with simulated tracking barcode, recipient details, merchant origin, COD payment badge.
     * Clicks "Mark Ready for Pickup" / "Schedule Courier" -> transitions order to `ready_for_pickup` and ensures an `unassigned` Delivery job is broadcasted.
2. Feature 4 (Courier Dispatch Board & Live Buyer Tracking):
   - Courier Dispatch Board (`/courier/deliveries`):
     * Unassigned delivery jobs appear in real-time on the Available Jobs board.
     * Courier claims job (FCFS with race condition protection) -> sets `assigned`, `courier_id`.
     * Courier navigates to merchant and marks "Picked Up" -> sets `picked_up`, updates `order.status = 'shipped'`.
     * Courier transitions parcel through "In Transit" and "Out for Delivery".
     * Courier delivers parcel -> collects COD / confirms drop-off, uploads proof image -> sets `delivered`, `order.status = 'delivered'`, `order.payment_status = 'paid'`.
   - Buyer Tracking Timeline (`/buyer/orders/{id}` & `/buyer/orders`):
     * Live synchronized timeline reflecting every milestone: Order Placed -> Packaging -> Ready for Pickup -> Picked Up -> In Transit -> Out for Delivery -> Delivered.

Execution Instructions:
- Run the full iteration loop: Explorer -> Worker -> Reviewer -> Challenger -> Forensic Auditor -> Gate check in GATE_STATUS.md.
- Ensure Worker includes the mandatory integrity warning.
- Verify migrations run cleanly (`./bagoo.sh migrate`), build passes (`npm run build`), and automated tests pass.
- When gate passes, write handoff.md and report completion to parent orchestrator.
