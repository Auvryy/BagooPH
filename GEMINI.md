# 🧠 GEMINI.md — Project Guidelines & Master Context

> **INSTRUCTION FOR AI ASSISTANTS:**
> This project follows the exact curriculum requirements provided by the instructor. Keep features grounded, clean, and directly aligned with the PDF specifications. Do not add unnecessary third-party services or over-complicated systems.

---

## 🎨 1. Unified Brand, Theme & Architectural Design Philosophy

- **Primary Theme Color:** `#E00D42` (Crimson Red) shared **across all users and portals** (Buyer, Seller, Courier, and Admin).
- **Design Aesthetic:** Architectural precision software look — clean slate structural borders, crisp micro-shadows, balanced whitespace, soft readable typography (`#1E293B` body text, `-0.008em` tracking).
- **Immutable Precision Corner Radius Scale (2px Buttons — No "AI Bubble Slop"):**
  - **Buttons, CTAs & Action Icons:** `rounded-xs` or `rounded-sm` (**2px**). Precision software look.
  - **Inputs, Search Bars & Selects:** `rounded-xs` or `rounded-sm` (**2px – 3px**).
  - **Badges, Tags & Status Chips:** `rounded-xs` (**2px**). **NEVER use rounded-full pill bubbles.**
  - **Cards, Panels & Data Tables:** `rounded-md` or `rounded-lg` (**4px – 6px**).
  - **Modals & Drawers:** `rounded-lg` or `rounded-xl` (**6px – 8px** max).
  - **Strict Ban:** Generic AI extremes (`rounded-3xl` or excessive `rounded-full` everywhere).
- **High-Visibility Structural Borders:**
  - Always use **`border-slate-300`** in light mode and **`border-slate-700` / `border-slate-800`** in dark mode.
  - **Never use faint/invisible borders** (`border-slate-100` or `border-black/5`) so users can easily distinguish sections, data tables, and bento cards.
- **Zero-Shift Overlap Navigation Standard:**
  - Dropdown menus must be strictly `absolute right-0 top-full -mt-0.5 pt-1 z-50` with a **250ms mouse-leave grace timeout**.
  - Dropdowns must **never expand the navbar height or shift elements**.
- **Design Authority:** Refer to [`docs/STYLE_GUIDE.md`](file:///home/andy/Projects/bagoo/docs/STYLE_GUIDE.md) for full component specifications.

---

## 👥 2. User Roles & Core Workflows

The system has **4 primary roles** (with logistics kept in mind for future extension):

### 🛒 1. Buyer
* **Registration:** Last name, First name, Middle initial, Sex, Email, Contact No, Birthday, Age (autogen), Address (Dropdown: Province, Municipality, Barangay; Manual: Street, House number), Upload ID.
* **Approval:** Registration status is `pending_approval` until Admin approves.
* **Core Functions:** Browse Categories, Search, View product & choose variations (color/size) and quantity, Cart (vouchers/discounts, payment mode, place order), Order status tracking (`to ship`, `in transit`, `out for delivery`), Rate/Feedback, Chat/Messaging, Account Management, Logout.

### 🏪 2. Seller
* **Registration:** Personal details, Business name, Line of business (category), Upload ID, Upload business permit. Requires Admin approval.
* **Core Functions:** Dashboard overview (stats, charts), Manage inventory (products, variations, prices, vouchers, stock), Order notifications, Prepare orders & print waybills/shipping labels, Hand over to courier, Handle customer feedback, Generate financial & profit reports (date picker: from/to), Chat/Messaging, Account Management, Logout.

### 🚚 3. Courier
* **Registration:** Personal details, Vehicle type, Plate number, Upload OR/CR, Upload Driver's License. Requires Admin approval.
* **Core Functions:** Delivery dashboard, Accept delivery requests (First-Come, First-Served), Pick up order from seller, Deliver & Complete order, Profit/earnings page, Delivery history, Chat/Messaging, Account Management, Logout.

### 🛡️ 4. Multi-Role Authentication & Navigation Pattern
* **Unified Smart Login (`/login`):** A single login page for all users. The backend automatically checks `user.role` on authentication and instantly redirects users to their designated interface:
  * `buyer` ➔ Marketplace / Buyer Home
  * `seller` ➔ `/seller/dashboard`
  * `courier` ➔ `/courier/deliveries`
  * `admin` ➔ `/admin/dashboard`
* **Buyer-First Public Interface:** The primary consumer landing page is tailored for buyers with frictionless shopping and registration.
* **Admin Obfuscation:** Admin controls and login buttons are **strictly hidden** from the public customer-facing UI (no "Admin Login" button on consumer headers). Accessible directly via `/admin/login` or via auto-redirect from `/login`.
* **Dedicated Partner Onboarding:**
  * **Seller Registration (`/seller/register`):** Dedicated merchant portal featuring shop name and business verification.
  * **Courier Registration (`/courier/register`):** Dedicated driver portal featuring fleet and vehicle onboarding.

### 🛡️ 5. Admin
* **Core Functions:** Dashboard overview, Manage account registrations (review submitted IDs/permits, approve/disapprove with email), Manage user accounts (activate, suspend, deactivate), Monitor seller compliance (category match, prohibited items), Manage complaints/disputes (review evidence, coordinate), Manage 10% platform commission, Generate reports (sales summary, commission report), Manage platform settings (announcements, policies), Chat/Messaging, Account Management, Logout.

---

## 🗄️ 3. Core Tech Stack
- **Backend:** Laravel 11 / 12 (PHP 8.4)
- **Frontend:** React + TypeScript + Tailwind CSS (via Inertia.js)
- **Database:** PostgreSQL 16
- **Environment:** Docker Compose (Nginx, PHP-FPM, PostgreSQL)
- **Helper Script:** `./bagoo.sh` (e.g., `./bagoo.sh start`, `./bagoo.sh fresh`)

---

## 🚫 4. Strict Guardrails (Anti-Hallucination)
1. **No Paid / External APIs:** Implement address selection, vouchers, waybills, and chat using local database logic.
2. **Mandatory Admin Approval:** Newly registered users cannot access their portal until approved by Admin.
3. **Consistent Theme & Styling:** Always use `#E00D42` for primary actions and strictly adhere to the **2px button corner radius (`rounded-xs`/`rounded-sm`)** and **high-visibility borders (`border-slate-300`)** defined in `docs/STYLE_GUIDE.md`.

---

## 🛑 5. Git, Commit Message & Response Protocol
1. **Never Commit Autonomously:** The AI must **NEVER** run `git add`, `git commit`, or `git push` autonomously. All git commands and repo pushes are manually handled by the user.
2. **Always Suggest a Commit Message:** At the end of every prompt completion, the AI must provide a clean, copy-pasteable Git commit message following conventional commit standards (e.g. `feat: ...`, `fix: ...`, `docs: ...`, `style: ...`).
3. **Always Output Changed File Paths:** At the end of every prompt completion, the AI must output a minimal list of the exact file paths modified or created during that prompt.
4. **Always Output Visual Changes & Page Navigation Guide:** At the end of every prompt completion, the AI must summarize what visual changes were made to the frontend and specify the exact page routes / navigation steps (e.g. `Where to check: /buyer/search, /buyer/profile, or Seller Cockpit ➔ /seller/reviews`) so the user immediately knows where to verify the updates.
5. **Always Output Total Lines Changed:** At the end of the changed files list, output a concise line showing the total number of lines modified/created (e.g. `Total lines changed: ~X lines`).
6. **Always Output Estimated Prompt Tokens:** At the end of every response, output a concise token usage estimate (e.g. `Estimated prompt tokens: ~X tokens`) and maintain strict token efficiency across all outputs.

---

## 🚫 6. Absolute Prohibition of Third-Party Brand Names (Copyright & Trademark Protection)
1. **Never Mention Big / Popular Brands:** The AI must **NEVER** mention, reference, compare, or include names of popular commercial brands or platforms (e.g., Shopee, SHEIN, Lazada, Amazon, Shopify, Stripe, Nike, Adidas, Apple, Sony, Zara, etc.) anywhere in the project.
2. **Strict Scope:** This applies to:
   - Source code (variable names, JSX/HTML text, `<title>` tags, `<Head>` tags, meta descriptions)
   - Code comments and inline annotations
   - Database seeders, migrations, and mock data
   - Documentation files (`PROGRESS.md`, `README.md`, walkthroughs, plans)
   - AI assistant conversational responses and commit messages
3. **100% Original BagooPH Identity:**
   - Platform Name: **BagooPH** / **Bagoo**
   - Logistics Fleet: **Bagoo Express**
   - Currency: Philippine Pesos (`₱` / `PHP`)
   - Brand Color: `#E00D42` (Crimson)
   - Shopping Container: **"Bag"** / **"Shopping Bag"** / **"Add to Bag"** (Never "Cart")
   - All sample products and stores must be 100% original Bagoo-branded assets.

---

## 🔒 7. Absolute Privacy & Server Credential / IP Address Protection
1. **Zero IP Address Leaks in Files:** The AI must **NEVER** write or persist real server IP addresses, Azure hostnames, private keys, or SSH connection credentials into any repository files (including `.md`, documentation, code comments, or commit messages).
2. **Placeholders Only:** If documentation requires reference to server setup, always use generic placeholders like `<SERVER_IP>` or `<AZURE_HOST>`.

---

## 📖 8. Selective Documentation Activation Rule
1. **Domain-Triggered Reading:** Whenever the user's prompt mentions a specific domain or user role, the AI must actively reference and strictly follow the corresponding documentation section in `docs/` and `GEMINI.md`:
   - **"Buyer" / "Shopping" / "Bag" ➔** Read [`docs/BUYER_FLOWCHART.md`](file:///home/andy/Projects/bagoo/docs/BUYER_FLOWCHART.md) & Buyer section in `GEMINI.md`.
   - **"Seller" / "Merchant" / "Shop" ➔** Read [`docs/SELLER_FLOW.md`](file:///home/andy/Projects/bagoo/docs/SELLER_FLOW.md) & Bento Matrix dashboard rules.
   - **"Courier" / "Logistics" / "Sorting Center" ➔** Read [`docs/COURIER_FLOW.md`](file:///home/andy/Projects/bagoo/docs/COURIER_FLOW.md) & dispatch workflows.
   - **"Admin" / "Governance" / "Commission" ➔** Read [`docs/ADMIN_FLOW.md`](file:///home/andy/Projects/bagoo/docs/ADMIN_FLOW.md) & 10% commission ledger rules.
   - **"Style" / "Theme" / "UI" ➔** Read [`docs/STYLE_GUIDE.md`](file:///home/andy/Projects/bagoo/docs/STYLE_GUIDE.md).
2. **Concise Progress Logging:** Keep `docs/PROGRESS.md` ultra-concise, focusing on functional outcomes rather than verbose conversational summaries. Batch and record completed milestones in high-density summary format.
