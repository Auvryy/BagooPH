# BagooPH Repository Instructions

Keep work direct, scoped, and concise. Inspect existing code before changing it; do not invent routes, models, fields, or behavior.

## Safety and Git

- Work only inside this repository.
- Local `git add` and `git commit` are allowed. Never run `git push`; only the user publishes commits.
- For coding tasks, split work into small logical commits by feature or concern instead of one large commit.
- Report every commit created, including its short hash and subject.
- Preserve unrelated user changes.
- Never store real IP addresses, hostnames, credentials, private keys, or secrets. Use placeholders such as `<SERVER_IP>`.
- Tests must use isolated SQLite `:memory:` and must never wipe the PostgreSQL development/production database.

## Stack and Architecture

- Laravel 12, PHP, PostgreSQL, React, TypeScript, Inertia.js, Tailwind CSS, Vite, and Docker Compose.
- Keep business rules in backend services/models; controllers should coordinate requests and responses.
- Use authorization and validation server-side. Never trust prices, totals, ownership, roles, stock, or status transitions from the client.
- Prefer existing components, enums, services, patterns, and dependencies. Do not add external or paid services unless explicitly requested.

## Domain Invariants

- Roles: buyer, seller, courier, logistics/sorting hub, and admin. Pickup and delivery riders are courier phases, not separate account roles unless existing code says otherwise.
- Registration requires the documented approval authority before portal access.
- Canonical order flow:
  `PLACED -> CONFIRMED -> PREPARING -> READY_FOR_PICKUP -> PICKED_UP -> AT_SORTING_CENTER -> SORTED -> ASSIGNED_TO_RIDER -> OUT_FOR_DELIVERY -> DELIVERED -> COMPLETED`.
- Failure branch: `DELIVERY_FAILED -> RETURNED` or documented rescheduling.
- Parcels follow `Seller -> Pickup Rider -> Sorting Center -> Delivery Rider -> Buyer`; do not create direct seller-to-buyer delivery.
- Only the buyer's receipt confirmation advances `DELIVERED` to `COMPLETED`.
- Platform commission is 10% of product sales; seller share is 90%. Keep shipping/handling accounting separate.
- Preserve the 14 master categories in `docs/CATEGORIES.md`.
- The `User` password cast hashes values; seed/factory passwords must be raw strings to avoid double hashing.
- Preserve verified seed accounts for buyer, seller, courier, logistics hub, and admin.
- For local demo sign-in, inspect `database/seeders/DatabaseSeeder.php` instead of guessing credentials. The current seeded accounts use `@bagoo.test` emails and the password `Password1234`.

## Product Language and UI

- Use the original BagooPH/Bagoo identity and Philippine pesos (`PHP` / `₱`). Avoid third-party commercial brand names in code, fixtures, assets, and documentation.
- Customer-facing copy uses “Bag,” “Shopping Bag,” and “Add to Bag.” Follow existing database/class names such as `Cart` when required by the codebase.
- No emojis in code, UI copy, comments, commit messages, or repository documentation.
- Primary accent: `#E00D42`.
- Use Plus Jakarta Sans as the single typeface across every page, portal, component, chart, email, and data field. Do not introduce monospace fonts, `font-mono`, JetBrains Mono, Inter, or another display/body font.
- Buttons, inputs, badges, and compact controls use 2px radii (`rounded-xs`/`rounded-sm`); avoid pill-shaped UI.
- Cards use `rounded-md`/`rounded-lg`; dialogs may use `rounded-lg`/`rounded-xl`.
- Use visible borders: normally `border-slate-300` in light UI and `border-slate-700`/`800` in dark UI.
- Avoid gradients and ornamental clutter on authentication screens. Preserve the split-screen login/register layout.
- Floating navigation menus must be absolute and must not shift layout. Preserve the existing overlap/grace-period pattern.
- Keep layouts responsive and avoid continuous GPU-heavy effects.

## Documentation Routing

Read only the documentation relevant to the task:

- System lifecycle or cross-role logic: `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md`
- Buyer/shopping: `docs/BUYER_FLOWCHART.md`
- Seller/shop: `docs/SELLER_FLOW.md`
- Courier: `docs/COURIER_FLOW.md`
- Logistics/hub: `docs/SORTING_CENTER_LOGISTICS_FLOW.md` and `docs/MASTER_LOGISTICS_SPECIFICATION.md`
- Admin/commission/governance: `docs/ADMIN_FLOW.md`
- UI/style: `docs/STYLE_GUIDE.md`
- Schema: inspect migrations/models first; use `docs/SCHEMA.md` as supporting context.
- History only when requested: `docs/PROGRESS.md`.

When documentation conflicts with executable code, identify the mismatch. For curriculum workflow, the canonical flow above and `docs/SYSTEM_FLOW_AND_SPECIFICATIONS.md` are authoritative unless the user explicitly changes them.

## Verification and Responses

- Run focused tests for changed behavior, then broader tests when risk warrants it.
- For frontend changes, run `npm run build`; for backend/domain changes, run the relevant PHP tests. Use `./bagoo.sh` equivalents when host permissions or dependencies require Docker.
- Do not claim success when checks are blocked or failing; state the exact blocker.
- Keep final responses short: outcome, verification, commits created, and any blocker. Do not include token estimates or a commit command unless requested.
