# V01: Honest Offers and Accurate Quotes

The Shopping Bag, checkout preview and persisted orders must explain the same
discount for the same selected items. This first batch keeps the existing
single-voucher request while repairing the preview and removing fabricated
offers. It does not introduce saving, stacking or campaign accounting.

## Inspect and reuse

- [Voucher model](../../app/Models/Voucher.php) and
  [buyer apply controller](../../app/Http/Controllers/Buyer/VoucherController.php).
- [Checkout controller](../../app/Http/Controllers/Buyer/CheckoutController.php),
  [order service](../../app/Services/Orders/CheckoutOrderService.php) and
  [submission service](../../app/Services/Orders/CheckoutSubmissionService.php).
- [Checkout page](../../resources/js/Pages/Checkout/Index.tsx),
  [Shopping Bag](../../resources/js/Pages/Cart/Index.tsx) and
  [buyer profile](../../resources/js/Pages/Buyer/Profile.tsx).

## Bounded change

Create one server-owned quote path used by preview and final checkout. Its
context is the owned Bag, selected item IDs, current products and quantities,
approved shops, delivery type, address/routing inputs and voucher selection.
Never accept prices, discounts, scope or shipping amounts from the client.
The unlocked preview is informational; final checkout rechecks under the
existing transaction and locks.

Calculate fees per shop/parcel using existing delivery policy. A shop voucher
qualifies only against its own selected merchandise. A platform product offer
allocates proportionally to merchandise; a shipping offer allocates against
actual eligible shipping fees. Allocate exact cents with stable ordering and
capacity bounds. Zero-fee shops receive no shipping discount.

Return a bounded public offer view with real scope, validity, remaining-use
eligibility, discount and a specific unavailability reason. Avoid returning
the entire administrative voucher record. Unrelated, expired, paused and
exhausted offers cannot appear applied. Use current selected items rather than
the entire Bag to calculate minimum spend.

Replace the hardcoded profile cards and badge with genuine publicly available
offers, clearly called available offers until saved records exist. Center the
honest empty state when there are none. Keep the current checkout selector;
its selection uses the server quote rather than a second JavaScript formula.
Retain optional code entry and removal.

## Acceptance and stopping point

- Single-shop and multi-shop previews match persisted order totals to the cent.
- A shipping cap leaves the unpaid shipping amount visible; hub self-pickup and
  naturally zero-fee parcels receive no artificial merchandise reduction.
- Changing selected items, address or delivery type refreshes eligibility.
- Minimum spend, ownership, quota and expiry errors describe the actual reason.
- No seeded-looking profile promotion or fixed count appears without records.
- Existing signed submission replay, stock rollback and once-per-checkout
  voucher counting remain intact.
- Relevant isolated SQLite tests and existing frontend checks pass;
  TypeScript/Vite build passes.

Record scoped before/after findings and actual verification in the roadmap.
Stop here: truthful selection does not mean saving, stacking, funding budgets
or new admin campaign controls are implemented.
