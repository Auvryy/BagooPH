# Batch 3: Orders, parcel tracking and communication

Branch: `fix/buyer-orders-and-communication-acceptance`.
Allocation: B10 (6), B11 (6), B12 (5), B13 (6), B14 (5): **28 scenarios**.
Dependency: accepted batches 1 and 2 with owned order/parcel evidence. Follow the
[shared plan](README.md) and the canonical lifecycle, custody and finance rules.

## Outcome

Buyers can understand the current recorded parcel position, follow the original
journey and use only permitted order, review and messaging actions. Notification
links/counts agree with saved records. Unimplemented refund or payment features
remain clearly identified rather than returning a false success.

## Buyer parcel tracking design

The user selected a clearer visual tracking experience instead of flat text:

- Restore connected **circular milestone markers**, with completed, current and
  upcoming steps visually distinct. Use a labelled horizontal rail where desktop
  space permits and a readable vertical sequence on phones. Highlight the current
  step with Bagoo crimson and an explicit current-step label, not color alone.
- Keep useful information from the current `OrderTrackingPanel`: status and stage,
  actual recorded holder, most recent handover time, hub/location names, original
  checkpoint times, responsible actor and clear next action.
- Keep a concise current-state summary above the detailed journey. Every repeated
  Mother Hub checkpoint remains in the expandable/readable history. Display times
  in Asia/Manila; distinguish last recorded location from a live location.
- Map display milestones to actual lifecycle/checkpoint evidence. Preserve
  seller preparation, pickup rider, origin Bayan Hub, at least one Mother Hub,
  destination Bayan Hub and delivery rider or counter collection. Do not render
  unrecorded scans as completed or create a direct seller-to-buyer shortcut.
- Give failed delivery, approved retry, return-to-sender, cancellation and
  self-pickup their actual branch, reason and next step. A returned or cancelled
  parcel must not appear successfully delivered. Delivered and buyer-completed
  remain visibly separate.
- Preserve missing-history, loading, refresh, error and retry feedback. Refresh
  retains useful page state and cannot duplicate events or receipt confirmation.
  Missing times/locations stay honestly unrecorded; do not invent an ETA or GPS.
- Match the surrounding buyer header, white surfaces, spacing, rounded controls
  and Plus Jakarta Sans. Use the local frontend design skill. Scope presentation
  to the buyer view so shared tracking use does not silently restyle other roles.
- Use labelled semantic progress/history, clear focus, touch-sized controls and
  reduced motion. A tracking view must remain readable on narrow screens and with
  long hub names; no continuous animation or external design dependency is needed.

## Tracking, completion and cancellation

- Match order rows, stage tabs, counts and timelines to the same owned data.
  Repeated operational events appear once and checkpoints retain their actual
  sequence. Another buyer's order URL or identifier cannot expose private data.
- Confirm receipt only for the buyer's delivered order, once. Preserve the
  narrow existing-order access exceptions without granting new purchasing access.
  Passing time alone must not complete an order or release seller funds.
- Buyer self-service cancellation is an additional writer to assess; the current
  cancellation route is seller-owned. Define allowed stages and a meaningful
  reason, preserve claim/custody boundaries, and reuse the canonical locked
  lifecycle/inventory/funding behavior rather than impersonating a seller.
- Verify blocked late cancellation, simultaneous requests and exactly-once stock
  restoration, retained notices and unchanged sibling order allocations. Prepaid
  refund cancellation remains dependent on a separately selected payment feature.

## Post-delivery returns and refunds

The buyer dispute controller currently exposes an unavailable view. Full return
requests, seller decisions, escalation and money repayment are new scope,
separate from the implemented failed-delivery reverse-custody process.

Before implementation, agree eligibility/window, completion interaction, reason
and description rules, private evidence types/size/count, seller response and
escalation deadlines, return transport/receipt, partial-item amounts, shipping
handling and COD repayment destination. Preserve immutable original payment and
settlement records; prevent duplicate requests, conflicting decisions and double
refunds. Reuse the existing returns/refund planning task rather than duplicating
its scope. The overnight core repair window does not promise this full feature.

## Reviews

- Retain completed-purchase ownership, exact item/variant linkage, integer stars,
  one saved review per purchased item and retry protection. Uncompleted or foreign
  purchases cannot write a review through a direct request.
- Resolve review text length, photo limits/content, public name masking, time
  window and one permitted edit. Existing creation is not evidence of edit support.
  Keep previous content and upload cleanup correct on a failed write.
- The public product, search card and shop summaries must use the same real
  verified reviews. Recompute averages/counts correctly after permitted changes;
  sellers cannot hide or overwrite the buyer's review.

## Messages and notifications

- Product-origin messages retain the correct shop, product context and owned
  conversation. Cross-role IDs cannot expose a different conversation.
- Text messages already exist; image attachments need bounded upload validation,
  ownership, delivery/order, storage and failure cleanup before acceptance. Enforce
  length/content rules server-side, preserve drafts and prevent duplicate sends.
- Real order events produce the intended recipients' notices once. Clicking a
  notice opens the right owned order, and displayed read/read-all actions update
  unread counts without acknowledging other accounts or unseen newer records.
- Flash-sale reminders require the actual sale schedule/subscription feature;
  existing lifecycle notifications or homepage advertisements do not establish it.
  Keep private pickup codes and sensitive evidence out of notices and public views.

## Acceptance and stopping point

Use the existing `BuyerOrderWorkspaceTest`, `ReviewIntegrityTest`,
`ProductMerchantChatTest`, `ChatAntiSpamTest`, `ChatUnreadNotificationTest` and
relevant lifecycle/notification/finance tests for changed rules. Add focused
rendering checks for real milestone order, active/exception states, missing data,
refresh failures and buyer-only presentation; run the production build.

The final handoff walks through catalogue, Bag, quote, COD order, original parcel
history, delivery and explicit buyer receipt, then a permitted review/message.
Record unsupported returns, online payments, reminders or unresolved policies
separately. Do not report all 28 scenarios accepted until their own actual
behavior and agreed expectations are verified.
