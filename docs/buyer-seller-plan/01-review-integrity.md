# BS01: Review Integrity

## Outcome

Reviews identify actual completed purchases, seller replies survive refresh,
and ratings/claims reflect recorded evidence. Both portals retain their own
presentation. This batch does not add review moderation or buyer review editing.

## Backend and compatibility

- Require an active approved buyer, an owned `COMPLETED` order and its original
  order item. Derive product/order/buyer identity from that item.
- Add nullable `order_item_id` with a unique constraint to retain legacy rows
  while enforcing one new review per purchased item. Accept the previous
  order/product pair only when it identifies exactly one item; reject ambiguity.
- Validate rating 1-5, plain-text comment up to 1,000 characters and at most five
  genuine JPEG/PNG/WebP images of at most 5 MB each. Reject injected identity.
- Commit the review and rating updates transactionally. An identical retry
  returns the original review; a changed second submission rejects. Remove
  unused uploaded files on rejection/rollback.
- Backfill legacy links only for unique, owned completed purchases with one
  matching item and one matching review. Keep all other reviews unverified;
  preserve their original text/photos and exclude them from verified aggregates.
- Persist one editable reply per review, with original seller attribution and
  publication/update timestamps. Only the currently eligible owning seller/shop
  may write. Preserve the original buyer review and reauthorize direct requests.
- Calculate product/shop averages and verified counts from verified purchase
  records. Calculate seller review reply rate from saved replies, separately
  from chat response metrics. Use null/empty states where no rating exists.
- Extend the existing review/reply routes and shared TypeScript types. Preserve
  original route names and actor gates; use normal authenticated write throttles.

## Interface

The buyer review picker identifies the order item and its variants, excludes
already-reviewed items and shows the saved outcome. Product pages distinguish
verified and unverified reviews and display saved seller replies. Seller review
forms submit to the server and retain drafts/errors until the saved reply is
confirmed. Average stars/counts reflect actual values, with an honest empty state.
Remove unsupported product/shop response claims, premium badges and guarantees;
derive shop age from its actual creation date. Keep changes inside existing buyer
and seller layouts rather than introducing a shared replacement portal.

## Acceptance and stopping point

Test missing/foreign/incomplete purchase, duplicate products in different order
items, invalid rating/text/images, unchanged and changed retries, upload rollback,
ambiguous legacy rows, owning/foreign/restricted seller replies, edited reply
attribution, averages/reply rates and public payload privacy. Check buyer/seller
saved UI states with frontend tests and a production build. Record scoped
evidence in the roadmap and hand off; do not start another batch automatically.
