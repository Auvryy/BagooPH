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

## 2. Dimensionality: Modern Tactile Depth Over Flat Design

- **NO FLAT DESIGN**: Never use flat, lifeless, monotone rectangular layouts. Modern high-tier e-commerce and logistics interfaces must feel physical, responsive, and tactile.
- **Generous Corner Radiuses**: Embrace modern, friendly curvature—`rounded-2xl` for primary cards, modals, and station panels; `rounded-xl` for interactive controls, inputs, and action buttons. Sharp boxy 90-degree corners are banned.
- **Micro-Dimensional Shadows**: Depth is created using subtle, layered ambient drop shadows (`shadow-sm`, `shadow-md`, `shadow-[0_4px_20px_rgba(0,0,0,0.06)]`) and crisp border contrasts (`border-slate-200/90`, `border-stone-800`), separating interactive layers cleanly.
- **Micro-Animations on Every Interaction**: Every interactive component must have physical, responsive feedback:
  - **Hover**: Subtle vertical lift (`hover:-translate-y-0.5`) with shadow expansion (`hover:shadow-md`).
  - **Active / Click**: Tactile mechanical press-in (`active:translate-y-0 active:scale-[0.99]`).
  - **Transitions**: Silky ease transitions (`transition-all duration-150 ease-out`).
  - **Operational Motion**: Light, discrete progress animation (e.g. low-FPS box stepping between nodes on scan) that provides instant visual feedback for real work rather than distracting idle loops.
- **Sidebar & Core Accents**: The primary navigation sidebar, major headings, selected radio controls, active tab underlines, and selected map markers utilize **Neutral Obsidian (`#121316` / `#111215`)**, a refined deep graphite-black completely free of brown, amber, or sepia undertones. Active elements use Bagoo signature crimson (`#E00D42`).

## 3. Strict Design Rules: Zero AI Slop, No Emojis, No Gradients, No Fake "Online" Chips

> [!CAUTION]
> **ABSOLUTE PROHIBITIONS: NO AI SLOP, EMOJIS, SYNTHETIC GRADIENTS, OR FAKE STATUS CHIPS**
>
> 1. **Never Use AI Slop or Redundant/Repetitive Text**:
>    - **Zero Corporate/AI Bloat**: Eliminate tutorial lectures, redundant status repetitions, and artificial enterprise buzzwords (e.g., *"6 Stations Online"*, *"Continuous Throughput Active"*, *"Multi-parcel continuous conveyor system"*, *"Role: Unloader / Gatekeeper"*, *"The queue includes rider handovers awaiting intake..."*).
>    - **Direct, Functional Operational Copy**: Sorters and warehouse operators need instant, concise facts. Label things directly: *"Hub Pipeline"*, *"Inbound"*, *"Sorting"*, *"Outbound Bay"*, *"Waybill verified"*, *"Next hop: [Facility]"*. Never lecture the user about how logistics works.
>
> 2. **Never Use Fake "Online" Status Chips or Boilerplate Pills**:
>    - Strictly ban generic decorative colored chips that mimic fake status indicators (e.g. green badges with pulsing dots like `🟢 6 Stations Online`, `Live Throughput Active`, or boilerplate dashboard template pills).
>    - If status is necessary, display clean, high-contrast operational badges without decorative pulsing dots or artificial "online" vanity chips.
>
> 3. **Never Use Emojis in Design**:
>    - Strictly prohibit emojis (e.g. ⚡, 📦, 🚚, 🔥, 🚀, ✨, 🛑, 👉) in UI layouts, buttons, headings, tables, KPI cards, badges, alerts, toast notifications, and backend flash messages.
>    - Use crisp, professional vector icons from `lucide-react` exclusively (`Package`, `Truck`, `Scale`, `Layers`, `Send`, `CheckCircle2`, `AlertCircle`).
>
> 4. **Never Use Synthetic Gradients**:
>    - Strictly avoid multi-color AI gradients, rainbow borders, and background color fade meshes.
>    - Use solid, authentic surfaces: clean white (`#FFFFFF`), warm subtle canvas (`#F8F9FA`), neutral obsidian black (`#121316`), and Bagoo crimson (`#E00D42` / `#C20836`).
>    - Depth must be created exclusively via tactile drop shadows, crisp border contrast, and physical press states—never through background color fades.
>
> 5. **Notion-Inspired Spacing & Geometry Scale**:
>    - **Generous Padding & Rhythm**: Cards must have ample breathing room (`p-5` to `p-6`). Elements and labels must never touch card borders or feel squished.
>    - **Standard Select Dropdowns**: Always use clean, native-feel `<select>` dropdowns for facility and driver selection rather than confusing text inputs with up/down carets.
>    - **No Unreadable Tiny Fonts**: Never use micro fonts like `text-[10px]` or `text-[11px]` for operational data, timestamps, or sorting bin codes. Use minimum `text-xs` (12px) with bold tabular weights so operators can read them effortlessly under warehouse lighting.

## 4. Real Product Imagery in Categories

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
