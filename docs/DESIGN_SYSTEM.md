# BagooPH Web Design System

Status: draft for the website redesign (November 2026). This document replaces
every earlier web styling rule. The current UI, older audits and plan documents
are not design references. Where this draft and the current code differ, the
code is what still needs redesigning.

The only element carried over from the previous design is the Bagoo brand red.

## 1. Principles

1. **Task first.** Every page exists for one or two jobs (find a product, pay,
   confirm a pickup, sort a parcel, approve a seller). The layout makes the
   main job obvious within one glance and one scroll.
2. **Each portal fits its user.** The storefront is for browsing and trust.
   Seller, hub and admin portals are work tools used for hours: dense, fast,
   keyboard-friendly. The rider portal is used outdoors on a phone, often with
   one hand: large targets, high contrast, one decision per screen.
3. **Hierarchy through type and space, not decoration.** Size, weight, spacing
   and alignment carry structure. Borders, cards, shadows, color and icons are
   added only when they clarify something.
4. **Red is a signal.** The brand red marks the primary action, the active
   location, step markers, the final amount to pay and the brand mark. If
   everything is red, nothing is.
5. **No black shades.** Black and near-black fills fight the brand red. Never
   use them for buttons, badges, step markers, avatars, chart bars, map pins,
   cards, overlays or any other filled shape. Filled accents use the brand
   reds; neutral fills stay light (`surface-50`, `surface-100`, `line-200`).
   Dark ink is only for text and thin icons.
6. **Real states, real data.** Every view is designed with real Bagoo content
   and covers loading, empty, error, partial and success states.
7. **Consistency over novelty.** A pattern solved once (filters, tables,
   status, money) is reused everywhere. New patterns need a reason.

## 2. Color

### 2.1 Brand red

| Token | Hex | Use | Contrast on white |
|---|---|---|---|
| `brand-500` | `#E00D42` | Primary buttons, active nav marker, step markers, final total, brand mark | 4.88:1 |
| `brand-600` | `#C20836` | Hover/pressed primary, red text links on tinted backgrounds | 6.21:1 |
| `brand-700` | `#A1052B` | Small red text, destructive emphasis | 8.16:1 |
| `brand-50` | `#FDF2F4` | Selected row/tab background, subtle highlight | - |
| `brand-100` | `#FCE7EA` | Hover tint on selected items | - |

White text on `brand-500` passes WCAG AA (4.88:1). Red text smaller than 14px
uses `brand-700`.

### 2.2 Neutrals

A warm neutral scale (stone family) sits better beside the red than cool
blue-grays and keeps the interface from looking generic.

| Token | Hex | Use |
|---|---|---|
| `ink-900` | `#1C1917` | Headings, primary text (17.5:1). Text only, never a fill |
| `ink-700` | `#44403C` | Body text in dense views |
| `ink-600` | `#57534E` | Secondary text, labels (7.6:1) |
| `ink-500` | `#78716C` | Placeholder, meta text, disabled labels (4.8:1, minimum for text) |
| `ink-400` | `#A8A29E` | Icons in idle state, never for text |
| `line-300` | `#D6D3D1` | Input borders |
| `line-200` | `#E7E5E4` | Dividers, table rules, card borders |
| `surface-100` | `#F5F5F4` | Page background in work portals, hover rows |
| `surface-50` | `#FAFAF9` | Page background in storefront |
| `white` | `#FFFFFF` | Content surfaces, inputs |

### 2.3 Semantic colors

Because the brand itself is red, error states must never rely on red alone.
Errors always pair the color with an icon and explicit wording.

| Meaning | Text | Background | Use |
|---|---|---|---|
| Success | `#15803D` | `#F0FDF4` | Delivered, completed, paid, approved |
| Warning | `#B45309` | `#FFFBEB` | Needs attention, delayed, pending review |
| Info / in progress | `#1D4ED8` | `#EFF6FF` | In transit, processing |
| Error / danger | `#A1052B` | `#FDF2F4` | Failed validation, rejected, destructive actions |
| Neutral | `#57534E` | `#F5F5F4` | Placed, cancelled, archived, returned |

### 2.4 Order status mapping

One mapping, used on every portal:

| Status | Tone |
|---|---|
| `PLACED` | Neutral |
| `CONFIRMED`, `PREPARING`, `READY_FOR_PICKUP` | Info |
| `PICKED_UP`, `AT_SORTING_CENTER`, `SORTED`, `ASSIGNED_TO_RIDER`, `OUT_FOR_DELIVERY` | Info |
| `DELIVERED`, `COMPLETED` | Success |
| `DELIVERY_FAILED` | Warning |
| `RETURNED`, cancelled | Neutral |

Statuses are never shown in brand red; red stays reserved for actions.

## 3. Typography

**Proposed typeface (decision pending):** one variable sans-serif for all UI
text, self-hosted as a package dependency (no external font service), plus a
monospace face used only for tracking codes, waybill numbers and IDs so that
`0/O` and `1/l` are distinguishable. Candidate: Geist Sans and Geist Mono
(open licence). Confirm before implementation; the scale below works for any
neutral grotesque.

All numbers in tables, prices, quantities and KPIs use tabular figures
(`font-variant-numeric: tabular-nums`) so columns align.

| Token | Size / line height | Weight | Use |
|---|---|---|---|
| `display` | 36 / 40 | 700 | Storefront hero headline only |
| `h1` | 28 / 34 | 700 | Page title |
| `h2` | 22 / 28 | 600 | Section title |
| `h3` | 18 / 24 | 600 | Card or panel title |
| `body-lg` | 16 / 24 | 400 | Storefront body, product descriptions |
| `body` | 14 / 20 | 400 | Default UI text in work portals |
| `label` | 13 / 18 | 500 | Form labels, table headers |
| `caption` | 12 / 16 | 400 | Meta text, timestamps, helper text |

Rules: at most three sizes in one component; weight 700 only for page titles
and prices; no all-caps body text (all-caps allowed for short table headers
with `tracking-wide`); minimum text size is 12px; rider portal minimum is 14px.

## 4. Space, layout and shape

- **Spacing scale (4px base):** 4, 8, 12, 16, 24, 32, 48, 64. Do not use
  in-between values. Related items 4-8px apart, groups 16-24px, sections 32-64px.
- **Breakpoints:** Tailwind defaults (`sm` 640, `md` 768, `lg` 1024,
  `xl` 1280). Design phone first for storefront and rider; design 1280px first
  for seller, hub and admin, then make them usable down to tablet.
- **Content widths:** storefront 1200px max; reading/forms 640px max;
  work portals use full width with a 240px sidebar.
- **Radius:** `4px` badges and small chips; `6px` inputs and buttons; `10px`
  cards, dialogs and images; full round only for avatars and status dots.
- **Elevation:** surfaces are separated by a `line-200` border or background
  change, not a shadow. Shadows are only for things that float above the page
  (menus, popovers, dialogs, toasts, sticky bars).
- **Cards are for grouping,** not a default wrapper. A page is not a grid of
  identical cards. Lists and tables are usually better than card grids for
  operational data.

## 5. Components

Shared primitives will live in one folder (`resources/js/Components/ui/`) and
every page builds from them. Feature components compose primitives instead of
restyling them.

### Buttons
- **Primary:** solid `brand-500`, white text, hover `brand-600`. One per view
  or dialog.
- **Secondary:** white background, `line-300` border, `ink-900` text, hover
  `surface-100`.
- **Ghost:** text only, for low-emphasis actions in toolbars and tables.
- **Danger:** white background, `brand-700` text and border; filled
  `brand-700` only inside a confirmation dialog.
- Sizes: `sm` 32px, `md` 40px (default), `lg` 48px (rider and mobile
  checkout). Labels are verbs ("Confirm pickup", not "OK").
- Loading state keeps the button width and disables repeat submission.

### Forms
- Label above the field, helper text below, error text below in danger color
  with an icon. Never use placeholder text as the label.
- Inputs 40px tall (48px on rider), `line-300` border, focus ring 2px
  `brand-500` at 40% opacity plus border `brand-500`.
- Validate on blur and on submit; keep entered values after server errors;
  scroll to and focus the first error.
- Group long forms into titled sections; show required fields, not optional
  ones, unless most fields are optional.

### Tables (work portals)
- Row height 44px (compact 36px option). Left-align text, right-align numbers
  and money. Sticky header on long tables.
- Every table has: search or filters above, result count, pagination or load
  more, empty state, and loading skeleton rows.
- Row actions on the right; bulk actions appear in a bar when rows are selected.
- On small screens, tables become stacked rows showing the 3-4 key fields.

### Status badges
- 4px radius, 12px text, tone colors from section 2.3, optional leading dot.
  Text is the human label ("Out for delivery"), never the raw enum.

### Navigation
- **Storefront:** top bar with logo, search as the dominant element, Bag, and
  account. Category navigation below. Mobile: search stays visible.
- **Work portals:** left sidebar (240px) grouped by task, collapsible to icons,
  with the current page marked by a `brand-500` bar and `brand-50` background.
  Page header holds title, short context line and the primary action.
- **Rider:** bottom navigation with at most 4 items; current task pinned at top.

### Feedback
- **Toasts:** bottom-right desktop, top mobile; success auto-hides after 4s,
  errors stay until dismissed.
- **Dialogs:** for confirmation of destructive or irreversible actions only.
  The backdrop dims the page with deep brand red (`brand-900` at about 45%),
  never black and never a light wash that makes the page look faded.
  Title states the action, body states the consequence, buttons name the action.
- **Inline banners:** for page-level conditions (account pending approval,
  offline, hub capacity full).

### Empty, loading and error states
- **Empty:** one line saying what will appear here, one line on how to get it,
  and the action that creates it. No illustrations larger than 96px.
- **Loading:** skeletons shaped like the real content; spinners only inside
  buttons and for actions under a second.
- **Error:** what happened, what the user can do, and a retry action. Never a
  raw exception or status code alone.

## 6. Content and formatting

- Money: `₱1,234.50` with two decimals in checkout, orders and finance;
  storefront listings may drop `.00`. Always tabular figures.
- Dates: `15 Nov 2026`, times `3:45 PM`, Asia/Manila. Relative time ("2 hours
  ago") only for activity feeds, with the exact time on hover.
- Customer copy says "Bag", "Shopping Bag", "Add to Bag".
- Tone: short, direct, friendly; sentence case for all headings and buttons.
- No emojis anywhere.

## 7. Motion

- Durations 120-200ms, ease-out for entering, ease-in for leaving.
- Animate only opacity and transform. No looping or decorative animation in
  work portals. Respect `prefers-reduced-motion` by removing movement.

## 8. Accessibility baseline

- WCAG 2.2 AA contrast for all text and meaningful icons.
- Visible focus on every interactive element; full keyboard operation for
  tables, dialogs, menus and forms; focus is trapped in dialogs and returned on
  close.
- Touch targets at least 44x44px on phone layouts.
- Icons that act as buttons have accessible names; status is never conveyed by
  color alone.

## 9. Portal profiles

| Portal | Density | Base text | Background | Primary concern |
|---|---|---|---|---|
| Storefront / buyer | Airy | 16px | `surface-50` | Discovery, trust, fast checkout |
| Seller centre | Dense | 14px | `surface-100` | Orders to act on, stock, earnings |
| Logistics hub | Dense | 14px | `surface-100` | Scan, sort, dispatch throughput |
| Admin | Dense | 14px | `surface-100` | Review queues, decisions with evidence |
| Rider (web) | Large | 16px | `white` | Current stop, next action, proof capture |
| Auth pages | Simple | 16px | `surface-50` | One form, clear errors, role clarity |

## 10. Things to avoid

- Gradients, glows, blurred blobs and glassmorphism.
- Wrapping every section in a rounded card with a shadow.
- Generic hero sections on non-landing pages.
- Centering everything; long text is left-aligned.
- Rows of identical stat tiles that nobody acts on. Show a metric only when it
  leads to an action or decision.
- Decorative icons beside every label or heading.
- Many competing badges, colors or primary buttons on one screen.
- Placeholder copy ("Lorem ipsum", "Welcome back!") and invented numbers.
- Red used for anything that is not an action, a step marker, the final total,
  the brand mark or an error.
- Black or near-black fills of any kind (see principle 5), including black
  modal backdrops and dark badges.

## 11. Implementation order

1. Confirm the typeface and any open decisions in this document.
2. Add the tokens (colors, type scale, radius, shadows) to `tailwind.config.js`
   and base styles, keeping the current pages working.
3. Build the shared primitives in `resources/js/Components/ui/`.
4. Redesign one pilot page end to end and review it against this document.
5. Adjust this document from what the pilot teaches, then redesign the remaining
   pages portal by portal.

## Open decisions

- Typeface choice (section 3).
- Whether work portals get a dark mode (not planned for the first pass).
- Pilot page selection (suggested: storefront home or seller order list).
