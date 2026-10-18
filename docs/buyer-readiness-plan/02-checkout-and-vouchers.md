# Batch 2: Vouchers, checkout and supported payment

Branch: `fix/buyer-checkout-and-voucher-acceptance`.
Allocation: B7 (12), B8 (11), B9 (7): **30 scenarios**.
Dependency: accepted batch 1 and the merged saved-address rules. Follow the
[shared plan](README.md), current voucher funding and financial contracts.

## Outcome

Selected Bag lines become exactly the intended per-shop COD orders. The buyer
sees the authoritative merchandise, shipping, discounts and amount to collect;
invalid input or a failed checkout leaves stock, Bag, voucher use and retry state
consistent. Supported payment behavior is explicit.

## Vouchers

- Claim/save an actual offer once, keep its real terms/end date and accurately
  reflect availability, saved state and per-buyer usage across sign-ins.
- Apply a shop offer only to its shop, and replace a previous choice within that
  slot. Preserve deliberate No voucher choices through quote refreshes.
- Check minimum spend at, below and above the boundary, finite percentage caps,
  current paid prices, global/per-buyer usage and current funding availability.
- Distinguish invalid codes, offers not yet active, expiry, exhausted usage and
  ineligible selection. Case normalization must agree with the documented code
  rule; do not silently invent a different identifier policy.
- Removing items or changing a quote must invalidate an ineligible selection with
  a clear notice. Shipping reductions affect shipping alone, never make totals
  negative and cannot reduce the seller's merchandise share accidentally.
- Assess simultaneous shop, platform merchandise and platform shipping offers
  against the current one-Bagoo-offer rule before expanding slots. Reuse the
  existing budgets, allocation records and funding gates for any approved change.
- Cancellation reuse must account for original redemption, expiry and surviving
  sibling orders. Restoring a discount cannot duplicate funding, move it to
  another order or silently grant another buyer an extra use.

## Checkout

- Reconcile each shop's items, shipping, discounts and COD payable with the
  combined result, saved orders and seller receipt. Retain current 10% commission,
  separate shipping and the documented funding responsibilities.
- Use the saved default's actual recipient and location; changing the selected
  address refreshes applicable quotes and keeps the merged address safeguards.
- Validate serviceability from the actual permitted hub/road route. A selectable
  geographic address is not automatically serviceable. Handle the error before
  submission where possible and independently reject it on the server.
- Weight/region tariffs require an agreed replacement for the current shipping
  policy. Do not introduce maritime coverage or substitute external sample fees.
- Resolve the note-to-seller limit and confirm the complete saved value reaches
  the seller. Keep required inputs, COD selection/acknowledgement and accessible
  field feedback aligned with server validation.
- A stale product price, depleted stock or newly invalid voucher must trigger
  fresh visible totals and necessary confirmation, with no hidden overcharge.
  Client prices, totals and ownership never determine the saved amount.
- Duplicate clicks, Back/resubmit and safe retries return the original orders,
  with one stock deduction and voucher consumption. A failed multi-shop write
  rolls back the whole checkout, including optional address saving.

## Payment boundary

COD is the current implementation. Verify the displayed collection amount and
unpaid-until-collected accounting; reject unsupported card/wallet requests
clearly instead of implying successful online payment.

Gateway success/failure/cancel callbacks, closed-browser reconciliation,
retry-to-pay and unpaid-payment expiry are new payment work. They need a selected
provider or an explicitly isolated provider test boundary, signed callback and
amount verification, replay protection, stock/refund policy and separate scope.
Neither a fake paid reference nor a generic success response passes those cases.
Keep time-driven order cancellation outside the baseline unless explicitly
changed; no new external or paid service is authorized by this plan.

## Acceptance and stopping point

Use `BuyerVoucherQuoteTest`, `BuyerVoucherSelectionTest`,
`VoucherFundingFoundationTest`, `BuyerCheckoutTest`, `CheckoutReplayTest`,
`InventoryCheckoutTest`, `CommerceInputValidationTest` and `BuyerAddressBookTest`
for affected behavior. Keep money comparisons in cents and test rollback,
ownership and repeat requests. Run relevant frontend quote/selection checks and
the production build after frontend changes.

Hand batch 3 real owned order and receipt evidence. Record a separate disposition
for every unsupported payment or unresolved tariff/voucher policy; a passing COD
checkout is not acceptance of all 30 scenarios. Do not merge an unresolved money
or stock defect merely to meet the preparation window.
