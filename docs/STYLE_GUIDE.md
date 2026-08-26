# 🎨 UI Design System & Professional Styling Guide

This guide establishes the visual identity, UI rules, and design principles for the BagooPH platform. The goal is a **sleek, modern, and restrained aesthetic** with high legibility, crisp 2px–6px corner geometry, and clear high-contrast section borders.

---

## 🔴 1. Unified Brand Palette (`#E00D42`)

The crimson red (`#E00D42`) is the **sole accent & primary brand color** shared across all roles (Buyer, Seller, Courier, Admin).

| Token | Hex Code | Purpose |
|---|---|---|
| `brand-50` | `#FDF2F4` | Subtle tint for active items, hover backgrounds, tag pills |
| `brand-100` | `#FCE7EA` | Soft border / card highlight |
| `brand-500` / `brand` | `#E00D42` | Primary interactive elements (buttons, active tabs, links, key metrics) |
| `brand-600` | `#C20836` | Primary hover state |
| `brand-700` | `#A1052B` | Active / click / pressed state |

### Neutrals & High-Visibility Structural Contrast:
- **Backgrounds:** Clean crisp whites (`#FFFFFF`) with subtle canvas (`#F8FAFC` / `bg-slate-50`).
- **High-Visibility Borders:** Clear, distinguishable borders (`border-slate-300` or `border-slate-300/90` in light mode; `border-slate-800` / `border-slate-700` in dark mode). **Never use faint/invisible borders** (`border-slate-100` or `border-black/5`) so users can easily distinguish sections, cards, and data matrices.
- **Typography:** Soft high-contrast slate hierarchy (`text-slate-900` for headings, `text-slate-800` for body, `text-slate-500` for captions).

---

## 📐 2. Crisp Corner Radius Philosophy (2px Buttons & Architectural Edges)

> **CRITICAL RULE ON CORNER RADIUS:**
> Avoid bubbly "AI pill slop" (e.g. `rounded-2xl`, `rounded-3xl` or `rounded-full` on everything).
> Use crisp, architectural radii that give a sharp, precision engineering feel:

| UI Component | Standard Tailwind Class | Radius Size | Description |
|---|---|---|---|
| **Buttons (Primary & Secondary)** | `rounded-xs` or `rounded-sm` | **2px** | Crisp, architectural, precision software look |
| **Form Inputs & Select Dropdowns** | `rounded-xs` or `rounded-sm` | **2px – 3px** | Clean rectangular structure |
| **Cards & Content Panels** | `rounded-md` or `rounded-lg` | **4px – 6px** | Structured framing with distinct boundaries |
| **Modals & Dialogs** | `rounded-lg` | **6px** | Balanced viewport presence |
| **Status Tags / Badges** | `rounded-xs` or `rounded-sm` | **2px** | Compact, sharp indicators (no round pill bubbles) |
| **Product Image Containers** | `rounded-xs` or `rounded-sm` | **2px – 4px** | Clean aspect ratio framing |

---

## 🖱️ 3. Hover Menu & Dropdown Bridge Standard

> **HOVER DROPDOWN GAP PREVENTION:**
> All hover dropdowns (Profile, Settings, Filters) must implement a seamless physical bridge (`before:content-[''] before:absolute before:-top-3 before:left-0 before:right-0 before:h-3`) and a **250ms mouse-leave grace timeout**.
> Users must NEVER experience dropdown disappearing mid-movement when moving the cursor from trigger to menu.

---

## 💎 4. Standard Component Templates

### Primary Action Button (2px Radius):
```tsx
<button className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-semibold rounded-xs shadow-xs transition duration-150 font-mono uppercase tracking-wider">
  <span>Confirm & Proceed</span>
</button>
```

### Secondary / Ghost Button (2px Radius):
```tsx
<button className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-white hover:bg-slate-100 border border-slate-300 text-slate-800 text-xs font-medium rounded-xs transition duration-150">
  <span>Cancel</span>
</button>
```

### High-Visibility Card:
```tsx
<div className="bg-white border border-slate-300 rounded-md shadow-xs p-6 hover:border-slate-400 transition-colors">
  <!-- Content -->
</div>
```
