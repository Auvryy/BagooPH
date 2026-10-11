# V04: Buyer Saving and Voucher Selection

Buyers should understand an offer without remembering a code or doing mental
arithmetic. Build on V02's authoritative quote and V03's real public campaigns.
Reuse the [buyer profile](../../resources/js/Pages/Buyer/Profile.tsx),
[Shopping Bag](../../resources/js/Pages/Cart/Index.tsx) and
[checkout](../../resources/js/Pages/Checkout/Index.tsx).

## Discovery and saving

Use the existing My Vouchers & Promos panel for available and saved offers,
with used and expired history from actual records. Scope each tab's count
to its real query; a saved offer may now be unavailable. Archived campaigns
with prior saves remain explainable rather than disappearing as broken data.

Show a shop's available offers near its existing product/shop content and
its selected Bag group. Platform offers appear in the profile and checkout
platform section. The card explains issuer/shop, fixed amount or percentage,
maximum discount, minimum spend and dated expiry in Philippine time.
“Save voucher” records one owned bookmark; repeated saving is idempotent.
“Use voucher” opens the existing eligible shopping/checkout path.

Display a centered helpful message for each genuinely empty panel. Do not
promise a first-order benefit, region, shipping subsidy or expiry absent from
campaign terms. Saving is not a guarantee of availability at payment.

## Picker instead of compulsory code entry

In checkout, show a “Shop voucher” row inside each shop group and one
“Bagoo voucher” row in the summary. Each opens a bounded picker: dialog on
desktop, mobile sheet using the existing layout and component patterns.
Use labelled selectable cards, an Apply button and a clear “No voucher”
choice. Keep “Have a code?” secondary and feed it through the same rules.

Eligible cards display the actual saving for this checkout. Ineligible cards
explain one useful reason: “Add ₱X from this shop,” “Not started,” “Expired,”
“Usage limit reached,” “Not for this shop” or “No shipping fee.” Availability
and eligibility are different; disable application without concealing why.
Do not reveal private campaign funding or another buyer's usage history.

Show an explicit “Use best vouchers” action, calculated by the server across
allowed combinations using actual total savings and exact caps. A result
must really be the best eligible combination, not independent percentages.
Bound campaign counts and use a deterministic calculation with stable tie
breaking; compare it with exhaustive small fixtures in tests.

Preserve a buyer's explicit choice, including No voucher. Never silently
replace it after a Bag or address change. If a selection becomes unavailable,
remove its applied amount, explain why and invite a new choice. Revalidate
on submission; a quote does not reserve stock, quota or campaign money.

## Clear order summary

Show original merchandise, each shop discount, the allocated Bagoo product
discount, shipping before/after its discount, total savings and final COD.
For multi-shop checkout, each shop has its own complete payable total;
the summary is their exact sum. Receipt/tracking views read the persisted
allocations, not the current campaign definition.

Use “Shipping discount” for a partial reduction, “Free shipping” only for
a quoted zero shipping fee and readable percentage/cap labels. Buyers never
see an offer simultaneously consume the product and shipping platform slots.
Cancellation terms state that the use is not automatically restored.

## Interaction and acceptance

Preserve each portal's style, the active purchase submenu, independent profile
scroll regions, centered empty states and existing responsive navigation.
Provide focus return, Escape/close behavior, labelled controls, touch targets,
scrollable picker content and pending/error feedback. Keyboard focus alone
does not submit checkout or change its chosen voucher.

Verify real counts, saved-offer ownership, idempotent save/remove, per-shop
selection, platform exclusivity, manual choices, No voucher, code fallback,
quota changes and exact summaries. Use component/handler checks and isolated
SQLite tests, then run the production build. Browser/device checks are a
separate explicitly requested step; do not claim them from static inspection.

Stop at the first-release collection and choice flow. Targeting, points,
refund automation and other deferred features do not become prerequisites.
