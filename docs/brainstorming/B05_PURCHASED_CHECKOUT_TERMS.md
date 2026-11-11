# B05: checkout consistency and frozen purchased terms

Use [B04](B04_MONEY_AND_LIABILITY.md) for agreed money rules, [B03](B03_SHIPMENT_CUSTODY_PRIVACY.md) for identity/custody/privacy and Q1 for the accepted company hierarchy. This package covers original questions 1, 2, 26 and the measurement/voucher portion of 27. It preserves the current native/web payloads and existing checkout submission identity.

## Confirmation contract

1. Require the original eligible buyer, owned Bag, positive selected quantities, server prices, eligible seller/products, current stock and valid recipient/address. Reject forged totals and shipping amounts.
2. A successful submission replay returns its original shop orders before consulting newly changed prices, coverage or tariff. Changed address/company/quantity/payment/voucher intent cannot reuse that successful confirmation.
3. For a new purchase, recheck saved address/pin state and selected company coverage. Lock the current company and the union of the shops' ordered route hubs before final pricing. Keep the established owner/company/hub lock order.
4. Revalidate the original confirmation's prices/network, calculate the selected tariff and preserve a server-only logistics snapshot. Product measurements are separate from sale capacity reserved by this purchase.
5. Create each shop's independent order/waybill, original address/pin, measurements, tariff/version, route, original promotions and exact shipping earnings. Local child bypass remains Q1's one-hub route. Collection retains its zero doorstep pool.
6. Before committing the assembled orders, verify logistics sources again. A late tariff, coverage or measurement change rejects the entire checkout, restores stock/reservations and leaves the original confirmation usable for a corrected retry. No first-shop partial success survives failure in the second shop.
7. Persist one original submission/result and original promotion funding. Lost response/retry must not create a second order, charge, stock deduction, Wallet payment or voucher commitment.

## Frozen fields and versions

| Terms | Existing carrier |
|---|---|
| Buyer recipient/address and pin | Original order recipient/shipping/destination fields and delivery recipient/address/pickup snapshot |
| Tariff, packed units and weight computation | `Order.shipping_quote`: tariff version, base/included/additional cents, quantities/dimensions, actual/volumetric/billable grams and divisor |
| Company and ordered path | Original delivery company/hub IDs and `route_hub_ids`, plus Q1's route names/types and bypass indicator |
| Product price and promotions | Original order items/price segments, voucher redemptions, funded terms and order allocations; never current listing prices |
| Shipping entitlements | `earnings_terms_version=1`, `earnings_split_cents`, `payable_shipping_cents`, original model and delivery type |
| Supported refund contract | `purchased_terms_version=1`, `refund_policy_version=1`; absent version on old purchases retains the original contract |

The new default tariff is PHP 50 for 1 kg plus PHP 20 per started extra kilogram; explicitly configured company tariffs stay valid. Existing quotes are never repriced by that default. The selected successor refund policy remains a B04 decision until its B11–B13 consumers can honor it; do not substitute policy 1 for an unsupported version.

New fields are additive. An older mobile client can ignore them while the server retains and enforces the authoritative terms. No new API credential, external provider or browser/device interaction is required. Deploy the additive company-default migration normally; it changes PostgreSQL defaults for future company rows, not published company settings or existing purchases. SQLite test inserts use the explicit model defaults, preserving tables referenced by immutable-evidence triggers.

## Buyer and company review

Buyer checkout continues to refresh its quote when address, company, collection hub, quantity or voucher input changes; pending/stale totals cannot confirm. A changed source at confirmation returns a useful reload/review error. Exact successful retry preserves the original result even after settings change.

Company setup → Shipping & earnings retains the existing rate editor. Its example now calculates shares from the entered base fee and model, including cent remainders, instead of assuming PHP 60. Saving edits affects future quotes. Enter PHP 50, 1,000 included grams and PHP 20 extra kg to publish the selected baseline for an existing company; this delivery does not silently overwrite its approved tariff or reset data.

## Verification gate

Verify two shops, COD/Wallet, original-price/voucher allocations, flat/custom and weight-priced tariffs, exact started-kilogram boundaries, bulky/quantity aggregation, collection, missing packed measurements, stale company/network/dimensions, late-write rollback, same-token correction and exact successful replay. Include timed/percentage sale regression so the final logistics guard does not reject reservations consumed by its own purchase. Physical concurrency on PostgreSQL and device workflows require separate evidence; isolated SQLite checks do not prove those.

Record actual results, commits, remaining limits and publication status in the roadmap. B06 and later operations retain their own acceptance; this package does not create new cancellation, compensation or return-payment actions.
