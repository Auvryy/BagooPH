# Buyer Module Flow

This document defines buyer actions. Parcel custody, hub transfers, COD, and exceptions follow `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Input, ownership, duplicate-submission, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Account and Shopping

```text
Register and submit identity requirements
-> Platform Admin approves account
-> Sign in
-> Browse or search the 14 master categories
-> Select product variants and quantity within stock
-> Add to Bag
-> Review Shopping Bag and voucher
-> Checkout
```

Public catalogue browsing does not grant transactional buyer access. Pending or rejected applicants may sign in to their own approval/resubmission holding screen; checkout and the buyer transactional portal require an active approved account. Email verification and uploading an ID do not replace the review in [ADMIN_FLOW.md](ADMIN_FLOW.md).

Suspension blocks new orders. Owned tracking and required receipt confirmation for existing orders remain available through the narrow authorized exception unless an explicit security review restricts them. This never permits foreign-order access, premature completion, or new checkout. Approval or document resubmission cannot silently clear a suspension.

Checkout requires the recipient, phone, serviceable road-based address, barangay, delivery type, and COD confirmation. Adding to the Shopping Bag does not reserve stock; checkout validates and decrements it atomically.

If selected items belong to multiple shops, checkout creates a separate order, parcel, tracking number, shipping fee, and seller pickup route per shop. The buyer may see them under one checkout result, but their fulfillment and delivery timelines remain independent. A shop voucher reduces only its matching shop order; a platform voucher is divided proportionally without exceeding its calculated checkout discount.

## 2. Doorstep Delivery

```text
PLACED
-> Seller CONFIRMED
-> PREPARING
-> READY_FOR_PICKUP
-> PICKED_UP
-> AT_SORTING_CENTER
-> SORTED
-> ASSIGNED_TO_RIDER
-> OUT_FOR_DELIVERY
-> DELIVERED
-> Buyer confirms receipt
-> COMPLETED
```

The buyer tracking page shows Mother-Hub and Bayan-Hub checkpoints beneath `AT_SORTING_CENTER`. The buyer cannot choose or assign riders.

At `OUT_FOR_DELIVERY`, the buyer sees the COD amount and delivery reminder. After physical handover, the order is `DELIVERED`. Only the buyer's confirmation makes it `COMPLETED`; final settlement also requires platform-level COD reconciliation.

## 3. Hub Self-Pickup

The parcel still travels through the origin Bayan Hub, at least one Mother Hub, and destination Bayan Hub. When ready, the buyer receives the hub address, operating hours, seven-day expiry, and a link to private one-time claim-code access. The code is shown only through the owned order action and never included in a notification. Reminders are sent on days three and six.

At the counter, the buyer presents the claim code and identity confirmation and pays COD if required. Collection maps the order to `DELIVERED`; the buyer then confirms receipt to complete it.

## 4. Failure and Return

If delivery fails, the buyer sees the reason and whether the parcel is awaiting address clarification, rescheduled, or returning to the seller. The buyer may provide corrected directions but cannot directly change delivery status or assign a retry rider.

Three total delivery attempts are allowed. Attempts one and two may be scheduled for retry by the destination hub. After the third failure, the buyer sees reverse-hub checkpoints until the seller receives the returned parcel and the order becomes `RETURNED`.

## 5. Required Buyer Notifications

- Seller confirmation and preparation milestones.
- Pickup and tracking availability.
- Out-for-delivery reminder and exact COD amount.
- Delivery failure reason and required buyer action.
- Self-pickup claim code, reminders, and expiry.
- Delivered prompt to confirm receipt.
- Return-to-sender progress and result.

## 6. Implementation Status

Current buyer gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.
