# BagooPH Style Guide

## Purpose and Authority

BagooPH should feel warm, playful, and dependable. Use generous rounded corners, a calm ivory canvas, soft raised surfaces, and small parcel-inspired details around the original crimson identity. Riders should be able to find their next stop and action quickly on a phone.

This guide defines presentation across BagooPH. `RIDER_UI_DESIGN.md` applies it to the courier portal. Business permissions, commercial states, custody, and money remain governed by the system, role, and validation specifications. Implementation status and delivery order belong only in `CORE_FLOW_ROADMAP.md`.

Design evolves through user feedback; tokens and interaction patterns are deliberate shared choices. Read `AGENTS.md` alongside this guide. Keep existing dependencies and components, the BagooPH name, PHP/₱ amounts, and Plus Jakarta Sans. Authentication retains its split-screen layout and restrained surfaces.

## 1. Experience Principles

These are BagooPH applications of established design principles, rather than claims that one visual treatment works for everyone.

| Principle | BagooPH rule | Rider example |
|---|---|---|
| Discoverability and feedback | Label actions with their outcome and acknowledge the server result. | “Confirm pickup” changes to a confirmed collection state only after acceptance. |
| Recognition over recall | Keep the destination, parcel, cash amount, and next step together. | A rider does not have to remember an address from another tab. |
| Grouping and hierarchy | Group related information; give the current task the strongest emphasis. | Contact actions sit beside the stop they concern. |
| Progressive disclosure | Show operational essentials first; reveal supporting history and notes on demand. | A parcel card expands into details without a wall of summary panels. |
| Error prevention | Distinguish navigation, communication, and custody-changing actions. | “Directions” cannot confirm collection; a photo upload can be corrected before submission. |
| Emotional confidence | Combine welcoming surfaces with honest, predictable behavior. | A small parcel illustration welcomes an empty queue; failures retain clear, serious instructions. |

The emphasis on visible actions, mapping, and feedback draws on Don Norman's [The Design of Everyday Things](https://jnd.org/books/the-design-of-everyday-things-revised-and-expanded-edition/). The focus on clear web and mobile navigation follows Steve Krug's [Don't Make Me Think, Revisited](https://sensible.com/dont-make-me-think/). The concrete BagooPH sizing and layout rules below are product decisions to evaluate with riders.

## 2. Visual Direction: Warmth, Depth, and Personality

Use a warm ivory canvas, white working surfaces, soft rose accents, and dark readable text. Place the primary task on a lightly raised card. Quiet secondary sections sit on the canvas or a tinted inset surface. This gives the screen a visible foreground and background.

Create personality through:

- A restrained parcel, bag, route, or waypoint motif using existing icons or small local vector artwork.
- A red edge marker, a softly tinted icon tile, or a short connected journey to identify the current task.
- Asymmetric desktop composition where the work queue dominates; a simple ordered stack on phones.
- Brief pressed and success feedback, and friendly empty-state language.
- Warm spacing and varied surface levels, with one decorative moment in a header or empty state.
- Rounded working cards and controls, with circular icon tiles and short pill badges that remain clearly labelled.

Reference material includes the [Awwwards mobile award collection](https://www.awwwards.com/annual-awards-2021/mobile-site-of-the-year) and its [mobile performance publication](https://www.awwwards.com/brainfood-mobile-performance-vol3.pdf). Use these to review responsive personality and motion costs. The BagooPH corner sizes, palette, and working-screen hierarchy below are product choices; the references do not establish operational usability or justify copying another site's identity.

Decorative details must not resemble an actual credential, barcode, verification seal, map pin, scan result, or financial balance. Keep them outside addresses, amounts, and action groups. Hide decorative artwork from assistive technology. Do not add large illustrations above mobile work queues, confetti, racing timers, background video, or continuous effects.

## 3. Color System and Meaning

Crimson `#E00D42` remains the primary accent. Use it for the main action, active navigation, and selected task. Rose tints carry brand warmth; sand, mint, and sky support relevant informational or semantic states.

Red does not have a universal psychological meaning. Context, wording, culture, and the task affect interpretation. Make the interface reassuring through reliable feedback and readable hierarchy. Do not use color to manufacture urgency or imply verification.

| Role | Color | Application |
|---|---|---|
| Primary accent | `#E00D42` | Main action on white; white label on solid red |
| Strong accent | `#C20836` | Hover state; small brand text on rose surfaces |
| Pressed accent | `#A1052B` | Pressed action state |
| Rose surface | `#FDF2F4` | Selected region, brand icon tile, quiet welcome detail |
| Rose inset | `#FCE7EA` | Small secondary brand surface; verify its foreground contrast |
| Warm canvas | `#F8F6F2` | Page background |
| Working surface | `#FFFFFF` | Task cards, forms, dialogs |
| Sand surface / text | `#FFF4DF` / `#92400E` | Relevant waiting instruction or warning |
| Mint surface / text | `#ECFDF5` / `#047857` | Server-confirmed success |
| Heading | `#0F172A` | Titles and key destinations |
| Body | `#1E293B` | Addresses, amounts, instructions |
| Supporting text | `#475569` | Times, scope, secondary labels |
| Structural border | `#CBD5E1` | Card separation; normally `border-slate-300` |
| Control boundary | `#64748B` | Input or outline boundary when needed to identify the control |
| Error text | `#BE123C` | Labelled validation and failure states |

Accessibility rules:

- Normal text needs at least 4.5:1 contrast; qualifying large text needs at least 3:1. See [text contrast](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
- Meaningful control boundaries, icons, and state indicators need at least 3:1 against adjacent colors where required. A pale card divider is not sufficient as the only way to identify a control. See [non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html).
- Pair status color with a label and, where useful, an icon. See [use of color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html).
- White on the primary red is approximately 4.88:1. Primary red on the rose surface is approximately 4.46:1, so use the stronger accent for small text there. Supporting text on the warm canvas is approximately 7.02:1. These are solid-color calculations, not a conformance claim for a rendered screen.
- Check every actual combination, including opacity, hover, focus, imagery, and dark surfaces. Do not put important text in pale gray or over decoration.

Dark interfaces retain visible slate-700/800 borders, light text, and labelled semantic states. Introduce dark-mode behavior only with an explicit product scope and complete contrast verification.

## 4. Geometry, Typography, and Spacing

Use explicit local radii for the soft rider direction. Do not rely on the existing theme's small `rounded-lg` value or change global tokens to restyle unrelated pages. Other portals adopt the direction when their work is in scope.

| Element | Class / actual current value | Rule |
|---|---|---|
| Buttons, fields, tabs | `rounded-[14px]` / `rounded-[16px]`, 12–16px | Comfortable outline, labelled action, 48px minimum touch area |
| Cards | `rounded-[24px]`, 24px | Visible border, airy padding, restrained depth |
| Inset surfaces and map frames | `rounded-[18px]`, 18px | Quiet grouping within the card |
| Dialogs and sheets | `rounded-[28px]`, 28px | Bounded height with readable, scrollable contents |
| Short badges and icon controls | `rounded-full` | Clear text or accessible name; never rely on shape alone |
| Decorative illustration frame | 18–24px or circular | Small and outside operational values |

Use Plus Jakarta Sans everywhere, including tracking numbers, prices, charts, and email. For aligned numbers use tabular numerals within the same family. Do not introduce another font or apply `font-mono`.

| Text role | Suggested mobile size | Weight / behavior |
|---|---:|---|
| Page title | 24–28px | 700–800; short and allowed to wrap |
| Stop / section title | 18–20px | 700; ahead of secondary labels |
| Address, instructions, editable input | 16px | 400–600; comfortable line height |
| Button / tab / navigation label | 14–16px | 600–700 |
| Supporting time or parcel metadata | 14px | 400–600; strong supporting color |
| Incidental compact label | At least 12px | Never the only address, amount, action, or failure instruction |

Use a 4px spacing rhythm: 4, 8, 12, 16, 24, and 32px. Phone gutters start at 16px; wider screens can use 20–24px. Cards use 16–20px padding. Separate major sections by 24px and related fields by 12–16px. Avoid uppercase paragraphs and heavily spaced labels. Preserve browser zoom and text enlargement.

## 5. Surface and Interaction Recipes

| Surface | Recipe | Intended effect |
|---|---|---|
| Primary task | White, slate-300 border, quiet two-layer shadow, small brand marker | Clear foreground for the work to do |
| Secondary information | White or warm canvas, structural border, little or no shadow | Lower emphasis without faint text |
| Selected task or tab | Rose tint, stronger red label, visible marker, semantic selected state | Selection is obvious without recoloring the entire screen |
| Waiting instruction | Sand inset with dark text and a short reason | Explains the next responsible actor |
| Confirmed result | Mint inset, dark text, explicit event/time when supplied | Communicates a recorded outcome |

A suitable primary-card shadow is `0 2px 0 rgba(15,23,42,0.04), 0 8px 24px rgba(15,23,42,0.06)`. Shadows support borders; they do not identify buttons or create a new surface around every row. Keep layers predictable and scrolling inexpensive.

Motion should explain an interaction: 120–180ms for a press or selection, up to 200ms for a card or sheet entering with a small fade and 6px movement. Use short, finite effects; avoid staggered queues and endless bouncing. A small press displacement can make an action feel tactile. No hover-only affordance or hover-dependent information. Respect `prefers-reduced-motion`; remove nonessential movement and preserve immediate feedback. Never animate a map marker to imply live location without live data.

Reuse the existing icon set and dialog/form primitives. New variants belong in shared components rather than repeated page-specific styling. Avoid additional UI libraries, external asset services, or decorative dependencies.

## 6. Mobile Interaction Contract

Design the rider portal at 360–430 CSS pixels first and support reflow at 320px. Layout must tolerate large text, long names, full addresses, and the on-screen keyboard. Necessary addresses wrap; tracking identifiers can wrap or be copied, rather than losing their distinguishing characters.

- Use at least 48×48px touch areas for standalone actions; primary submission actions can be 52px tall. Keep at least 8px between adjacent targets. This is BagooPH's field-use target, above the [44px enhanced accessibility criterion](https://www.w3.org/WAI/WCAG22/Understanding/target-size-enhanced/); it is not the 24px minimum AA criterion.
- Keep frequently used actions within a comfortable lower-screen area when the rider is stopped. Do not require precision gestures, dragging, or swiping to complete work.
- Preserve the labelled mobile bottom navigation. Include safe-area padding and reserve its complete height in page content.
- Choose one bottom action arrangement per screen. A task action, bottom navigation, keyboard, and dialog must not cover one another or hide focused content.
- Use single-column tasks on phones. Keep important controls visible without horizontal scrolling. Put supplementary detail behind labelled disclosure controls.
- Forms use 16px editable text, correct input types, persistent labels, and inline errors. Preserve entered notes and selected evidence after validation failure where possible.
- Sheets/dialogs have bounded viewport height, independently scrollable content, visible close/cancel actions, managed focus, and appropriate dialog semantics. Support Escape and return focus to the opening control.
- Mobile information architecture remains clear at 200% text enlargement and [320px reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html). Actual support requires rendered verification during the implementation stage.

## 7. Page Composition

Role dashboards share typography, surfaces, navigation behavior, and truthful states. Their content hierarchy follows the user's task rather than a mandatory four-KPI layout.

For riders: compact identity/duty context, work filters, the first task, a bounded selected-stop map, then remaining tasks and secondary history. On desktop, place the map beside the first task. The map follows an explicit job selection and saved location; addresses and custody actions remain outside it. Put the work queue before statistics. Do not repeat the same counts in cards, tabs, a custody panel, and another preview.

For broader operational dashboards: a concise title and primary action, only useful real metrics, a dominant work list, and secondary detail. An 8/4 desktop split is appropriate when supporting information helps the work. Phone layouts place actions before charts and decoration.

Charts use real chronological data, labelled periods, and functioning controls. Journey diagrams reflect actual custody and correctly branch for return or counter collection. They never invent progress, ETA, performance, or sample activity. Authentication keeps split-screen login/registration with readable forms and minimal decoration; no gradients or ornamental clutter there.

Floating menus remain absolute, preserve the existing overlap and 250ms pointer grace period, and never shift layout. They also support click/tap, keyboard use, dismissal, and focus management.

## 8. Language, Feedback, and Trust

Use sentence case, familiar words, and explicit outcomes. Customer shopping language remains “Bag,” “Shopping Bag,” and “Add to Bag.” Operational language can use “Parcel,” “Pickup,” “Assigned hub,” and “Completed trips.” No emojis in UI, comments, code, or repository documentation.

Prefer “Pickup address” to “Merchant location endpoint,” “Assigned hub” to “Territory sector,” and “Delivery history” to “Custody audit ledger.” Friendly copy should be brief: “Your tasks are up to date” only after a successful refresh, or “No pickup jobs available” with a truthful next step.

Every mutation distinguishes idle, submitting, rejected, and server-confirmed states. Prevent repeated submission while processing. Render field errors beside the field, announce the result accessibly, and keep a useful recovery action. A successful upload is not a successful delivery until the server records that outcome.

Missing values use “Not provided,” “Not assigned,” or an explicit unavailable explanation. Do not substitute fictitious facilities, operating hours, credentials, perfect success rates, balances, scan results, or success messages. Keep addresses, cash, proof, and authorization factual even when surrounding artwork is playful.

## 9. Implementation Review

Review changed screens for:

- Clear current task, destination, scope, cash amount, and next permitted action.
- Bagoo red, one typeface, correct geometry, visible boundaries, and actual contrast.
- Phone reflow, text enlargement, long content, keyboard interaction, safe areas, and touch target size.
- Labelled controls, logical headings, visible focus, modal behavior, and announced validation/results.
- Honest loading, empty, missing-data, error, offline, and terminal states.
- Reduced motion and no continuous heavy effects.
- Existing permission and custody rules; visuals cannot add operational authority.

Use focused automated checks and the production frontend build for implementation changes. Browser automation and screenshots require the user's explicit request under `AGENTS.md`. A document, static check, or successful build alone does not prove mobile usability. Record implementation evidence and limits in `CORE_FLOW_ROADMAP.md`.
