# Rider Interface Design

## Purpose and Boundaries

This document applies `STYLE_GUIDE.md` to the BagooPH courier portal. It defines the intended mobile experience, screen hierarchy, interaction states, and acceptance criteria for the five agreed improvements. It is a presentation contract, not an implementation-status report. Current evidence, dependencies, and delivery order belong in `CORE_FLOW_ROADMAP.md`.

Pickup and final-mile work remain phases of the same approved courier account. `COURIER_FLOW.md`, `SYSTEM_FLOW_AND_SPECIFICATIONS.md`, and `CORE_FLOW_VALIDATION_AND_EDGE_CASES.md` govern permissions and transitions. Presentation must preserve mandatory hub custody, buyer-only completion, exact server-owned COD amounts, and private verification access.

## 1. Make the Next Task Clear

### Phone Screen Order

| Order | Region | Contents and emphasis |
|---|---|---|
| 1 | Compact header | BagooPH, actual assigned hub when known, duty state and its control |
| 2 | Page heading | “Your tasks” and a short real scope or unassigned explanation |
| 3 | Work filters | Pickups, deliveries, available work; counts from the returned queues |
| 4 | Task queue | Current stage, stop, complete address, parcel identifier, cash due where applicable, permitted action |
| 5 | Secondary detail | Disclosed item summary, notes, recorded journey, or history |
| 6 | Bottom navigation | Existing Tasks, Trips, Messages, and Profile destinations with visible labels |

The first working area should help a rider identify a task without scrolling through decorative panels. Avoid claiming that the first item is an optimized route or a dispatch priority unless the server supplies that priority. Preserve the returned task order or explain an explicit user-selected sort.

Desktop can use a wide queue and a smaller duty/assignment context panel. Retain the same reading order and task controls. Do not force four metric tiles onto the phone layout or repeat counts throughout the screen.

### Task Card Anatomy

1. A labelled current stage and tracking number.
2. A prominent stop name and full address, followed by any authorized landmark.
3. A short next-step instruction, including the responsible actor when the rider is waiting.
4. Directions, call, and message actions grouped with that stop, when backed by valid data and permission.
5. Exact COD due or an explicit prepaid/no-COD state for final-mile work; pickup cards do not expose unrelated buyer/payment data.
6. One primary permitted transition. Supporting notes and parcel details use disclosure rather than another dashboard panel.

Use white raised task cards on the warm canvas, a restrained red stage marker, readable dark text, and one small parcel icon. A waiting card uses a quiet sand instruction inset. A recorded successful outcome can use mint with explicit text. Decoration does not carry custody meaning.

### Stage-to-Action Mapping

| Server-owned stage / permission | Rider instruction | Primary interaction |
|---|---|---|
| Eligible available pickup | Collect from the seller after claiming | Claim pickup |
| Claimed pickup awaiting collection | Go to the seller and check the parcel | Confirm pickup through the authorized flow |
| Collected pickup awaiting origin intake | Bring the parcel to the assigned Origin Bayan Hub | Directions when destination data exists; hub intake remains a hub action |
| Assigned final-mile parcel awaiting departure | Collect the assigned parcel from the Destination Bayan Hub | Start delivery through the authorized flow |
| Out for delivery | Deliver to the order's saved destination | Record delivery through the authorized evidence form |
| Recorded delivered parcel | Delivery recorded; buyer confirmation remains separate | View recorded detail/history |

Frontend stage labels never create a new status or infer actual possession from assignment alone. An unavailable action has a clear reason. Going off duty stops new work without hiding existing assigned responsibilities.

## 2. Directions for the Relevant Stop

Directions should follow the rider's current responsibility:

- Pickup travel targets the seller; after collection it targets the assigned Origin Bayan Hub.
- Final-mile collection targets the Destination Bayan Hub; the delivery leg targets the saved buyer destination.
- Hub-to-Mother-Hub transport belongs to its authorized logistics flow, not an invented rider shortcut.

Show the destination name and address outside the map so they remain usable when navigation cannot open. Prefer a labelled “Directions” action over a generic pin icon. Reuse the existing external navigation-link approach before adding an embedded map or any new dependency.

| Data condition | Interface behavior |
|---|---|
| Authorized destination and valid saved coordinates | Open directions to that destination; retain the textual address |
| Authorized full address but no saved coordinates | Use address-based directions and avoid implying an exact verified pin |
| Hub name/code only | Display that assignment with a clear “Hub address not available” explanation; no invented destination link |
| Missing or unusable destination | Explain the missing information; never open an empty search or substitute a default city/hub |
| External navigation unavailable | Preserve the address and offer copying it when supported |

Buyer directions use the immutable checkout destination snapshot, not a subsequently edited default profile address. Coordinates support that destination and cannot replace an assigned hub, recipient, service area, or COD amount. Only expose location data to actors authorized for the current phase.

An embedded map must earn its place: reliable authorized pins, a legible label/legend, usable address fallback, and a bounded area below operational essentials on phones. Do not add moving rider dots, automatic ETA, distance rankings, live GPS, or route optimization to imply capabilities outside the approved scope.

## 3. Make Phone Use Comfortable and Recoverable

### Layout and Navigation

- Start at 360–430px and support 320px reflow. Task cards stack, addresses wrap, and necessary identifiers remain distinguishable.
- Apply the style-guide text sizes and 48px standalone touch areas; key submission buttons can be 52px tall.
- Keep the labelled bottom navigation and safe-area spacing. Reserve its height in content so the last task remains actionable.
- Put each task's primary action after its essential information. Use a sticky action region only when it avoids conflict with navigation, keyboard, and sheets.
- Keep major filters visible; collapse additional filters into a labelled control. Avoid multiple competing scroll regions in the queue.
- Support keyboard, touch, and pointer input; no essential swipe, hover, or drag interaction.

### Evidence and Action Sheets

Use a compact task heading, recorded parcel identifier, evidence requirements, optional note, and explicit submit/cancel controls. Selecting or previewing a photo never changes delivery status.

Render validation beside the relevant field and announce the summary. Explain allowed evidence types and size using the actual validation contract. Keep notes and the selected photo after rejection where possible, with a visible replace/remove action. Never invent a successful scan, image, or recipient confirmation.

Dialogs/sheets must have accessible naming, managed focus, Escape handling, and return focus to the opening task. Constrain their height to the available viewport, allow content to scroll, and keep controls reachable with the on-screen keyboard. Cancel closes the local interaction; it is not assignment cancellation.

| Interaction state | Required feedback |
|---|---|
| Idle | Clear permitted action and evidence requirements |
| Submitting | Processing text, repeated submission blocked, context retained |
| Validation rejected | Specific field error and recoverable entered data |
| Stale assignment or denied action | Server reason, refresh/review path, no local success state |
| Network unavailable | Honest failure/retry guidance; no promised offline transition |
| Server-confirmed success | Recorded result, updated queue, accessible acknowledgement |

Never ask a rider to interact while moving. Location instructions and large controls support stopped field use.

### Messages on Phones

Show a conversation list, then the selected conversation as a focused view with a visible back action. Keep the parcel and participant context close to the composer. Use a labelled Send control on all widths, clear errors, and draft preservation when switching or retrying where possible.

Read-only conversations explain why sending is unavailable. A refresh or incoming-message update must preserve the selected conversation and draft. Mark a thread as read only through a verified selected-thread acknowledgement flow; opening the inbox is not evidence that every conversation was read. Do not imply persistent event notifications through decorative dots.

## 4. Keep Profile and History Truthful

### Profile

Lead with actual account identity and useful controls rather than a large digital pass. Put contact and security settings within easy reach. Present logistics-managed assignment, vehicle, and reviewed credentials as read-only information with that responsibility explained.

Use actual account/approval values and record-backed vehicle data. Missing information is labelled “Not provided” or “Not assigned.” Do not show decorative credentials as scannable passes, default license restrictions, fixed working hours, invented plate regions, or positive verification claims for missing values.

The layout uses the actual scope supplied to each screen. When scope is absent, use an honest neutral label; do not reuse a plausible default hub code or a previous screen's assignment.

### Trips

Use “Completed trips” or “Delivery history” for recorded deliveries. State the returned history's actual scope and period. Do not call a current-hub final-mile subset lifetime work, or claim pickup history without those records. Filters must affect the displayed data and explain their result.

Keep COD due, cash held, remittance, rider earnings, and seller settlement conceptually separate. A delivery-history screen cannot create a wallet or payout balance. Financial controls require the approved ledger/reconciliation work before they become actionable.

Success rates, “Operational,” and verification badges must derive from actual records and a defined denominator/state. Omit unsupported performance claims. A calm empty state may use a small parcel illustration and useful guidance, but never sample trips or earnings.

## 5. Make Settings Consistent and Safe

Contact/password forms should share the same field rhythm and error behavior as other rider forms. Explain the real password requirements, provide a clear saved result, and clear sensitive password values after success. Verification links continue to use authorized access.

Root and courier-subdomain entry points must invoke valid mutations in the user's current portal context. Reuse the repository's routing/domain patterns; do not hardcode a deployment hostname or invent an endpoint to make a form appear functional. An endpoint or authorization gap requires backend evidence before the interaction can pass acceptance.

Duty changes explain the effect on new work and existing parcels. Assignment/vehicle ownership remains logistics-managed. The frontend must not expose role, approval, hub, or financial editing authority.

Account closure requires controlled handling of active assignments and custody. A confirmation dialog alone does not enforce this rule. The account-deletion guard and any handover/remittance checks require server implementation and tests; a cosmetic change cannot satisfy that dependency.

## Frontend Component Responsibilities

| Existing area | Intended presentation responsibility |
|---|---|
| `CourierLayout.tsx` | Shared surfaces, actual scope, responsive navigation, focus and feedback |
| `CourierDutyControl.tsx` | Consistent duty wording and accessible confirmation |
| `Courier/Deliveries.tsx` | Task hierarchy, stage/stop/actions, evidence states, meaningful directions |
| `Courier/Profile.tsx` | Clear account/security forms and truthful managed information |
| `Courier/Earnings.tsx` | Honest completed-trip presentation and functioning client-side filters |
| `Courier/Messages.tsx` | Mobile list/detail flow, labelled composer, draft/error behavior |

Reuse existing shared primitives. Extract genuinely repeated presentation patterns into small components. Do not replace the application stack, add a paid service, or put business decisions in frontend utilities.

## Acceptance for the Later Implementation

| Area | Evidence required |
|---|---|
| Task clarity | Available, claimed, collected, assigned final-mile, out-for-delivery, delivered, off-duty, and unassigned states show the correct actor/stop/action |
| Directions | Seller, origin-hub, destination-hub, and buyer targets use authorized real data; missing address/pin states remain truthful |
| Mobile | 320/360/390/430px layouts, long content, 200% text enlargement, safe areas, keyboard-open forms, readable text, and required touch areas |
| Accessibility | Contrast, control labels, focus order, dialog behavior, and announced errors/results; essential actions have non-gesture paths |
| Profile/history | No unsupported credentials, sample records, perfect rates, default hubs, lifetime claims, or fabricated balances |
| Settings | Contact/password success and failure in both portal contexts; account closure cannot abandon active work |
| Messages | Mobile list/detail navigation, readable participant context, recoverable drafts, truthful read/update behavior |
| Regression | Production frontend build and focused checks; domain/route changes additionally need isolated SQLite tests |

Backend-dependent acceptance remains explicitly unfulfilled until that implementation is verified. Browser UI testing requires an explicit user request under `AGENTS.md`; static source checks and builds are useful evidence but do not establish rendered phone usability. Record actual checks, unresolved dependencies, and outcomes only in the roadmap.
