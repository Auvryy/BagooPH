# Selected-product shop vouchers

A seller may offer a voucher for all eligible products in the sole approved
shop, or for a saved selection of its products. This extends the existing
campaign, quote, funding and return contracts; it does not add another voucher
slot or change the commission and payment rules.

## Selection and publication

`product_scope` is `all` or `selected`. Whole-shop offers use an empty
`product_ids` list. A selected offer requires 1–100 distinct positive integer
product IDs from the seller's currently published, eligible listings. All
variants of a selected product qualify. Variant-specific targeting is outside
this contract.

The owned product picker searches names and SKUs and returns twelve rows per
page. It retains choices across searches and pages, supports removal and shows
the count and field errors. A failed or stale search cannot replace the current
results or discard choices. Unavailable selected listings remain explainable
in the seller editor and must be removed or corrected before publication.

Drafts retain the chosen scope. Publication checks current product eligibility
again and creates an immutable revision containing the canonical sorted IDs.
Changing scope requires a revised draft and a new publication. An older edit
request that omits both selection fields preserves an existing selection;
switching to the whole shop is explicit. Existing revisions migrate to `all`
without changing their original terms or monetary records.

The seller authoring and product-picker endpoints enforce the existing account,
shop and role gates on both portal URL forms. A buyer, rider, logistics account,
another seller or client-selected shop identifier cannot manage the scope.
Platform campaigns retain their current platform-wide scope.

## Buyer presentation and calculation

Buyer cards, the checkout picker and the applied-discount summary identify
selected-product offers. Product links include only currently public qualifying
listings. A product detail shows only shop offers that apply to that product.
The shop and saved collection may explain a targeted offer without suggesting
it applies to every listing. An unavailable selected catalogue has a clear
message and cannot be presented as immediately usable.

Minimum spend counts only the qualifying selected merchandise, at current
selected-variant prices after scheduled sales and before vouchers. Other
products, other shops and shipping cannot qualify the offer or increase its
discount. Fixed and percentage reductions retain the existing caps, dates,
quota, per-buyer limit and funded spending ceiling. Percentage arithmetic uses
integer centavos. The cost cannot exceed the original seller share attributable
to qualifying purchased lines, including centavo allocation at tiny prices.

Shop reductions apply first. A platform product voucher still uses the remaining
eligible merchandise across the permitted shop orders. Shipping remains
separate. For example:

| Amount | PHP |
|---|---:|
| Qualifying products | 300.00 |
| Other products in the same shop | 700.00 |
| Shop voucher: 10% of qualifying products | 30.00 |
| Platform voucher: 10% of remaining merchandise | 97.00 |
| Shipping before discounts | 50.00 |
| Buyer total | 923.00 |

Selecting vouchers or saving a bookmark reserves no stock, use or funding. A
changed selection receives a fresh quote. Placement reloads the original
revision and validates current availability inside the existing complete
checkout transaction. Failed placement leaves no partial orders, stock changes
or funding. An exact successful retry keeps its original result even after
later campaign or listing changes.

## Original orders, cancellation and returns

Each redemption retains its revision's selected IDs and financial terms, and
each order keeps its original discount, funding allocation, prices, quantities
and SKUs. Current listing or campaign edits cannot rewrite those values.

COD cancellation releases the original unspent funding and inventory once;
its voucher use remains consumed. Paid wallet cancellation refunds the original
paid amount once. Unpaid wallet expiry retains the existing rule for restoring
an eligible use only when all orders sharing that redemption were cancelled
unpaid. Neither path reallocates another order's discount.

Returns use the saved product IDs and original purchased units. The shop
discount is apportioned within qualifying original units; a different product
does not receive part of that reduction. Platform discounts are apportioned
after the shop reduction. Original commission, seller share and every cent
remain conserved across partial or full merchandise returns. Ordinary return
entitlement preserves the existing separate treatment of original shipping.
Real reverse custody, review, backed COD repayment or the original wallet journal
remain mandatory. A return does not automatically restore a paid voucher use.

For the example above, one PHP 100 qualifying unit retains PHP 10 shop and PHP 9
platform reductions, so its merchandise return basis is PHP 81. The PHP 700
unrelated product retains only its PHP 70 platform reduction, so its basis is
PHP 630. Neither amount is recomputed from a later product price or new offer.

Implementation evidence, checks and scoped assessments belong in
[the roadmap](../CORE_FLOW_ROADMAP.md). A coded prerequisite does not replace an
unperformed workbook, browser, printer or database-concurrency procedure.
