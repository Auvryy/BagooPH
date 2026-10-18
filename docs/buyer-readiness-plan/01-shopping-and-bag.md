# Batch 1: Product discovery and Shopping Bag

Branch: `fix/buyer-discovery-and-bag-acceptance`.
Allocation: B3 (11), B4 (8), B5 (5), B6 (10): **34 scenarios**.
Prerequisite: the merged buyer account/address foundations and current approved
shop/category/product eligibility. Follow the [shared plan](README.md).

## Outcome

A buyer can find an eligible product, understand its actual selected variant,
save it and put the intended quantity into their own persistent Shopping Bag.
Buy Now checks out only the chosen line while other Bag items remain.

## Browsing and search

- Verify master-category and shop listings, exact/partial case-insensitive search,
  trimmed input, empty queries and a useful no-results state. Assess a bounded
  typo suggestion separately; current literal matching is not typo tolerance.
- Check combined category, price, rating and availability filters; inclusive
  bounds, invalid/reversed ranges, clearing filters and stable sorting/paging.
  Keep the validated selection in navigation and Back/Forward behavior.
- Literal punctuation, wildcards and hostile text must never execute markup or
  query syntax, disclose restricted listings or produce a server error.
- Inactive, archived, draft or restricted listings stay outside the purchasable
  catalogue, including direct product URLs. Distinguish stock availability from
  account/shop approval. A vacation treatment requires an actual saved policy.
- Review store-specific categories against the approved single master-category
  scope; do not silently introduce a second incompatible taxonomy.

## Product details

- Require every configured variant dimension. Price, image and available stock
  must agree with the selected actual combination; reject unavailable combinations
  and forged selections on the server. Existing color/size data needs review
  before claiming a full independently priced combination matrix.
- Cover typed, pasted and button quantities: positive integers only, inclusive
  actual stock, no fraction/negative/text bypass and no stale available quantity.
- If a cumulative per-buyer limit is selected, define its product setting and
  counted order states. Enforce it in the shared inventory/checkout path so a new
  Bag, retry or second purchase cannot reset the limit. Do not invent a limit.
- Product promotion displays must use a real current price and eligibility.
  Flash countdowns, promotional quotas and split-price quantities need a real
  promotion model; do not simulate them with the homepage advertisement system.
- Buy Now selects the requested product/variant/quantity once and retains
  unrelated Bag lines. Unavailable products have a clear unavailable/not-found
  result with no purchasable controls.

## Saved products and following

- Save/remove remains owned, persistent and duplicate-safe. Heart state agrees
  between the product page and saved list after reload and another sign-in.
- Keep unavailable saved entries readable without exposing restricted product
  details; disable buying and preserve an accurate empty state.
- Guests must sign in before account-owned saves or follows. Do not turn a
  pending account into an approved purchasing account.
- Replace any local-only follow toggle and invented count with a persisted owned
  follow relationship and real count if following is included. Cover unfollow,
  reload and repeated clicks. This is additional behavior, not evidence that the
  existing button already works.

## Shopping Bag

- The same product/variant merges; different variants remain separate and shop
  groups contain only their own lines. Retain quantities across sign-out/sign-in.
- Selecting/deselecting lines changes only the intended checkout selection and
  recalculates totals. Never include unrelated/unselected lines in Buy Now.
- Recheck current price, stock and catalogue eligibility. An unavailable line
  cannot block an otherwise valid selection or be purchased through a direct
  request. Give clear feedback when a stored price or stock changes.
- Quantity updates and removals persist. A manipulated foreign line ID cannot
  change another buyer's Bag. Repeat requests must not duplicate line counts.

## Acceptance and stopping point

Extend the existing `BuyerDiscoveryAndSavedProductsTest`, `CataloguePrefetchTest`,
`BuyerCartQuantityTest`, `BuyerCartSelectionTest`, `BuyerBuyAgainTest` and
inventory/checkout tests only where behavior changes. Check relevant frontend
selection, navigation and error states and run the production build.

Use ordinary owned records and isolated tests, not destructive demo resets.
Record which added features and policy decisions remain open before treating
all 34 scenarios as accepted. Hand off a usable catalogue-to-Bag path to batch 2;
do not start payment, return/refund or unrelated portal work in this branch.
