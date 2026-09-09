# Gemini / Agent Guidelines & Project Standards

## 1. WORKSPACE ISOLATION
- Strictly operate inside `/home/andy/Projects/bagoo`.
- Never touch or modify files outside of this repository.

## 2. COMMIT SUGGESTION DIRECTIVE
- At the end of **EVERY** response and after every milestone or set of changes, you must provide a ready-to-run **Git commit command** in the exact format `git commit -m "<type>: <description>"` so the user can instantly copy-paste it.
- Do NOT include `git add`; only output the full `git commit -m "..."` command directly.

## 3. ZERO-EMOJI INVARIANT
- Strictly zero emojis across all code, markup, CSS, commit messages, and assistant responses.

## 4. BAGOO MINIMALIST DESIGN STANDARD
- Deep obsidian canvas (`#08090A` / `#000000`).
- Surgical Crimson Red (`#E00D42`) restraint.
- Low word count, high visual density, and hardware-accelerated animations.
- Grounded in authentic local e-commerce: 10% flat platform commission, 90% seller take-home, Cash on Delivery (COD), and doorstep courier logistics.

## 5. OFFICIAL CURRICULUM FLOW & ROLE SPECIFICATIONS
- 6 Operational Roles: Buyer, Seller, Sorting Center/Logistics, Rider/Courier (Pickup), Rider/Courier (Delivery), Admin.
- Buyer Registration Gate: Buyer Registration -> Admin Approval -> Login (Buyers require Admin Approval before login access).
- Logistics Route: Seller -> Rider Pickup -> Sorting Center -> Assigned Rider -> Buyer (No direct seller-to-buyer deliveries).
- Canonical 13 Statuses: PLACED, CONFIRMED, PREPARING, READY_FOR_PICKUP, PICKED_UP, AT_SORTING_CENTER, SORTED, ASSIGNED_TO_RIDER, OUT_FOR_DELIVERY, DELIVERED, COMPLETED (Failure: DELIVERY_FAILED, RETURNED).
- Completion: DELIVERED -> Buyer Confirms Receipt -> COMPLETED.
- Categories: Strictly maintain the 14 master product categories in docs/CATEGORIES.md.
