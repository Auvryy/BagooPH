# Administrator Governance Flow

Platform Admin governs marketplace access, compliance, disputes, commissions, and financial audit. Logistics Company Admin manages its own facilities, personnel, manifests, exceptions, and operational COD remittance. These authorities must remain separate.

## 1. Platform Admin Responsibilities

- Approve, reject, suspend, or reactivate buyer, seller, courier, and logistics-company applications.
- Review seller category and product compliance.
- Audit orders, waybill checkpoints, disputes, COD reconciliation, commissions, and payouts.
- Resolve disputes using order, parcel, proof, cash, and message evidence.
- Manage platform policies and announcements.
- Prevent cross-company logistics data access.

Platform Admin does not perform routine parcel scans or silently change custody and cash history. Corrections must be auditable adjustments.

## 2. Logistics Company Admin Responsibilities

- Manage its Mother Hubs and Bayan Hubs.
- Create and scope hub-handler access.
- Approve or assign active riders to hubs and barangays within its company.
- Manage vehicles and road-based manifests.
- Monitor parcels, failed delivery, retries, and return-to-sender.
- Reconcile rider and counter COD remittances before platform handoff.
- Configure supported road zones and shipping rates when those modules are implemented.

## 3. Commission and Payout Governance

- Commission base is the order product subtotal.
- Platform commission is 10%; seller share is 90%.
- Shipping and handling are tracked separately.
- Payout eligibility requires order `COMPLETED` and platform-level COD reconciliation.
- Rider earnings and logistics revenue do not reduce or merge into the seller/product split.
- Refunds, reversals, shortages, and corrections use traceable adjustment records.

## 4. Dispute Evidence

Admin review may include account documents, order items, waybill and hub checkpoints, manifest history, rider assignment, delivery proof, failure reasons, COD custody records, buyer confirmation, and role messages.

Resolution may approve a refund, seller payout, return, account penalty, or further investigation. A resolution cannot erase the original audit trail.

## 5. Remaining Admin Work

- Connect logistics-company approval and tenant isolation to the main KYC workflow.
- Add COD reconciliation and payout approval views.
- Add complete parcel exception and RTS audit views.
- Add immutable financial adjustments and discrepancy handling.
- Clarify refund rules for cancellation, failed delivery, and returned orders.
