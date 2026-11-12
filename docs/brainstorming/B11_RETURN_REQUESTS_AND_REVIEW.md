# B11: return requests and independent decisions

This implements the return-review package in [the build order](BUILD_ORDER.md).
[B04](B04_MONEY_AND_LIABILITY.md) owns refund policy; a finding here is not a
payment or a physical handover. Execution evidence belongs in
[the roadmap](../CORE_FLOW_ROADMAP.md).

## Request and decision boundaries

A buyer requests a return against their own originally purchased units within
seven elapsed days of the persisted delivery or hub-collection receipt. For a
version-two logistics journey, the saved B10 recipient/payment evidence is also
required. The request does not change the successful original delivery.

The eligible reasons remain damaged, wrong item, missing parts and not as
described. Voluntary change of mind after delivery is excluded. Description:
10–1,000 characters. Attach one to five private JPG, PNG or WEBP images, each up
to 5 MB. Server-side image inspection, ownership and original quantities remain
authoritative. Claims identify stable original unit positions; overlapping and
already adjusted units cannot be claimed again.

The seller has three elapsed days to approve or reject. A buyer can escalate an
overdue request or appeal a rejection within three days. An independent active,
verified admin resolves escalated requests with a reason. Seller rejection
reasons are 10–300 characters; other decision reasons are 10–1,000 characters.
Expiry does not silently approve a return or send money. Withdrawal/eligible
expiry releases unfulfilled unit claims; retained decisions and money evidence
remain unchanged. Automatic scheduling belongs to B14.

Approval authorizes a separate return barcode with the buyer still holding the
parcel. The buyer has seven elapsed days after approval to present it at the
original destination Bayan Hub. Only the actual intake, scanned reverse legs
and evidenced seller receipt establish physical progress. Failed-delivery RTS
uses its original separate workflow.

## Fault findings

Sellers can acknowledge seller/item fault when approving a request. They cannot
assign courier liability. An independent active, verified admin can record or
revise a reasoned fault finding after authorization and before any repayment
basis has been approved. The immutable event history retains earlier findings.
Reviewing fault does not repeat authorization, extend drop-off time, change
custody or transfer funds.

Courier findings require an explicit account of the actual transit or handover
evidence, 10–1,000 characters. The finding retains the original company,
delivery checkpoint and private case evidence identifiers. The most recent
scanner is not automatically liable. The recorded finding is visible to the
buyer, seller and admin; logistics access does not expose the financial review.

Historical refund-policy-one approvals may retain an unspecified finding because
their purchased merchandise-only policy remains valid. Successor policy two
requires an explicit supported seller/courier finding. Checkout activation and
funded item-plus-shipping execution remain B13 prerequisites; this review change
does not activate successor purchases.

## Retry, conflicts and manual review

Each decision retains the actor, current case version, original request token,
fingerprint, reason and resulting state. Exact retries return the original
event. Changed details with an old token, foreign actors and stale versions
cannot overwrite another decision. The form keeps failed input for correction,
clears irrelevant fault fields when changing actions and prevents repeated
submission while a request is pending.

Open `/buyer-returns` as the seller, then the requested case: choose **Approve return**,
select seller/item fault and submit a bounded reason. Confirm that its return
barcode is awaiting drop-off and no repayment appears. For an authorized case,
open **Admin → Deliveries & returns → Buyer returns → the same case**, choose **Review fault and liability**,
select a supported finding and identify the actual evidence. Confirm that the
earlier approval remains in history. Recheck from the buyer's return page.
These are manual acceptance instructions, not claims that user acceptance ran.
