# PF02: Buyer and Seller Navigation

## Outcome and dependency

After PF01 is accepted, extend the proven [policy](POLICY.md) to selected buyer
and seller reads. New behavior must feel native to each portal; keep their
separate headers, sidebars, spacing, controls, typography and responsive rules.
Follow the [buyer](../BUYER_FLOWCHART.md) and [seller](../SELLER_FLOW.md) contracts.

## Work and boundaries

Audit the active route/controller/shared props for every proposed link before
enabling it. Use separate logical commits and acceptance evidence for buyer and
seller concerns. Existing directories to inspect include
`resources/js/Pages/Buyer`, `resources/js/Pages/Seller`,
`resources/js/Layouts/BuyerLayout.tsx` and
`resources/js/Layouts/DashboardLayout.tsx`.

| Surface | Planned approach |
|---|---|
| Buyer saved products, categories and owned list navigation | Use bounded intent preparation after eligibility/context review; retain unavailable saved entries honestly |
| Buyer profile, address and purchase history navigation | Prepare permitted code/reference data; keep personal records and mutable order state fresh on opening where needed |
| Seller owned product lists and public storefront preview | Prepare only the intended authorized list/preview with its exact search/filter/page identity |
| Seller dashboard summaries and order lists | Profile and narrow fresh requests; do not treat old metrics or order states as current operational permission |
| Product/address/contact edits, Buy again and fulfillment | Keep intentional server-validated mutations, existing feedback and related cache invalidation |
| Bag, checkout, private evidence, finance and settlement | Fresh authoritative reads/actions; no speculative secrets, payment proof or cached decision forms |

Do not fetch messages merely because the Messages link is hovered: message
reads may change unread state. Notification acknowledgements and advertisement
views/clicks remain tied to real display/activation. Keep all contact/branding
updates separate from reviewed identity/shop changes.

Invalidate relevant owned list/detail/summary entries after successful edits,
saves/removals, archiving and fulfillment actions. Preserve existing idempotency
and one-shop scope. A seller changing stock in another session may invalidate
a browsing snapshot without a local event; checkout and operational reads must
still contact the server, and the maximum browsing age stays explicit.

## Acceptance and stopping point

- Guest, buyer, seller, pending/restricted and foreign-owner cases retain the
  same data and action permissions. No account, shop or signed-out cache reuse.
- Product edits and saved-product changes invalidate affected lists and
  summaries; unrelated pages retain useful state and scroll.
- List search/pagination has distinct keys, no request storms, honest errors
  and normal navigation when speculation is disabled.
- Side-effecting message/notification reads are never triggered by hover;
  finance and private evidence remain outside the prefetch allowlist.
- Fresh server checks reject stale stock/order/approval actions. Pending
  controls cannot reuse cached success or old validation feedback.
- Run affected PHP/frontend tests and the frontend build. Record each portal's
  observations, remaining manual checks and scoped before/after assessment in
  the roadmap; do not infer an overall buyer/seller readiness increase.

Stop after the selected buyer/seller work. This batch does not add new commerce
features, change financial rules, rewrite page designs or start the other roles.
