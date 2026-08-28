## 2026-08-27T08:23:45Z
<USER_REQUEST>
You are Explorer 2 for Milestone M1 (Auth, Gate Middleware, KYC Registration & Admin Queue Backend).
Your working directory is /home/andy/Projects/bagoo/.agents/explorer_m1_backend

MANDATORY INSTRUCTIONS:
1. Read /home/andy/Projects/bagoo/.agents/ORIGINAL_REQUEST.md
2. Read /home/andy/Projects/bagoo/PROJECT.md
3. Read /home/andy/Projects/bagoo/.agents/suborch_milestone_1/SCOPE.md
4. Read /home/andy/Projects/bagoo/.agents/explorer_survey_db/handoff.md

Your Task:
- Inspect backend authentication, registration, middleware, and admin controllers:
  1. `app/Http/Controllers/Auth/RegisteredUserController.php` & registration routes:
     * How multi-role registration works for customer, seller, courier.
     * Handling file uploads for KYC documents (Gov ID, Business Permit, Driver's License, OR/CR).
     * Validations, storage path (e.g. `storage/app/public/kyc_documents` or `storage/app/kyc_documents`), and setting initial `status` & `kyc_status`.
  2. Auth gate enforcement:
     * `app/Http/Middleware/RoleMiddleware.php` & `app/Http/Requests/Auth/LoginRequest.php` / `AuthenticatedSessionController.php`:
     * Blocking users with `kyc_status = 'pending_approval'`, `status = 'pending_approval'`, or `kyc_status = 'rejected'` from accessing role dashboards.
     * Redirecting pending/rejected users to `/pending-approval` route.
     * Allowing access to `/pending-approval`, `/logout`, and KYC resubmission endpoints.
  3. Admin KYC Queue API / Controllers:
     * Check `AdminDashboardController.php` or propose `AdminKycController.php` with routes in `routes/web.php` or `routes/admin.php`.
     * Listing pending applicants (with role, profile, document URLs, submission timestamp).
     * Action endpoint: `POST /admin/kyc/{user}/approve` (updates `kyc_status = 'approved'`, `status = 'active'`, `kyc_reviewed_at`).
     * Action endpoint: `POST /admin/kyc/{user}/reject` (updates `kyc_status = 'rejected'`, `kyc_feedback = $reason`, `kyc_reviewed_at`).
     * Check document URL serving / viewing mechanism for admin preview.
- You are read-only! DO NOT write application source code.
- Write your comprehensive analysis and actionable backend controller & route implementation plan to `/home/andy/Projects/bagoo/.agents/explorer_m1_backend/handoff.md`.
- Send a message back to parent suborchestrator when complete.
</USER_REQUEST>
