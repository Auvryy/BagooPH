# Scope: Milestone M1 — Core Schema, KYC Registration & Admin Approval Gate

## Architecture & Responsibilities
- **Milestone Name**: Milestone M1 (Core Schema, KYC Registration & Admin Approval Gate)
- **Status**: DONE
- **Gate Verdict**: PASS (Iteration 1: 2x APPROVE, 2x Challenger APPROVE, Forensic Audit CLEAN)

### Completed Deliverables:
1. **Database Schema & Migrations**:
   - `database/migrations/2026_08_27_000001_add_kyc_fields_to_users_table.php`: Added `kyc_status` (enum default 'pending_approval', indexed), `id_document_path`, `business_permit_path`, `driver_license_path`, `or_cr_path`, `kyc_feedback`, `kyc_submitted_at`, `kyc_reviewed_at`.
   - `database/migrations/2026_08_27_000002_create_courier_profiles_table.php`: Created `courier_profiles` table with foreign key `user_id` (unique, cascadeOnDelete), `vehicle_type`, `plate_number`, `license_number`, `or_cr_status`, `is_available`.
   - `database/migrations/2026_08_27_000003_add_variant_fields_to_cart_items_and_order_items_tables.php`: Added `color`, `size`, `sku_snapshot` to `cart_items` and `order_items`.

2. **Enums & Eloquent Models**:
   - `app/Enums/KycStatus.php`: Backed enum with `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, `label()`, `badgeClass()`.
   - `app/Models/CourierProfile.php`: Eloquent model with casts and `user()` BelongsTo relation.
   - `app/Models/User.php`: Extended fillable, casts, `courierProfile()` HasOne relation, `isKycApproved()`, `isKycPending()`, `isKycRejected()`.
   - `app/Models/CartItem.php` & `app/Models/OrderItem.php`: Added variant fields to `$fillable`.

3. **Multi-Role KYC Registration & File Uploads**:
   - `app/Http/Controllers/Auth/RegisteredUserController.php`: Multi-role registration for Buyer, Seller, and Courier. Stores uploaded documents on `public` disk under `kyc_documents`. Creates associated `Shop` (for seller) and `CourierProfile` (for courier). Redirects new users to `route('kyc.pending')`.
   - `resources/js/Pages/Auth/Register.tsx`, `SellerRegister.tsx`, `CourierRegister.tsx`: Modern registration forms with file upload dropzones, size preview, and `forceFormData: true` Inertia submission.

4. **Auth Gating & Role Middleware**:
   - `app/Http/Middleware/RoleMiddleware.php`: Enforces access gating. Unapproved (`pending_approval`) and rejected (`rejected`) users are held at `/pending-approval`. Suspended users are logged out with an error banner. Admin users bypass the gate.
   - `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: Redirects unapproved logins to `/pending-approval`.
   - Universal `/dashboard` route: Routes users based on role and KYC status.
   - `resources/js/Pages/Auth/PendingApproval.tsx`: Dual-state UI for In-Review status and Rejected status with compliance officer feedback and document resubmission form posting to `POST /kyc/resubmit`.

5. **Admin KYC Verification Queue**:
   - `app/Http/Controllers/Admin/AdminKycController.php`: Paginated queue with status/role filtering and search. `approve()` sets `kyc_status = 'approved'`, `status = 'active'`, and activates shop/courier profile. `reject()` validates reason, sets `kyc_status = 'rejected'`, records `kyc_feedback`, and deactivates shop/courier.
   - `resources/js/Pages/Admin/KycQueue.tsx`: Metric summary cards, filter pills, applicant table, high-resolution document preview modal for ID, Business Permit, Driver's License, and OR/CR, 1-click Approve, and Reject with reason modal.
   - `resources/js/Layouts/DashboardLayout.tsx`: Admin sidebar navigation link with ShieldCheck icon.

6. **Field Mismatch Bugfix & Cart Variants**:
   - `app/Http/Controllers/Buyer/CheckoutController.php` (line 168) and `app/Http/Controllers/Seller/SellerOrderController.php` (line 100): Fixed `'recipient_phone'` to `'delivery_phone'` in `Delivery::create(...)`.
   - Cart and Order item variants: `CartController::store` matches by `product_id`, `color`, and `size`; `CheckoutController::store` copies variant fields to `order_items`.

7. **Database Seeders & Demo Accounts**:
   - `database/seeders/DatabaseSeeder.php`: Seeded all 5 base roles (`admin`, `seller`, `buyer`, `courier`, `logistics`) with `kyc_status = 'approved'` and `status = 'active'`. Seeded `CourierProfile` for `courier@bagoo.test`. Seeded demo pending and rejected accounts.

8. **Automated Verification Results**:
   - 81+ automated tests passing with 294 assertions.
   - 0 TypeScript / Vite build errors (`npm run build`).
   - 43 adversarial attack scenarios verified (100% defended).
   - Forensic audit verdict: **CLEAN**.
