# Advanced shopping delivery

This delivery builds the five remaining shopping features after the three buyer
acceptance batches. It preserves the approved category scope, COD, 10% platform
commission, buyer-confirmed completion and the canonical parcel route.

## Purchasing and stock

- Vacation mode keeps approved listings visible with a clear label and blocks
  new Bag additions, Buy Now and checkout. Existing orders remain actionable.
  The sole shop's seller controls the setting; it does not change approval.
- A seller may set a whole-number product limit from 1 to 1,000,000 or leave it
  blank. The limit counts units across that buyer's non-cancelled orders for the
  product, across all options and sessions. Early cancellation restores the
  allowance once. Delivery returns retain the purchase count. Bag lines do not
  reserve an allowance; final checkout rechecks it under product locks.
- Independent combination stock is an explicit seller setting. Every configured
  color/size pair has a stock count and an optional surcharge. Product stock is
  the sum of those rows. Legacy size stock remains supported until the seller
  deliberately converts it. Existing order prices and selected options stay
  unchanged. Options needed for orders in progress must remain restorable.

## Sales and reminders

- Sellers schedule fixed base prices for their own products in Asia/Manila.
  Option surcharges apply on top of the sale base price. A store sale and a flash
  slot may coexist; schedules of the same kind cannot overlap for one product.
  Store sale prices can change for new purchases; End now stops new use.
- Flash prices take priority while their saved quantity remains. Checkout splits
  promotional units and remaining units into separate priced lines. Remaining
  units use the current store sale or regular price. Stock and quota are checked
  together; an order keeps its original prices. Early cancellation restores only
  its recorded promotional allocation, once. It does not extend the sale window.
- Sale reductions are seller funded: merchandise totals use the actual charged
  prices, and vouchers evaluate those totals. Platform voucher funding and
  shipping accounting keep their existing separate rules.
- Buyers can subscribe to a future flash slot and remove their reminder. The
  existing scheduler creates one owned notification shortly before the start,
  with a product link. Missed or ended slots must not create misleading notices.
  Starting a sale does not require a browser countdown to be running.

## Verification

Check valid, invalid, boundary, ownership, stale-price, retry, cancellation and
rollback behavior using isolated SQLite tests. Check buyer/seller interaction
helpers and build the frontend. Record actual results and scoped ratings in the
roadmap. Deployment must apply additive migrations and run the scheduler; local
checks do not establish a deployed notification or simultaneous PostgreSQL run.
