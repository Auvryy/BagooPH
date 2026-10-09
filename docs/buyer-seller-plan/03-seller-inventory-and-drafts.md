# BS03: Seller Inventory and Drafts

## Outcome and dependency

Follow BS02. Give the seller a practical inventory worklist and explicit draft
creation within the existing merchant style, approved shop and category scope.

## Planned work

- Preserve variant stock `0` when opening/saving the edit form; never substitute
  product stock merely because the variant value is zero.
- Add Save draft and Publish using existing validated listing fields. Drafts
  remain outside the public catalogue. Creation accepts active/draft only;
  existing editing/archival rules remain.
- Add validated server-side search, category, listing-status and stock filters,
  retaining selections through stable pagination.
- Define active-product low stock as 1-5 units and out of stock as zero. Exclude
  drafts/archives from active-stock alerts. Link dashboard alerts to the matching
  inventory worklist; present variant quantities without inventing a new schema.
- Publishing never bypasses account/shop approval, category or moderation.

## Acceptance and exclusions

Test zero/null stock, save/reload, draft visibility, filtering/pagination,
foreign/restricted writes, category scope and moderated publishing. Run frontend
regressions, focused seller/catalogue tests and a build. No import/export,
warehouse automation, new stock model or extra-shop controls. Record evidence
in the roadmap and stop after this selected batch.
