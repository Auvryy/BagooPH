# 🎨 BagooPH Unified Design System & UI Architecture Guide

> **CORE DESIGN DIRECTIVE FOR ALL AGENTS & DEVELOPERS:**
> This document defines the permanent, immutable design tokens, layout patterns, and styling rules for BagooPH across **all 5 portals** (Landing, Buyer, Seller, Courier, Admin).
> Follow these exact patterns to maintain visual harmony, razor-sharp precision, and eye-friendly ergonomics. Do NOT deviate or hallucinate alternative design styles.

---

## 🏛️ 1. Design Philosophy: "Architectural Precision & Soft Ergonomics"

BagooPH avoids generic, bloated "AI bubble slop" (overly rounded pill shapes, washed-out invisible borders, and jarring high-saturation contrasts). 
Instead, BagooPH adopts an **architectural precision software aesthetic**:
- **Crisp 2px Corner Geometry:** Buttons, inputs, and tags feel precise, sharp, and purposeful.
- **High-Visibility Structural Borders:** Sections, cards, tables, and bento grids are clearly demarcated with visible slate boundaries for maximum visual accessibility.
- **Soft Eye-Friendly Typography:** Soft Slate-800 body text with negative tracking (`-0.008em`) and generous line-height (`1.55`) ensures long hours of comfortable usage.
- **Instant Seamless Navigation:** Dropdowns overlap the trigger with zero 1-pixel gaps, zero navbar height shifts, and 250ms grace timeouts.

---

## 🔴 2. Color Palette & Accessibility Tokens

The **Crimson Red (`#E00D42`)** is the sole primary accent color across all roles, paired with structured slate neutrals.

### Brand Accent Tokens:
| Token | Hex Code | Tailwind / CSS | Purpose |
|---|---|---|---|
| `brand-50` | `#FDF2F4` | `bg-[#FDF2F4]` | Active menu highlights, subtle tag backgrounds |
| `brand-100` | `#FCE7EA` | `bg-[#FCE7EA]` | Soft alert badges, notification containers |
| `brand-500` / `brand` | `#E00D42` | `bg-[#E00D42] text-[#E00D42]` | Primary CTA buttons, active tabs, brand logo, key metrics |
| `brand-600` | `#C20836` | `hover:bg-[#C20836]` | Button hover state |
| `brand-700` | `#A1052B` | `active:bg-[#A1052B]` | Button pressed / active state |

### Neutrals & Structural Contrast:
| Element | Light Theme | Dark / Terminal Theme | Rule / Purpose |
|---|---|---|---|
| **Canvas Background** | `#F8FAFC` (`bg-slate-50`) / `#F4F3EF` | `#0A0D14` / `#111319` | Warm, neutral foundation |
| **Card / Panel Background** | `#FFFFFF` (`bg-white`) | `#111319` / `#1E222D` | Clean container surface |
| **Headings (H1–H6)** | `#0F172A` (`text-slate-900`) | `#FFFFFF` (`text-white`) | Bold, authoritative title hierarchy |
| **Body Text** | `#1E293B` (`text-slate-800`) | `#E2E8F0` (`text-slate-200`) | Soft on the eyes; never use harsh #000 for body |
| **Muted Labels / Meta** | `#64748B` (`text-slate-500`) | `#94A3B8` (`text-slate-400`) | Captions, dates, subtitles |
| **Structural Borders** | `#CBD5E1` (`border-slate-300`) | `#334155` (`border-slate-700`) | **Mandatory high-visibility borders** |
| **Section Dividers** | `rgba(0, 0, 0, 0.22)` | `rgba(255, 255, 255, 0.15)` | Clear layout partition |

> [!IMPORTANT]
> **STRICT BAN ON INVISIBLE BORDERS:**
> Never use faint borders (`border-slate-100` or `border-black/5`) for cards and tables. Low-contrast borders make sections blend together and cause eye strain. Always use **`border-slate-300`** in light mode and **`border-slate-700` / `border-slate-800`** in dark mode.

---

## 📐 3. Permanent Corner Radius Scale (2px Buttons)

All corner radii are strictly standardized via `tailwind.config.js`:

```javascript
// tailwind.config.js
borderRadius: {
    xs: '2px',       // Primary & Secondary Buttons, Badges, Search Inputs
    sm: '2px',       // Form Selects, Small Action Controls
    DEFAULT: '2px',  // Standard UI Elements
    md: '4px',       // Inner Sub-Cards, Floating Tooltips
    lg: '4px',       // Standard Content Cards, Bento Panels
    xl: '6px',       // Modals, Flyout Drawers, Dialogs
    '2xl': '8px',    // Hero Showcase Banners
}
```

### Component Radius Directory:
| UI Component | Required Tailwind Class | Radius (px) | Visual Standard |
|---|---|---|---|
| **Buttons (All CTAs, Links, Icons)** | `rounded-xs` or `rounded-sm` | **2px** | Sharp, architectural, precision software look |
| **Inputs, Textareas & Search Bars** | `rounded-xs` or `rounded-sm` | **2px** | Crisp rectangular form field |
| **Status Badges, Chips & Tags** | `rounded-xs` | **2px** | Sharp rectangular indicators (**NO round pill bubbles**) |
| **Bento Cards & Data Tables** | `rounded-md` or `rounded-lg` | **4px – 6px** | Distinctly framed modular cards |
| **Modals, Dialogs & Drawers** | `rounded-lg` or `rounded-xl` | **6px – 8px** | Focused overlay panels |
| **Brand Logo Box** | `rounded-xs` or `rounded-sm` | **2px – 4px** | Iconic square-proportioned emblem |

---

## 🖱️ 4. Navigation Dropdown Architecture (Zero-Shift Overlap Pattern)

All navigation dropdowns (User Profile, Quick Settings, Filter Menus) must adhere to the **Zero-Shift Instant Overlap Standard**:

```tsx
/*
 * 1. Parent container must be `relative`
 * 2. Dropdown wrapper must be `absolute right-0 top-full -mt-0.5 pt-1 z-50`
 * 3. Incorporate 250ms mouse-leave grace timeout via useRef<NodeJS.Timeout>
 */
<div 
    className="relative"
    onMouseEnter={handleMenuEnter}
    onMouseLeave={handleMenuLeave}
>
    {/* Trigger Button */}
    <button
        type="button"
        onClick={() => setMenuOpen(!menuOpen)}
        className="flex items-center gap-2 p-1.5 rounded-xs hover:bg-slate-100 transition border border-transparent hover:border-slate-300"
    >
        {/* Avatar / Content */}
    </button>

    {/* Dropdown Menu Overlay */}
    {menuOpen && (
        <div 
            className="absolute right-0 top-full -mt-0.5 pt-1 w-60 z-50 animate-scale-in"
            onMouseEnter={handleMenuEnter}
            onMouseLeave={handleMenuLeave}
        >
            <div className="bg-white rounded-md shadow-2xl border border-slate-300 py-1.5 text-slate-800 font-sans">
                {/* Menu items */}
            </div>
        </div>
    )}
</div>
```

### Key Principles:
1. **Zero Navbar Height Expansion:** The dropdown is strictly `absolute`, having zero footprint in the navbar flex layout.
2. **Instant Mouse-In Overlap (`-mt-0.5 pt-1`):** The wrapper begins 0.5px inside the button boundary, so the cursor is instantly within the hover zone before exiting the button.
3. **250ms Grace Period:** Allows slow or diagonal cursor movements without flickering or vanishing.

---

## 🔤 5. Typography & Spacing System

- **Primary Typeface:** `'Plus Jakarta Sans', 'Inter', system-ui, sans-serif`
- **Monospace Data Font:** `'JetBrains Mono', 'Fira Code', monospace` (used for prices `₱`, order IDs `BGO-1234`, telemetry gauges, and badges).
- **Body Rhythm:** `letter-spacing: -0.008em; line-height: 1.55;`
- **Heading Rhythm:** `letter-spacing: -0.018em; font-weight: 800 or 900;`
- **Spacious Layouts:** Maintain clean, breathable white space (`gap-4` to `gap-6`, `p-5` to `p-8`). Never crowd buttons or stack text tightly.

---

## 💎 6. Standard Code Snippets

### Primary Action Button (2px Radius):
```tsx
<button className="inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-mono rounded-xs shadow-xs transition duration-150 uppercase tracking-wider">
    <Plus className="w-3.5 h-3.5" />
    <span>New Listing</span>
</button>
```

### Secondary / Outline Button (2px Radius):
```tsx
<button className="inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 text-slate-800 text-xs font-bold font-mono rounded-xs transition duration-150">
    <Filter className="w-3.5 h-3.5 text-slate-500" />
    <span>Filters</span>
</button>
```

### High-Visibility Bento Card:
```tsx
<div className="bg-white border border-slate-300 rounded-md shadow-xs p-5 hover:border-slate-400 transition-colors">
    <div className="flex items-center justify-between pb-3 border-b border-slate-200">
        <h3 className="text-sm font-black text-slate-900 tracking-tight">Active Deliveries</h3>
        <span className="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-300 rounded-xs text-[10px] font-bold font-mono uppercase">
            Live
        </span>
    </div>
    <div className="pt-3">
        {/* Content */}
    </div>
</div>
```

### Status Badge (2px Radius):
```tsx
<span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-xs text-[10px] font-bold font-mono uppercase bg-slate-100 text-slate-700 border border-slate-300">
    Processing
</span>
```

---

## 🚫 7. Forbidden Anti-Patterns (Do NOT Introduce)

1. ❌ **No `rounded-full` or `rounded-3xl` on buttons and cards:** Keep buttons strictly at 2px (`rounded-xs`/`rounded-sm`).
2. ❌ **No faint or washed-out borders (`border-slate-100`):** Always use `border-slate-300` for clear visual distinction.
3. ❌ **No pure `#000000` body text:** Use soft, eye-friendly `#1E293B` (`text-slate-800`).
4. ❌ **No continuous GPU animations on scroll:** Avoid heavy SVG `feTurbulence` grain filters or full-screen mouse spotlight canvas overlays.
5. ❌ **No non-absolute dropdowns:** Floating menus must never shift or push navbar elements.
