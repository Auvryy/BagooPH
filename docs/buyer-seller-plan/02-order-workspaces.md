# BS02: Accurate Order Workspaces

## Outcome

Buyers and sellers see accurate order counts, distinct delivery/completion and
exception states, and clear next actions. Seller batch rejection cannot partly
advance orders. Keep existing canonical lifecycle, receipt, return and money
writers; this is not a new fulfilment or dispute system.

## Shared read model

- Group seller items under one original owned order, with one selection/action
  area. Paginate orders, not item rows; use stable date/ID ordering.
- Use server-side buyer/seller status filters, validated pagination and counts
  over the whole owned history. Preserve URL filters when changing pages.
- Keep `DELIVERED`, `COMPLETED`, `DELIVERY_FAILED`, `RETURNED` and `CANCELLED`
  separate. Preserve explicit legacy aliases without rewriting history.
- Buyer stages include All, To ship, In transit, Delivered, Completed, Delivery
  issue, Returned and Cancelled. Seller stages add To pack and Ready for pickup.
- Keep restricted buyers' narrow owned-order access and seller history under
  shop restrictions. Mixed-shop legacy seller orders remain read-only.
- Replace misleading seller claims metrics/links with actual delivery-issue,
  reverse-custody and cancellation destinations. Unsupported post-delivery
  disputes remain clearly unavailable.
- Buyer purchases paginate inside the existing profile/tracking workspace;
  preserve settings/address saves, navigation and order-detail route authority.

## Writes

- Delivered buyer orders prominently link to existing receipt confirmation;
  only the existing receipt writer may advance `DELIVERED -> COMPLETED`.
- Validate a seller batch as one owned selection and execute its lifecycle
  transitions in one transaction. Lock selected orders consistently. Reject
  duplicates/foreign/mixed-shop/invalid-state rows before any final commit.
- Use the existing seller cancellation reasons as a server allowlist. Require
  explanatory notes for Other reason. Preserve pre-claim/pre-custody rejection,
  exactly-once stock restoration and sibling voucher allocations.
- Keep waybills, pickup, return receipt and financial links/actions connected
  to the original order; do not create direct status mutation shortcuts.

## Interface and contracts

Add paginated order resources, per-stage counts and existing-authority action
flags to Inertia props; migrate consumers and tests together. Keep buyer and
seller styling distinct, using each portal's existing cards, tabs, dialogs and
navigation. Separate item quantities from order counts. Selection is by order ID
and resets when its filter changes. One successful mutation refreshes current
records/counts; failures retain clear feedback without fake local state.

## Acceptance and stopping point

Test multi-item distinct counts, every stage/legacy alias, stable pagination,
foreign/restricted ownership, mixed-shop read-only history, buyer-only receipt,
later invalid batch rollback including notices/checkpoints, cancellation reasons,
claim race/stale action rejection and stock restoration. Verify grouped seller
cards and buyer pagination/actions with frontend checks and a build. Broaden
commerce/auth/custody/finance regression tests as risk warrants. Record scoped
evidence and hand off; BS03-BS05 remain unstarted unless selected separately.
