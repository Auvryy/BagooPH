# 🎨 UI Design System & Professional Styling Guide

This guide establishes the visual identity, UI rules, and design principles for the platform. The goal is a **sleek, modern, and restrained aesthetic** (reminiscent of top-tier products like Stripe, Linear, or Vercel Marketplace), avoiding generic "AI-generated" designs.

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

### Neutrals (Minimalist Dark/Light foundation):
- **Backgrounds:** Clean crisp whites (`#FFFFFF`) with subtle off-white canvas (`#F8FAFC` / `bg-slate-50`).
- **Borders:** Crisp, razor-thin borders (`border-slate-200` or `border-slate-200/80`). Avoid thick, heavy outlines.
- **Typography:** High-contrast slate hierarchy (`text-slate-900` for headings, `text-slate-600` for body, `text-slate-400` for muted captions).

---

## 📐 2. Border Radius Philosophy (No "AI Pill Slop")

> **CRITICAL RULE ON CORNER RADIUS:**
> Avoid excessive, overly bubbly shapes (e.g. `rounded-3xl` or `rounded-full` on everything) and avoid harsh sharp corners (`rounded-none`).
> Use refined, geometric radii that give a sharp, premium software feel:

| UI Component | Standard Tailwind Class | Approximate Radius | Description |
|---|---|---|---|
| **Buttons (Primary & Secondary)** | `rounded-lg` or `rounded-md` | 6px – 8px | Sleek, compact, professional look |
| **Form Inputs & Select Dropdowns** | `rounded-lg` | 8px | Clean rectangular structure |
| **Cards & Content Panels** | `rounded-xl` | 12px | Structured framing without bubbly bubbles |
| **Modals & Dialogs** | `rounded-xl` | 12px | Balanced viewport presence |
| **Status Tags / Pill Badges** | `rounded-md` or `rounded-full` | 6px / Pill | Compact indicators |
| **Product Image Containers** | `rounded-lg` | 8px | Clean aspect ratio borders |

---

## 💎 3. Elevation, Borders & Shadows

- **Subtle, Crisp Shadows:** Avoid massive blurry drop-shadows. Use `shadow-xs` or `shadow-sm` layered with hairline borders:
  ```html
  <!-- Example: Clean Modern Card -->
  <div class="bg-white border border-slate-200/80 rounded-xl shadow-xs p-6 hover:border-slate-300 transition-colors">
  ```
- **Micro-Interactions:** Quick, smooth transitions (`duration-150 ease-out` or `duration-200`). Use subtle active press effect: `active:scale-[0.98]` or `active:scale-[0.99]`.

---

## 🔘 4. Standard Component Templates

### Primary Action Button:
```tsx
<button className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-semibold rounded-lg shadow-xs transition duration-150">
  <span>Confirm & Proceed</span>
</button>
```

### Secondary / Ghost Button:
```tsx
<button className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 hover:text-slate-900 text-xs font-medium rounded-lg transition duration-150">
  <span>Cancel</span>
</button>
```

### Form Input:
```tsx
<input 
  type="text" 
  className="w-full px-3.5 py-2 text-xs text-slate-900 bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] placeholder:text-slate-400 transition" 
  placeholder="Enter value..."
/>
```

### Clean Table Row:
```tsx
<tr className="border-b border-slate-100 hover:bg-slate-50/60 transition duration-100">
  <td className="py-3.5 px-4 text-xs font-medium text-slate-900">#TRK-10928</td>
  <td className="py-3.5 px-4 text-xs text-slate-600">Pending Pickup</td>
</tr>
```
