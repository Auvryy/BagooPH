# Original User Request

## Initial Request — 2026-08-27T08:16:31Z

<USER_REQUEST>
Complete end-to-end data interconnectedness across all 5 BagooPH user roles (Buyer, Seller, Courier Rider, Logistics Sorting Hub, and Platform Admin) with real database workflows, order packaging/dispatch approvals, barcode/location scanning simulator, a demo fast-forward delivery progression control, and mandatory Admin KYC approval enforcement for newly registered users.

Working directory: /home/andy/Projects/bagoo
Integrity mode: development

## Requirements

### R1. Unified End-to-End Order & Delivery Lifecycle
Ensure complete data interconnectedness between Buyer, Seller, Courier Rider, and Admin:
- **Buyer Checkout & Placement:** Buyer selects product variants, applies vouchers, and submits an order with real delivery address and payment choice (COD / Simulation).
- **Seller Order Preparation & Handover:** Seller sees incoming order in Seller Cockpit (`/seller/orders`), reviews order items, clicks to approve and pack ("Packaging"), generates thermal waybill/shipping label, and marks parcel "Ready for Pickup".
- **Courier Dispatch Board & Pickup:** Unassigned delivery appears on Courier Dispatch Board (`/courier/deliveries`). Courier claims request (First-Come, First-Served), navigates to store location, and confirms item pickup ("Picked Up").
- **Transit & Doorstep Delivery:** Courier transitions parcel through "In Transit" and "Out for Delivery", collects COD / confirms drop-off, and submits proof of delivery ("Delivered").
- **Live Buyer Tracking Timeline:** Buyer sees real-time synchronized status updates across every delivery milestone on `/buyer/orders` and `/orders/{id}`.

### R2. Interactive "Fast-Forward" Order Delivery Simulator
Provide an interactive testing/simulation control (visible on Order Detail / Courier / Seller screens for demo and testing):
- A "Fast-Forward Stage" / "Simulate Next Step" button that instantly advances the order and delivery through its status sequence:
  `pending` ➔ `packaging` ➔ `ready_for_pickup` ➔ `picked_up` ➔ `in_transit` ➔ `out_for_delivery` ➔ `delivered`.
- Enables developers, reviewers, and evaluators to test and showcase the full interconnected multi-role lifecycle in seconds without switching sessions or waiting for manual triggers.

### R3. Parcel Location Scanning & Status Approval Checkpoints
Implement tactile status update checkpoints representing physical package scans and handovers:
- Seller packaging & dispatch release confirmation.
- Rider pickup verification with barcode/tracking code scan simulation.
- Logistics hub receipt and barangay sorting confirmation.
- Courier final drop-off and recipient handover verification.

### R4. Multi-Role KYC Registration & Admin Approval Gate
Enforce strict account governance and KYC approval:
- Buyer, Seller, and Courier registration forms collect required identification documents (ID, business permit, or driver's license/OR-CR).
- Newly registered accounts default to `pending_approval` status.
- Admin Verification Queue (`/admin/users` or `/admin/dashboard`) displays submitted applicant documents with one-click `Approve` (activates account) or `Reject` (with feedback reason).
- Account status gates portal access on login, preventing unapproved users from accessing transactional features.

### R5. 10% Platform Commission & Financial Split Ledger
- Delivers atomic revenue distribution upon order completion:
  - 90% credited to Seller's earnings balance.
  - 10% credited to Bagoo Platform Commission Treasury.
  - Delivery Fee (₱50–₱60) credited to the Courier Rider's earnings ledger.

## Acceptance Criteria

### End-to-End Interconnection
- [ ] Placing an order as a Buyer immediately reflects in the Seller's orders table with accurate item details, SKU, and address.
- [ ] Seller marking an order "Ready for Pickup" automatically generates an unassigned delivery job on the Courier Dispatch Board.
- [ ] Courier claiming the job and updating status to "Delivered" updates the Buyer's tracking timeline to "Delivered" and increments Seller/Courier earnings ledgers.

### Fast-Forward Simulation
- [ ] Clicking "Fast-Forward" advances the order to the next valid stage and synchronizes status across database, seller view, courier view, and buyer tracking timeline.
- [ ] Fast-forwarding to "Delivered" triggers commission splitting and marks delivery complete.

### Location & Barcode Scanning Checkpoints
- [ ] Seller has a distinct "Approve & Pack" action.
- [ ] Courier has a distinct "Confirm Pickup / Scan Package" action.
- [ ] Courier has a distinct "Complete Delivery & Upload Proof" action.

### Admin KYC Approval Gate
- [ ] Newly registered user cannot access role dashboard while `pending_approval`.
- [ ] Admin can view pending applicants, inspect uploaded ID/permits, and click "Approve".
- [ ] Once approved by Admin, user can immediately log in and access their role dashboard.

</USER_REQUEST>
