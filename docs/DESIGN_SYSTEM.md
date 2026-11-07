# BagooPH Marketplace Design System & Style Guidelines

## 1. Brand Identity & Header Standard

> [!IMPORTANT]
> **SIGNATURE BRAND ACCENT HEADER**: The Buyer marketplace header is rendered in **Signature Brand Crimson Red (`#E00D42` / `#EF4444`)**, giving BagooPH an authoritative, instantly recognizable e-commerce identity akin to top Southeast Asian platforms (Shopee).
>
> - **Header Surface**: Solid `#E00D42` with white logo, white typography, and white navigation controls.
> - **Search Bar**: Crisp white rounded card with dark slate text, subtle placeholder, and a vibrant `#E00D42` search button.
> - **Trending Search Keywords**: Subtle white/rose-100 keyword links beneath the search bar.
> - **STRICT COLOR RULE**: Never use pure black (`#000000`, `bg-black`) or dark charcoal (`bg-slate-900`) for action buttons, marketing banners, countdown boxes, floating badges, or interactive elements.
> - **Brand Palette**:
>   - Primary Brand Red: `#E00D42` (Bagoo Crimson) and `#EF4444` (Vibrant Red)
>   - Primary Hover Red: `#C20836`
>   - Secondary Accent: `#F59E0B` (Amber) and `#F97316` (Warm Orange)
>   - Neutral Surfaces: Clean white (`#FFFFFF`), warm light slates (`bg-slate-50`, `bg-slate-100`), with crisp dividing borders (`border-slate-200`)

## 2. Dimensionality: Roundy, Modern & High-Density Tactile Surfaces

- **Roundy Feel**: Embrace friendly, modern curves (`rounded-2xl`, `rounded-full`, pill tags). Avoid sharp or dated flat boxy borders.
- **Snug Spacing (High-Density E-Commerce)**: Top e-commerce sites (Shopee, Lazada) feel full, energetic, and compact. Keep card margins and gutters compact (`gap-3` to `gap-4`, tight vertical margins) so users experience rich product discovery without barren white space.
- **Micro-Dimensionality**: Depth is achieved through soft ambient drop-shadows (`shadow-[0_4px_16px_rgba(15,23,42,0.06)]`), border highlights (`border-slate-200/90`), and tactile hover lifts (`hover:-translate-y-1 hover:shadow-[0_16px_36px_rgba(224,13,66,0.12)]`).
- **No-AI Gradients**: Strictly avoid synthetic AI multi-color gradients. Use authentic solid background tones, real product photography, tactile borders, and micro-shadows.

## 3. Real Product Imagery in Categories

- **Authentic Photography Over Generic Icons**: Category navigation tiles feature **real, isolated product photography** (e.g., actual boxy tee for Men's Apparel, real sling bag for Everyday Carry, real field watch for Accessories) rather than abstract vector line icons.
- **Layout**: High-density 2-row multi-column grid of compact rounded tiles with clean category titles below.

## 4. Platform Event Banners (Admin Campaigns)

- **Layout**: Left side features an interactive auto-rotating banner carousel (Mega Sale, 11.11 Festival, Tech Expo) with navigation indicators; right side features 2 stacked promotional event tiles (Partner Promos, Free Shipping).

## 5. Flash Deals Architecture & Showcase

1. **Card Layout**:
   - Aspect-square product photography with `-XX%` discount tag and heart button.
   - Bold red price in `#E00D42` with strike-through original compare price.
   - Integrated selling fast progress bar inside the card with fire icon and remaining stock count.
   - EXCLUDED: Product title, star ratings, and "Add to Bag" buttons.
2. **Countdown Timer**:
   - Time boxes use **Signature Red (`bg-[#E00D42]`)** and **Warm Orange (`bg-[#F97316]`)** with bold white mono typography.
3. **Seller Participation**:
   - Sellers schedule products into flash sale slots in Seller Centre (`Seller/ProductSales.tsx`), defining promotional quantity and discount percentages.
   - Active slots are dynamically consumed by the marketplace Flash Deals feed.

## 6. Algorithmic Top Products & Curated Discovery

- **Personalized Ranking**: Surfaces top-performing items weighted by sales velocity, user engagement, and category affinity.
- **Dynamic Ribbons**: Distinctive badge overlays (e.g. `#1 BESTSELLER`, `TRENDING · 3.4K SOLD`, `HOT -29%`).
- **Contextual Discovery**: Clicking a top product or collection routes to a dedicated filtered discovery collection (e.g. `/buyer/search?tag=everyday-carry`), ensuring related listings match the exact aesthetic and sub-niche.
