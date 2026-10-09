# BS05: Buyer Buy Again

## Outcome and dependency

Follow BS03 and BS04. Rebuild an editable Shopping Bag from an owned completed
purchase using current catalogue/inventory rules, not historical prices.

## Planned work

- Add an owned Buy again preview and confirmation route for completed orders.
  Preview original variants/quantities against current prices and availability.
- Let the buyer select eligible items and explain unavailable listings/changed
  variants. Never silently choose another variant or purchase method.
- Revalidate all selected rows using shared cart/inventory rules inside one
  transaction. If any selected row fails, add none and return clear feedback.
- Persist a buyer-owned request token and original result so an identical retry
  adds items once; changed reuse rejects. Merge existing matching Bag lines under
  ordinary quantity limits without reserving/decrementing stock.
- Use normal checkout for addresses, vouchers, shipping, payment selection and
  actual order creation. Preserve the original completed order and snapshots.

## Acceptance and exclusions

Test foreign/incomplete orders, price changes, removed variants, sold-out or
restricted products, existing Bag quantities, atomic rollback, identical and
changed retries, and a subsequent ordinary checkout. Run backend/frontend
regressions and a build. No automatic repeat orders, subscriptions, copied old
shipping/voucher totals or new payment flow. Record evidence and hand off.
