# Buyer Module Flow

This document defines buyer actions. Parcel custody, hub transfers, COD, and exceptions follow `docs/SORTING_CENTER_LOGISTICS_FLOW.md`. Input, ownership, duplicate-submission, and recovery rules follow `docs/CORE_FLOW_VALIDATION_AND_EDGE_CASES.md`.

## 1. Account and Shopping

```text
Register with a required birth date proving age 18 or older
-> Sign in and browse the marketplace
-> View the buyer application modal and submit identity requirements
-> Platform Admin approves account before checkout
-> Browse or search the 14 master categories
-> Select product variants and quantity within stock
-> Add to Bag
-> Review Shopping Bag and voucher
-> Checkout
```

Public catalogue browsing does not grant checkout access. Pending or rejected buyers enter the marketplace with their own dismissible application modal, styled like the buyer pages. They may view their profile, owned order history and Shopping Bag, and add or change Bag items while the account is active or awaiting approval. An empty history explains that no orders have been placed. Checkout and other protected purchasing actions require an active approved account. Email verification and uploading an ID do not replace the review in [ADMIN_FLOW.md](ADMIN_FLOW.md).

Birth date is required for password and first Google signup, including direct submissions and registration preflight. The buyer must have reached their eighteenth birthday on the server's Philippine calendar. Missing, impossible, future and underage dates are rejected before account creation. The server derives age; a supplied age cannot override it. Existing reviewed identity remains subject to the recorded correction process rather than an invented replacement date.

Suspension blocks new orders and Bag changes. Personal pages and owned history remain readable, and required receipt confirmation for previously approved buyers remains available through the narrow authorized exception unless an explicit security review restricts it. Reading history as an unapproved applicant does not grant receipt confirmation or private pickup-code access. This never permits foreign-order access, premature completion, or new checkout. Approval or document resubmission cannot silently clear a suspension.

Checkout requires the recipient, phone, serviceable road-based address, barangay, delivery type, and COD confirmation. Adding to the Shopping Bag does not reserve stock; checkout validates and decrements it atomically.

If selected items belong to multiple shops, checkout creates a separate order, parcel, tracking number, shipping fee, and seller pickup route per shop. The buyer may see them under one checkout result, but their fulfillment and delivery timelines remain independent. A shop voucher reduces only its matching shop order; a platform voucher is divided proportionally without exceeding its calculated checkout discount.

Successful checkout and My Purchases links open the existing purchases and tracking workspace, with item details and parcel actions. Restricted buyers retain only their owned recorded-order view under the exception above.

Account settings keep the original sign-in email and allow verified additional contact/recovery addresses with current-password confirmation and an emailed code. Buyers edit contact numbers and profile photos directly; address changes require an active approved account. Saving a photo updates the profile and header from the saved result. Addresses apply to future orders; existing order snapshots remain intact. Present recorded first, middle and last names separately; never guess missing components from a legacy combined name. Legal name, birth date and private identity evidence use the correction request and history beside identity information in settings, with Platform Admin review. Unreviewed buyers correct their application in the marketplace modal.

### Delivery address book

An active approved buyer may save up to **10** delivery addresses. Recipient name
is an editable delivery contact, separate from the account's reviewed legal
identity. Require recipient, Philippine mobile number, region, province/area,
city/municipality, barangay and meaningful street details. Postal code is optional
in the address book and must contain four digits when supplied; checkout retains
its own required postal-code rule.

Location choices follow their selected parents. Changing a region, province/area
or city clears its descendants, and direct submissions must satisfy the same
hierarchy. Keep entered contact and street details when location loading fails;
show a retry action and wait for usable choices before saving. NCR uses Metro
Manila as its area. Geographic validity does not promise an available delivery
route.

The first saved address is default. Adding another preserves that default unless
the buyer explicitly chooses a replacement. Changing the default leaves exactly
one selected, and checkout preselects its saved recipient and location. A default
address cannot be deleted, including when it is the only address: choose or add
another default first. Existing addresses remain editable when the book is full;
checkout can use a new address without saving it.

Read, edit, default and delete operations must remain scoped to the owning buyer.
Address changes never update an existing order, parcel or waybill. Printed
recipient details use the order snapshot, including barangay, province and postal
code; missing historical values must not be replaced by current profile data.

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

An active approved buyer may cancel their own unpaid COD order before
`READY_FOR_PICKUP`, with a reason and details when selecting Other reason. A rider
claim, recorded handover, handover hold or recorded payment blocks this action.
The order transaction restores the selected inventory and releases only its
original voucher funding once. Other orders and retained redemption usage remain
unchanged. Original delivery notes are retained; the cancellation has its own
recorded reason and time. Online-paid legacy orders require payment support;
cancellation does not imply a refund.

An original buyer may request a return for purchased units through exactly seven
elapsed days after evidenced physical delivery or counter collection, including
after confirming receipt. Follow the [selected return contract](buyer-returns-plan/README.md)
for bounded private photos, meaningful reasons, seller response and admin appeal.
A case retains the original purchase, delivery and completion. Approval, physical
return, recorded repayment and the buyer's confirmation of money are distinct
steps; no case or parcel label proves that a refund was paid.

After buyer-confirmed completion, each purchased item may receive one verified
review with integer stars from one to five, text up to 1,000 characters and up to
five JPG, PNG or WEBP photos of 5 MB each. The buyer may edit rating and text once
within 30 days of posting, retaining the original content privately and keeping
the existing photos. An unchanged successful edit retry is safe. Public reviews
show masked buyer names and use verified saved ratings for averages and counts.

Buyer–seller messages retain their actual product or owned-order context and
allow text up to 1,000 characters plus one readable JPG, PNG or WEBP image up to
5 MB. Images are private to the conversation participants. Failed sends preserve
the draft; retrying the same send request cannot create another message or file.
Opening conversation history acknowledges only the owned messages actually
returned in that snapshot. Newer unseen messages remain unread.

## 3. Hub Self-Pickup

The parcel still travels through the origin Bayan Hub, at least one Mother Hub, and destination Bayan Hub. When ready, the buyer receives the hub address, operating hours, seven-day expiry, and a link to private one-time claim-code access. The code is shown only through the owned order action and never included in a notification. Reminders are sent on days three and six.

At the counter, the buyer presents the claim code and identity confirmation and pays COD if required. Collection maps the order to `DELIVERED`; the buyer then confirms receipt to complete it.

## 4. Failure and Return

If delivery fails, the buyer sees the reason and whether the parcel is awaiting address clarification, rescheduled, or returning to the seller. The buyer may provide corrected directions but cannot directly change delivery status or assign a retry rider.

Three total delivery attempts are allowed. Attempts one and two may be scheduled for retry by the destination hub. After the third failure, the buyer sees reverse-hub checkpoints until the seller receives the returned parcel and the order becomes `RETURNED`.

## 5. Required Buyer Notifications

The header bell opens a compact recent-updates panel. Highlight unread updates and acknowledge only the owned records displayed in that panel; newer or undisplayed records remain unread. Tapping an update also acknowledges it and navigates with Inertia. Approval updates open the buyer's account/application context with visible feedback rather than an unexplained marketplace redirect. The complete notification list retains the buyer header, navigation and visual style. Failed loading or acknowledgement provides a retry message without claiming success.

- Seller confirmation and preparation milestones.
- Pickup and tracking availability.
- Out-for-delivery reminder and exact COD amount.
- Delivery failure reason and required buyer action.
- Self-pickup claim code, reminders, and expiry.
- Delivered prompt to confirm receipt.
- Return-to-sender progress and result.

## 6. Implementation Status

Current buyer gaps and their approved delivery phase are tracked only in `docs/CORE_FLOW_ROADMAP.md`.
