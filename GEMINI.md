# 🧠 GEMINI.md — Project Guidelines & Master Context

> **INSTRUCTION FOR AI ASSISTANTS:**
> This project follows the exact curriculum requirements provided by the instructor. Keep features grounded, clean, and directly aligned with the PDF specifications. Do not add unnecessary third-party services or over-complicated systems.

---

## 🎨 1. Unified Brand, Theme & Design Philosophy

- **Primary Theme Color:** `#E00D42` (Crimson Red) shared **across all users and portals** (Buyer, Seller, Courier, and Admin).
- **Design Aesthetic:** Minimalist, clean, and top-tier professional software look (clean slate borders, crisp micro-shadows, balanced whitespace, readable typography).
- **Refined Corner Radius Rules (No "AI Bubble Slop"):**
  - **Inputs & Buttons:** `rounded-lg` (6px–8px). Structured and sleek, NOT overly round.
  - **Cards, Panels & Modals:** `rounded-xl` (10px–12px). Clean geometric layout.
  - **Avoid:** Generic AI extremes (`rounded-3xl` or excessive `rounded-full` everywhere).

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

### 🛡️ 4. Admin
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
3. **Consistent Theme & Styling:** Always use `#E00D42` for primary actions and adhere to the `rounded-lg` / `rounded-xl` professional border-radius standards.
