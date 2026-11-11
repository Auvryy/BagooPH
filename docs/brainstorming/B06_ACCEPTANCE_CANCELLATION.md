# B06 — Acceptance, cancellation and recipient disclosure

The user selected direct cancellation before acceptance, seller review after acceptance, and private recipient details until acceptance. This contract changes source topics 6 and 11–13 without adopting their automatic fines, timed auto-cancellation, shared-key encrypted QR payloads or unsupported refund promises. B03 routing/custody and B04–B05 purchased money remain authoritative.

## Purchased cancellation policy

New checkout quotes use `cancellation_policy_version: 2` when both additive cancellation tables exist. Policy 2 is independent of logistics flow version 2 and the still-active refund policy 1. Original purchases lacking this cancellation field, or explicitly carrying 1, retain direct buyer cancellation before readiness. Unknown versions require review. An unsubmitted confirmation must refresh when policy 2 activates; an already successful checkout replays its original purchase without repricing or duplicating reservations.

| Original order state | Policy 2 buyer action | Seller action / outcome |
|---|---|---|
| Placed / pending, before acceptance | Direct cancellation with allowlisted reason | Original stock/funding released once; evidenced Wallet payment reversed once |
| Confirmed / accepted | Request cancellation | Review within 24 hours; preparation and pickup held while pending |
| Preparing / packed | Request cancellation | Same review boundary; printing alone is not readiness |
| Ready for pickup | No buyer cancellation or request | Seller may cancel only while no claim, handover, cash account or hold prevents it |
| Rider claimed or recorded courier custody | No cancellation | Continue delivery or the separately authorized return/recovery flow |
| Cancelled | Saved outcome only | Retry retains original inventory, evidence and payment outcome |

Current account, shop ownership, payment evidence, handover hold and retained custody are rechecked inside the transaction. Mutable unassigned delivery fields cannot override a retained non-seller custody checkpoint. All preparation and cancellation writers share the order-then-parcel lock order used by rider pickup claims. Tests cover both serial outcomes; simultaneous PostgreSQL concurrency remains a separate check.

## Request and decision evidence

A buyer request uses an allowlisted reason, up to 500 characters of notes (required for Other reason), and a UUID retry token. Original buyer/seller identities, request reference, reason, source order/custody snapshot and response deadline are retained. There is at most one active request per order. Requests do not cancel orders or move stock, vouchers or money.

The original eligible seller reviews a specific request/version with an explicit approval or decline and a 10–1,000-character reason. A current source snapshot, including the last original custody checkpoint, must still match. Approval performs the existing cancellation atomically with its decision: stock, sale reservations, voucher funding and original Wallet reversal are handled once. Decline records the reason and leaves the order active. A later request gets a new reference and token; an old token replays its own saved request, even when a newer request exists.

At exactly `respond_by`, approval is rejected. Read-only presentation shows expiry immediately without database writes. The scheduled `orders:expire-cancellation-requests` command records expiry and releases the request slot; it never cancels the order, refunds payment or restores inventory. Seller preparation can also close an already expired request within its existing transaction. Both tables retain immutable original request fields and append-only event history, protected by model hooks and SQLite/PostgreSQL triggers. Rollback refuses to discard recorded request history.

Buyer and seller receive deduplicated in-app notices using the original participants. Notices carry the order/request references and generic outcome; private address, phone and decision details are read from the authorized order page. Duplicate submission guards supplement backend token/version checks. Validation or uncertain responses retain typed details and the same token. A confirmed success rotates the token.

## Recipient disclosure and page feedback

Before acceptance, the seller response omits recipient street address, phone, postal code, barangay, map pin, delivery notes and tracking barcode. Items, recipient name and city/province remain available for review. The waybill/QR buttons and acceptance preview cannot produce a label before acceptance. Acceptance reveals the original purchased recipient snapshot, never the buyer's later profile edits. Unaccepted cancelled orders remain private. Redaction affects response models only and never saves over the original data.

Buyer order details show direct/request availability, pending deadline, saved decision, expiry and the actual Wallet refund reference/amount when one exists. Seller orders show preparation feedback, request review controls and the same recorded payment outcome. JSON callers receive structured saved status or field/conflict feedback. No response equates cancellation with physical return, COD repayment or a new liability payment.

## Rollout and acceptance

Apply the normal additive database migration before expecting policy 2 on new purchases. Run the normal scheduler for durable expiry events. Before those tables exist, checkout retains policy 1 and the expiry command safely does no work. This branch does not reset or seed the development database, apply the migration there, deploy, change mobile repositories or test a phone.

Automated evidence and limitations are recorded in [the roadmap](../CORE_FLOW_ROADMAP.md). The user's B01–B06 manual review remains pending; [the later-review guide](MANUAL_REVIEW_B01_B06.md) groups those checks without treating automation as user acceptance.
