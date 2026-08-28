# DISPATCH — 2026-08-27T08:22:53Z

You are the Sub-Orchestrator for Milestone M1: Core Schema, KYC Registration & Admin Approval Gate.
Your working directory is: /home/andy/Projects/bagoo/.agents/suborch_milestone_1

MANDATORY: Read /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md and /home/andy/Projects/bagoo/PROJECT.md before starting work.
Also read previous survey reports at /home/andy/Projects/bagoo/.agents/explorer_survey_db/handoff.md and /home/andy/Projects/bagoo/.agents/explorer_survey_ui/handoff.md.

Scope of Milestone M1:
1. Feature 1 (Multi-Role KYC Registration & Schema Extensions):
   - Migrations & Models: Add KYC columns to `users` (`kyc_status` enum defaulting to `pending_approval`, `id_document_path`, `business_permit_path`, `driver_license_path`, `or_cr_path`, `kyc_feedback`, `kyc_submitted_at`, `kyc_reviewed_at`, name/address profile fields).
   - Create `courier_profiles` table & model (`user_id`, `vehicle_type`, `plate_number`, `license_number`, `or_cr_status`, `is_available`).
   - Add variant fields (`color`, `size`, `sku_snapshot`) to `cart_items` and `order_items` tables & models.
   - Fix `delivery_phone` vs `recipient_phone` field mismatch in CheckoutController and SellerOrderController.
   - Support file upload / simulated document submission in `Register.tsx`, `SellerRegister.tsx`, `CourierRegister.tsx`, and `RegisteredUserController.php`.
2. Feature 2 (Auth & Role KYC Approval Gate & Admin Verification Queue):
   - Enforce status check in `RoleMiddleware.php` and `LoginRequest.php`: users with `status = 'pending_approval'` or `kyc_status = 'pending_approval'` or `'rejected'` are blocked from role dashboards and directed to `/pending-approval` (`PendingApproval.tsx`).
   - Admin KYC Queue in `AdminDashboardController` / `AdminKycController` and `Admin/Users.tsx` (or `Admin/KycQueue.tsx`):
     * List pending applicants with role, submission date, and submitted documents.
     * Document preview modal (inspect Gov ID, Business Permit, Driver's License, OR/CR).
     * One-click `Approve` (sets `kyc_status = 'approved'`, `status = 'active'`).
     * `Reject` modal with reason/feedback (sets `kyc_status = 'rejected'`, `kyc_feedback = reason`).
     * Once approved, user can immediately log in and access their role dashboard.

Execution Instructions:
- Run the full iteration loop: Explorer -> Worker -> Reviewer -> Challenger -> Forensic Auditor -> Gate check in GATE_STATUS.md.
- Ensure Worker includes the mandatory integrity warning.
- Verify migrations run cleanly (`./bagoo.sh migrate` or in SQLite test runner), build passes (`npm run build`), and automated tests pass.
- When gate passes, write handoff.md and report completion to parent orchestrator.
