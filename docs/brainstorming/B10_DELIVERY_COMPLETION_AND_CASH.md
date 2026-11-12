# B10: Delivery completion, earnings and COD reconciliation

This package verifies topics 8, 12 and 25 with B04/B05 original money terms and B09 dispatch. It retains the existing version-two automatic completion model. It does not activate B11–B13 successor refunds, new compensation funding or actual bank transfers.

## Original handover and payment

Completion locks and reloads the saved order and parcel, then fetches the proof from that parcel's own immutable checkpoints. An unsaved proof, changed in-memory parcel, another parcel's proof or a different operator cannot establish delivery. Successful replay must identify the same original proof and actor; it creates no extra completion, earnings or Wallet settlement.

Doorstep evidence must retain the original waybill, courier actor, prior courier custody, out-for-delivery source, recipient custody and private proof image reference. It also needs the saved original COD collection or prepaid Wallet handover receipt. A `paid` flag alone is not payment evidence. Counter collection instead retains its consumed buyer claim, named counter operator and matching collection event; COD additionally has its original cash account and collection, while Wallet retains its verified original payment and collects no cash.

The parcel's recorded Delivered/Customer collected state, order payment and evidence must agree before completion. Completion, eligible earnings, collection and virtual settlement share the caller's transaction. A failure rolls back the handover and cash/completion writes together; the original failed request can then retry. Historical version-one orders retain buyer confirmation and their original settlement/reconciliation gates.

## Separate money boundaries

- Product proceeds retain the original 90% seller / 10% platform split with funded promotion adjustments. They are separate from the purchased shipping pool.
- Pickup earnings require actual origin receipt. Company and final-mile earnings require valid delivery; integer-cent allocations use the purchased quote rather than current company settings.
- COD delivery-rider release requires counted hub receipt. Offered cash, short receipts, outstanding balances/excess and unresolved differences cannot release that share or permit duty closure.
- Cash-release retries reload the saved event and lock the current cash account. Caller-modified or older event snapshots cannot hide a current discrepancy. Existing earning/event identity prevents double release.
- Prepaid Wallet delivery creates no COD collection. Virtual proceeds use the original balanced journal; they are not bank or cash payouts.
- Hub collection retains its zero purchased doorstep charge and creates no invented final-mile rider share.
- Released earnings are recorded entitlements. Actual seller/courier payments retain their separate authorization, reference and proof requirements. A delivery label is not a payout receipt.

Existing shift checks retain event-ID boundaries between consecutive shifts, counted receipts, pending offers, reviewed shortfall recovery and carried obligations. Original earnings/history and protected carrier entitlements remain intact for later returns.

## Visible changes and delivery

Rider **Trips** and company **Delivery earnings** show the original earning receipt reference beside its work/share. The rider still sees released versus awaiting hub receipt amounts, current COD hold/differences and the **COD cash handovers** link. Company cards and desktop rows retain the same source reference. The old generic trip warning no longer suggests version-two automatic completion is absent; cash handovers and payouts remain separate receipts.

No new schema, API provider, configuration key, reset or reseed is needed. The additive B06/B08 prerequisite migrations must already be applied through normal deployment. Verification, scoped assessment and remaining acceptance limits are recorded in [the roadmap](../CORE_FLOW_ROADMAP.md). Follow [the review guide](MANUAL_REVIEW_B09_B10.md) using existing test accounts; it does not mark pending B01–B08 manual acceptance complete.
