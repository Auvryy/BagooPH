# BS05: Buyer Buy Again

## Outcome and dependency

Follow BS03 and BS04. Rebuild an editable Shopping Bag from an owned completed
purchase using current catalogue/inventory rules, not historical prices.

## Selected behavior

- The completed purchase's Buy again button adds its original variants and
  quantities directly to the existing Shopping Bag at current prices. The user
  selected this simpler flow on October 19; the Bag supplies quantity editing,
  item selection and checkout review instead of a duplicate preview page.
- Increase an existing matching product/color/size line; keep other variants
  separate. Open the normal Bag with the affected available owned lines selected.
  Do not select unrelated or foreign lines. Never silently substitute variants.
- Revalidate all original rows using shared cart/inventory rules inside one
  transaction. If any row fails, add none and return clear feedback beside the
  action. The buyer can then edit their existing Bag or visit the current product.
- Persist a buyer-owned request token and original result so an identical retry
  adds items once; changed reuse rejects. Merge existing matching Bag lines under
  ordinary quantity limits without reserving/decrementing stock.
- GET requests and old preview bookmarks never add items. Owned old bookmarks
  return to the purchase. Adding remains an explicit authenticated POST.
- Use normal checkout for addresses, vouchers, shipping, payment selection and
  actual order creation. Preserve the original completed order and snapshots.

## Acceptance and exclusions

Test foreign/incomplete orders, price changes, removed variants, sold-out or
restricted products, existing Bag quantities, atomic rollback, identical and
changed retries, and a subsequent ordinary checkout. Run backend/frontend
regressions and a build. No automatic repeat orders, subscriptions, copied old
shipping/voucher totals or new payment flow. Record evidence and hand off.
