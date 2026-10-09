# BS04: Buyer Discovery and Saved Products

## Outcome and dependency

Follow BS03. Buyers can reliably narrow catalogue results and save products
across sessions, using the existing buyer portal presentation.

## Planned work

- Trim/length-limit search, treat wildcard characters literally, validate price
  ranges/sorting/rating/category filters and retain them through stable pagination.
- Add persistent owned saved-product records with one unique buyer/product
  entry. Save/remove actions are retry-safe and require ordinary buyer eligibility.
- Add save controls and an owned saved-products page reachable from buyer
  navigation. Guests receive the existing sign-in path rather than fake saves.
- Keep unavailable saved entries visible as unavailable; disable purchasing and
  respect catalogue/privacy restrictions. Saving never reserves inventory.
- Extend product archival reference checks for saved entries. Preserve other
  buyers' lists, original orders and moderation restrictions.

## Acceptance and exclusions

Test invalid filter shapes/ranges, literal wildcard search, stable pagination,
eligibility, foreign-list access, duplicate saves/removals, sign-out persistence
and archived/restricted/sold-out entries. Run relevant frontend/backend checks
and a build. No comparison engine, recommendation service, stock-notification
subscriptions or checkout changes. Record evidence and stop after this batch.
