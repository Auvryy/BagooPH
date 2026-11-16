# Website click-through audit — 16 Nov 2026

Method: a browser signed in as each role with the seeded demo accounts and followed every in-app link
(GET only, no data-changing buttons), checking each page for errors, failed requests, placeholder text,
dead ends and overflow at 375px. Key journeys were also walked by hand on a phone-sized screen.

| Role | Pages crawled | Result after fixes |
|---|---|---|
| Guest | 40 | Clean (orders page asks to sign in, as intended) |
| Buyer | 70 | Clean |
| Seller | 70 | Clean (6 empty states that explain themselves) |
| Admin | 70 + every sidebar/tab page | Clean (1 empty state) |
| Hub worker | 25 | Clean (empty recovery list, printable command sheet) |
| Rider | 9 | Clean (empty messages) |

## Fixed

| # | Where | Problem | Fix |
|---|---|---|---|
| 1 | Storefront as seller/rider/hub/admin | Bag, Track order, Messages and account links answered 403 | Shown to shoppers only; other roles get "My portal" |
| 2 | Admin account activity / closure / identity corrections | No way back | Users / Account breadcrumb |
| 3 | Admin Moderation & penalties | Server error (missing column) and not in the sidebar | Query fixed, listed under Governance |
| 4 | Admin Finance | Eight sidebar links | One Finance link with tabs |
| 5 | Admin logistics | One company at a time, wrong worker/rider counts, ids instead of names | Company directory with filters and one map; real counts; names |
| 6 | Admin Users | Four stacked buttons per row | Open account + More menu, badges |
| 7 | Buyer home / catalogue | Raw status "AT SORTING_CENTER" | Human wording |
| 8 | Rider phone menu | Wrapped onto two rows | Four items on one row |
| 9 | Seller orders | Completed order note styled as an error | Neutral note |
| 10 | Buyer, seller, admin pages | Black used for selected states and buttons | Brand tint or secondary button |
| 11 | Seller product editor and catalogue | Drawer, misaligned inputs, letters accepted in numbers | Centered dialog, strict number inputs, aligned table |

## Still open (smaller, not blocking)

- Buyer orders on phones lists the account menu before the orders; put the orders first on small screens.
- Hub header title is cut to "Op…" beside the Scan button on phones; the full title is repeated below it.
- Some marketplace hero and landing sections use dark panels; they are surfaces, not accents, and were left.
- Several admin finance pages still open with long explanation paragraphs; move them behind a "How this works" disclosure.

Money: `php artisan bagoo:audit-money` checks every order's total, seller/platform split, shipping shares and
cash record; it reports 0 issues on the dev data and in a test covering COD, wallet, voucher, heavy and cancelled orders.
