# Milestone M1 Adversarial Challenger Report (Gate Bypass, Auth & Security)

**Agent:** Challenger 1 (`challenger_m1_1`)  
**Target:** Milestone M1 — Core Schema, KYC Registration & Admin Approval Gate  
**Verdict:** **APPROVE**  
**Date:** 2026-08-27  

---

## Challenge Summary

**Overall risk assessment**: **LOW**  
The Milestone M1 implementation strictly enforces authorization boundaries, gate checks, input validation, role isolation, and session invalidation across all user roles. All 43 adversarial attack scenarios and stress tests passed empirically.

---

## 1. Observation

Direct empirical observations from executing adversarial tests on the live container environment (`./bagoo.sh artisan test`):

### 1.1 Gate Bypass Scenarios
- **Pending Approval Users**:
  - `User` with `role = 'seller'`, `status = 'pending_approval'`, `kyc_status = 'pending_approval'` attempting `GET /seller/dashboard`, `/seller/products`, `/seller/orders`, `/seller/vouchers`, `/seller/reports`, `/seller/settings` ➔ **Redirected to `route('kyc.pending')`** (302).
  - `User` with `role = 'courier'`, `status = 'pending_approval'`, `kyc_status = 'pending_approval'` attempting `GET /courier/deliveries`, `/courier/earnings`, `/courier/profile`, `/courier/messages` ➔ **Redirected to `route('kyc.pending')`** (302).
  - `User` with `role = 'logistics'`, `status = 'pending_approval'` attempting `GET /hub` ➔ **Redirected to `route('kyc.pending')`** (302).
- **Rejected KYC Users**:
  - `User` with `role = 'seller'`, `kyc_status = 'rejected'` attempting `GET /seller/dashboard` or `POST /seller/products` ➔ **Redirected to `route('kyc.pending')`** (302).
  - `User` with `role = 'courier'`, `kyc_status = 'rejected'` attempting `GET /courier/deliveries` ➔ **Redirected to `route('kyc.pending')`** (302).
  - `User` with `role = 'logistics'`, `kyc_status = 'rejected'` attempting `GET /hub` ➔ **Redirected to `route('kyc.pending')`** (302).
- **Suspended Users**:
  - `User` with `status = 'suspended'` accessing `/seller/dashboard` ➔ Session invalidated, user logged out (`assertGuest()`), **Redirected to `route('login')`** with error message (`email: "Your account has been suspended by platform administration."`).
  - `User` with `status = 'suspended'` authenticating via `POST /login` ➔ Authentication blocked, session flushed, redirected to `route('login')` with error message.

### 1.2 Unauthenticated Access & Role Isolation
- **Unauthenticated Access**:
  - Unauthenticated request to `/pending-approval` ➔ Redirected to `route('login')`.
  - Unauthenticated request to `/admin/kyc` ➔ Redirected to `route('login')`.
  - Unauthenticated call to `POST /admin/kyc/{user}/approve` ➔ Redirected to `route('login')`.
  - Unauthenticated call to `POST /admin/kyc/{user}/reject` ➔ Redirected to `route('login')`.
  - Unauthenticated call to `POST /kyc/resubmit` ➔ Redirected to `route('login')`.
  - Unauthenticated request to `/dashboard` ➔ Redirected to `route('login')`.
- **Role Isolation (Horizontal & Vertical Privilege Separation)**:
  - Approved Buyer accessing `/seller/dashboard` ➔ `403 Forbidden`.
  - Approved Buyer accessing `/courier/deliveries` ➔ `403 Forbidden`.
  - Approved Buyer accessing `/hub` ➔ `403 Forbidden`.
  - Approved Buyer accessing `/admin/kyc` ➔ `403 Forbidden`.
  - Approved Seller accessing `/courier/deliveries` ➔ `403 Forbidden`.
  - Approved Seller accessing `/hub` ➔ `403 Forbidden`.
  - Approved Seller accessing `/admin/kyc` ➔ `403 Forbidden`.
  - Approved Courier accessing `/seller/dashboard` ➔ `403 Forbidden`.
  - Approved Courier accessing `/admin/kyc` ➔ `403 Forbidden`.

### 1.3 Privilege Escalation & Admin KYC Action Security
- **Self-Approval Attack**: Rejected seller calling `POST /admin/kyc/{self_id}/approve` ➔ Intercepted (302 to `kyc.pending` / 403 Forbidden), status remains `rejected`.
- **Non-Admin Approval Attack**: Buyer calling `POST /admin/kyc/{target_id}/approve` ➔ `403 Forbidden`, target status unchanged.
- **Admin Rejection Reason Validation**: Admin sending empty `reason` or short `reason` (<5 characters) to `POST /admin/kyc/{user}/reject` ➔ Rejected with 422 validation errors, status unchanged.
- **Admin Bypass**: Admin with `kyc_status = 'pending_approval'` accessing `/admin/dashboard` or `/dashboard` ➔ Bypasses KYC gate as designed and routes to `admin.dashboard`.

### 1.4 Resubmission & Document Upload Hardening
- **Malicious File Upload**: Uploading PHP/executable script (`exploit.php`) to `POST /kyc/resubmit` ➔ Rejected with validation error on `business_permit`, `kyc_status` remains `rejected`.
- **Oversized Upload**: Uploading 6MB file (>5120 KB limit) to `POST /kyc/resubmit` ➔ Rejected with validation error.
- **Valid Resubmission**: Uploading valid PDF/JPG updates document paths, resets `kyc_status = 'pending_approval'`, `status = 'pending_approval'`, and clears `kyc_feedback`.
- **Courier Resubmission**: Uploading valid `driver_license` and `or_cr_document` properly updates `driver_license_path` and `or_cr_path`.
- **Approved User Visiting Holding Page**: Approved active user visiting `/pending-approval` ➔ Redirected to `route('dashboard')`.

### 1.5 Registration Validation & Mass Assignment Defense
- **Role Escalation via Registration**: Sending `role: 'admin'` in `POST /register` ➔ Validation error (`in:buyer,seller,courier`), user creation prevented (`assertDatabaseMissing`).
- **State Injection via Registration**: Sending `status: 'active'` or `kyc_status: 'approved'` in registration payload ➔ Ignored; user record created with `status = 'pending_approval'` and `kyc_status = 'pending_approval'`.
- **Missing Required Documents**: Registration requests for seller without `shop_name`/documents or courier without vehicle/licenses ➔ Rejected with strict validation errors.

### 1.6 Universal `/dashboard` Redirection
- `guest` ➔ `route('login')`
- `pending_approval` user ➔ `route('kyc.pending')`
- `rejected` user ➔ `route('kyc.pending')`
- `admin` (even with pending KYC) ➔ `route('admin.dashboard')`
- approved `seller` ➔ `route('seller.dashboard')`
- approved `courier` ➔ `route('courier.deliveries')`
- approved `buyer` ➔ `route('buyer.index')`

### 1.7 Dynamic Mid-Session Status Changes & Security Hardening
- **Mid-Session Rejection**: When an active seller's account is rejected by Admin during an active session, their next HTTP request is immediately intercepted by `RoleMiddleware` and redirected to `route('kyc.pending')`.
- **Mid-Session Suspension**: When an active user is suspended, their next HTTP request immediately terminates their session, invalidates tokens, logs them out, and redirects to `login`.
- **SQL Injection Defense**: Admin KYC queue search query handles SQL injection strings (`' OR '1'='1`, `'; DROP TABLE users; --`, etc.) safely via Eloquent parameterized bindings.
- **Null Safety**: Approving/rejecting users who do not have a shop or courier profile (e.g. buyers) executes cleanly without null pointer or missing relationship exceptions.

---

## 2. Logic Chain

1. **Step 1 (Attack Vector Mapping):**
   Mapped 10 distinct security attack vectors across gate bypasses, horizontal/vertical privilege escalation, registration tampering, file upload payloads, dynamic session revocation, and SQL injection.
2. **Step 2 (Empirical Test Harness Development):**
   Authored an extensive, automated adversarial test suite in `tests/Feature/Auth/Milestone1AdversarialSecurityTest.php` with 43 dedicated test methods.
3. **Step 3 (Container Execution & Verification):**
   Executed the test suite inside the Docker container via `./bagoo.sh artisan test`. All 43 test cases passed with 142 assertions.
4. **Step 4 (E2E & Regression Verification):**
   Executed the full suite of M1-related tests (`Milestone1AdversarialSecurityTest`, `RoleMiddlewareGateTest`, `AdminKycApprovalTest`, `KycRegistrationTest`, `DeliveryPhoneConsistencyTest`, `F1_KycRegistrationTest`, `F2_KycApprovalGateTest`). All 73 tests passed with 272 assertions.
5. **Step 5 (Conclusion & Risk Evaluation):**
   Every attack scenario was successfully defended by `RoleMiddleware`, `RegisteredUserController`, `AuthenticatedSessionController`, and `AdminKycController`. No vulnerabilities or gate bypasses were discovered.

---

## 3. Caveats

- **Scope Boundary:** Adversarial testing focused exclusively on Milestone M1 scope (Auth, KYC Gate, Role Isolation, Registration, and Admin KYC queue). Milestone M2–M5 transactional endpoints (order lifecycle, delivery checkpoints, commission splits, and simulation controls) are scheduled for their respective milestones.
- No other caveats.

---

## 4. Conclusion

**Verdict: APPROVE**

Milestone M1 satisfies all security requirements, interface contracts, and access control constraints. The auth and KYC approval gates are robust against adversarial bypass attempts, privilege escalation, file upload attacks, and mid-session status changes.

---

## 5. Verification Method

To independently execute and verify the adversarial and milestone test suites:

### 5.1 Run Adversarial Test Suite
```bash
./bagoo.sh artisan test --filter="Milestone1AdversarialSecurityTest"
```
**Expected Result:** `PASS (43 tests, 142 assertions)`

### 5.2 Run All Milestone M1 Test Suites
```bash
./bagoo.sh artisan test --filter="Milestone1AdversarialSecurityTest|RoleMiddlewareGateTest|AdminKycApprovalTest|KycRegistrationTest|DeliveryPhoneConsistencyTest|F1_KycRegistrationTest|F2_KycApprovalGateTest"
```
**Expected Result:** `PASS (73 tests, 272 assertions)`

### 5.3 Run Frontend Asset Build
```bash
./bagoo.sh npm run build
```
**Expected Result:** `✓ built (Exit code 0, 0 errors)`
