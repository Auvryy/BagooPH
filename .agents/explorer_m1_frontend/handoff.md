# M1 Frontend Investigation Report: Registration, KYC Forms, Pending Gate, & Admin KYC Queue UI

## 1. Observation

A comprehensive audit was performed across all React 18 / Inertia.js 2.0 TypeScript frontend pages, layouts, and type definitions in `/home/andy/Projects/bagoo/resources/js`.

### 1.1 Type Definitions (`resources/js/types/index.d.ts`)
- **Observed:**
  ```typescript
  // resources/js/types/index.d.ts lines 3-16
  export interface User {
      id: number;
      name: string;
      email: string;
      role: Role;
      phone?: string | null;
      avatar?: string | null;
      address?: string | null;
      city?: string | null;
      postal_code?: string | null;
      status?: string;
      email_verified_at?: string;
      shop?: Shop | null;
  }
  ```
  `User` lacks KYC fields: `kyc_status` (`'pending_approval' | 'approved' | 'rejected'`), `id_document`, `business_permit`, `driver_license`, `or_cr_document`, `kyc_feedback`, `kyc_submitted_at`, `kyc_reviewed_at`, and `courier_profile`.
- `CourierProfile` interface is completely missing from `types/index.d.ts`.
- **Order vs Delivery Phone Types:**
  - `Order` (`lines 131–151`): defines `recipient_phone: string;`.
  - `Delivery` (`lines 108–129`): defines `delivery_phone: string;` and `pickup_phone?: string | null;`.
  - `Checkout/Index.tsx` (`lines 53–54`): correctly manages form state with `recipient_name` and `recipient_phone: user.phone || ''`.
  - `Courier/Deliveries.tsx` (`line 275`): renders `{delivery.delivery_phone}` for destination contact.

### 1.2 Registration Pages (`resources/js/Pages/Auth/`)
- **`Register.tsx` (Customer/Buyer Registration - lines 8–14):**
  ```typescript
  const { data, setData, post, processing, errors, reset } = useForm({
      name: '',
      email: '',
      role: 'buyer' as const,
      password: '',
      password_confirmation: '',
  });
  ```
  Missing file upload input for Government ID (`id_document: null as File | null`), contact phone (`phone`), and delivery address.
- **`SellerRegister.tsx` (Seller Registration - lines 8–15):**
  ```typescript
  const { data, setData, post, processing, errors, reset } = useForm({
      name: '',
      shop_name: '',
      email: '',
      role: 'seller' as const,
      password: '',
      password_confirmation: '',
  });
  ```
  Missing file upload dropzones for Government ID (`id_document`) and Business Permit / DTI Certificate (`business_permit`), plus business phone and address.
- **`CourierRegister.tsx` (Courier Rider Registration - lines 8–16):**
  ```typescript
  const { data, setData, post, processing, errors, reset } = useForm({
      name: '',
      vehicle_type: 'motorcycle',
      plate_number: '',
      email: '',
      role: 'courier' as const,
      password: '',
      password_confirmation: '',
  });
  ```
  Missing file upload dropzones for Driver's License (`driver_license`), Vehicle OR/CR document (`or_cr_document`), and Government ID (`id_document`). Also missing `license_number` and contact `phone`.

### 1.3 Missing Gate UI (`resources/js/Pages/Auth/PendingApproval.tsx`)
- File `resources/js/Pages/Auth/PendingApproval.tsx` does **NOT** exist in the repository.
- When an unapproved or rejected user logs in, there is currently no status dashboard or holding screen to view KYC status, read rejection feedback (`kyc_feedback`), re-upload corrected KYC files, or safely log out.

### 1.4 Missing Admin KYC Queue UI (`resources/js/Pages/Admin/KycQueue.tsx`)
- File `resources/js/Pages/Admin/KycQueue.tsx` does **NOT** exist.
- `resources/js/Pages/Admin/Users.tsx` (`lines 15–213`) currently only provides a generic modal to modify user role and status strings (`'active' | 'pending' | 'suspended'`), but has no document inspection viewer, no document preview links/thumbnails, and no one-click Approve or Reject with feedback modal.
- `resources/js/Layouts/DashboardLayout.tsx` (`lines 94–100`): Admin sidebar navigation does not have a link to KYC Verification Queue.

### 1.5 Shared Inertia Auth Props (`app/Http/Middleware/HandleInertiaRequests.php`)
- `HandleInertiaRequests.php` (`lines 43–54`) only shares:
  `['id', 'name', 'email', 'role', 'avatar', 'phone', 'address', 'city', 'postal_code', 'shop']`.
- It omits `status`, `kyc_status`, `kyc_feedback`, `kyc_submitted_at`, and document paths, meaning the frontend cannot react to the user's KYC gate status without this data.

---

## 2. Logic Chain

```
Observation 1.1 & 1.5 (User interface missing KYC fields, HandleInertiaRequests omits kyc_status)
  ↳ Frontend components have no TypeScript typing or runtime data for KYC state
  ⟹ CONCLUSION 1: Extend User interface in index.d.ts with kyc_status, kyc_feedback, and document paths, and add CourierProfile interface. Ensure HandleInertiaRequests shares kyc_status and kyc_feedback.

Observation 1.2 (Registration pages lack document upload fields and useForm FormData support)
  ↳ Buyer, Seller, and Courier registrations currently only submit plain JSON text fields
  ⟹ CONCLUSION 2: Upgrade Register.tsx, SellerRegister.tsx, and CourierRegister.tsx to accept file uploads (Gov ID, Business Permit, Driver's License, OR/CR) using Inertia useForm with forceFormData: true, client-side file preview, and validation error displays.

Observation 1.3 (PendingApproval.tsx does not exist)
  ↳ Unapproved users redirected to /pending-approval will encounter a 404 or missing page error
  ⟹ CONCLUSION 3: Build resources/js/Pages/Auth/PendingApproval.tsx supporting two dynamic states:
     a) "Pending Verification": Real-time status badge, submitted docs checklist, refresh button, logout.
     b) "Application Rejected": Alert banner with kyc_feedback reason, interactive document re-upload form submitting to POST /kyc/resubmit, and logout.

Observation 1.4 (No Admin KYC Queue UI exists; Users.tsx lacks document inspector)
  ↳ Admins cannot review applicant IDs, permits, or licenses, nor approve/reject with canned feedback
  ⟹ CONCLUSION 4: Build resources/js/Pages/Admin/KycQueue.tsx (or integrate into Users.tsx) with applicant list, document inspection modal (Gov ID, Permit, Driver License, OR/CR preview), 1-click Approve, and Reject with reason modal. Add KYC Queue link to DashboardLayout.tsx admin navigation.

Observation 1.1 (Order uses recipient_phone, Delivery uses delivery_phone)
  ↳ Frontend types and views are already consistent: Checkout/Index.tsx binds recipient_phone, Courier/Deliveries.tsx displays delivery_phone
  ⟹ CONCLUSION 5: Frontend phone field binding is sound. The bug is strictly in backend CheckoutController.php:165 and SellerOrderController.php:100 where 'recipient_phone' was passed to Delivery::create instead of 'delivery_phone'.
```

---

## 3. Caveats
1. **Document Storage Format:** KYC documents should support standard image formats (`image/jpeg`, `image/png`, `image/webp`) as well as `application/pdf`. The UI document modal should support image rendering and PDF view/download links.
2. **File Size Constraints:** Browser-side client validation should enforce max 5MB per document file to prevent failed large multipart uploads.
3. **Role Gating Flow:** Customer (`buyer`) accounts may or may not require mandatory KYC before browsing, but if KYC is enforced across all roles as requested in R4, `PendingApproval.tsx` must handle all 3 applicant roles seamlessly (`buyer`, `seller`, `courier`).

---

## 4. Conclusion & Proposed Implementation Blueprint

### 4.1 Type Definitions Updates (`resources/js/types/index.d.ts`)

Add the following type extensions:

```typescript
export type KycStatus = 'pending_approval' | 'approved' | 'rejected' | 'none';

export interface CourierProfile {
    id: number;
    user_id: number;
    vehicle_type: 'motorcycle' | 'van' | 'bicycle' | 'truck';
    plate_number: string;
    license_number?: string | null;
    or_cr_status?: string | null;
    is_available: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    phone?: string | null;
    avatar?: string | null;
    address?: string | null;
    city?: string | null;
    postal_code?: string | null;
    status?: string;
    kyc_status?: KycStatus;
    kyc_feedback?: string | null;
    kyc_submitted_at?: string | null;
    kyc_reviewed_at?: string | null;
    id_document?: string | null;
    business_permit?: string | null;
    driver_license?: string | null;
    or_cr_document?: string | null;
    id_document_url?: string | null;
    business_permit_url?: string | null;
    driver_license_url?: string | null;
    or_cr_url?: string | null;
    email_verified_at?: string;
    shop?: Shop | null;
    courier_profile?: CourierProfile | null;
}
```

---

### 4.2 Registration Forms Redesign

#### A. `SellerRegister.tsx`
- **Fields:** `shop_name`, `name`, `email`, `phone`, `password`, `password_confirmation`, `id_document` (File), `business_permit` (File).
- **Form State:**
  ```typescript
  const { data, setData, post, processing, errors, reset } = useForm({
      name: '',
      shop_name: '',
      email: '',
      phone: '',
      role: 'seller' as const,
      password: '',
      password_confirmation: '',
      id_document: null as File | null,
      business_permit: null as File | null,
  });
  ```
- **File Upload Dropzone:** Tactile Swiss-style upload zone showing file name, file size in KB/MB, and remove button when selected.
- **Submit:** `post(route('register'), { forceFormData: true })`.

#### B. `CourierRegister.tsx`
- **Fields:** `name`, `email`, `phone`, `vehicle_type` (`motorcycle`, `van`, `bicycle`), `plate_number`, `license_number`, `password`, `password_confirmation`, `id_document` (File), `driver_license` (File), `or_cr_document` (File).
- **Form State:**
  ```typescript
  const { data, setData, post, processing, errors, reset } = useForm({
      name: '',
      vehicle_type: 'motorcycle',
      plate_number: '',
      license_number: '',
      email: '',
      phone: '',
      role: 'courier' as const,
      password: '',
      password_confirmation: '',
      id_document: null as File | null,
      driver_license: null as File | null,
      or_cr_document: null as File | null,
  });
  ```
- **Submit:** `post(route('register'), { forceFormData: true })`.

#### C. `Register.tsx` (Customer)
- **Fields:** `name`, `email`, `phone`, `password`, `password_confirmation`, `id_document` (File).
- **Submit:** `post(route('register'), { forceFormData: true })`.

---

### 4.3 Gate UI: `resources/js/Pages/Auth/PendingApproval.tsx`

- **Location:** `resources/js/Pages/Auth/PendingApproval.tsx`
- **Props:**
  ```typescript
  interface Props {
      user: User;
      kyc_status: 'pending_approval' | 'rejected' | 'approved';
      kyc_feedback?: string | null;
      kyc_submitted_at?: string | null;
      role: Role;
  }
  ```
- **UI Structure:**
  1. **Header Badge:** `ACCOUNT VERIFICATION // GATEWAY`
  2. **Pending Review State (`kyc_status === 'pending_approval'`)**:
     - Animated radar radar icon + "Application Under Compliance Review".
     - Estimated time indicator: "Usually reviewed within 2–24 hours".
     - Checklist of submitted items:
       * `[✓] Government Identification Document`
       * `[✓] Merchant Business Permit / Rider LTO License`
       * `[✓] Store Profile & Vehicle Specs`
     - Action: `Check Status / Refresh` button (`router.reload()`).
     - Action: `Sign Out` button (`route('logout')`).
  3. **Rejected State (`kyc_status === 'rejected'`)**:
     - Crimson warning badge + "Action Required: KYC Application Rejected".
     - Feedback callout card with `kyc_feedback` message.
     - Document Resubmission Form with `useForm` posting to `route('kyc.resubmit')`.
     - File dropzones for re-uploading corrected `id_document`, `business_permit`, `driver_license`, `or_cr_document`.
     - "Submit Corrected KYC Documents" button.

---

### 4.4 Admin KYC Queue UI: `resources/js/Pages/Admin/KycQueue.tsx`

- **Location:** `resources/js/Pages/Admin/KycQueue.tsx`
- **Route:** `GET /admin/kyc` (`route('admin.kyc.index')`)
- **Props:**
  ```typescript
  interface KycQueueProps {
      applicants: PaginatedData<User>;
      stats: {
          pending: number;
          approved: number;
          rejected: number;
          total: number;
      };
      filters: {
          status?: string;
          role?: string;
          search?: string;
      };
  }
  ```
- **Components & Features:**
  1. **Summary Metrics Banner:** 4 Cards (Pending Applications, Approved Merchants/Riders, Rejected/Action Needed, Total Processed).
  2. **Search & Filter Bar:** Search input + Status Filter Pills (`Pending`, `Approved`, `Rejected`, `All`) + Role Filter Dropdown.
  3. **Applicant Data Table:**
     - Columns: `Applicant`, `Role / Store / Vehicle`, `Submitted Date`, `KYC Documents`, `KYC Status`, `Actions`.
     - Document pills: Clickable `[ID Doc]`, `[Permit]`, `[Driver License]`, `[OR/CR]` with preview eye icon.
  4. **Document Inspection Modal:**
     - High-resolution modal with document image viewer (zoom/pan, open in new tab).
     - Side-by-side or tabbed switching between Gov ID, Business Permit, License, and OR/CR.
     - Applicant details panel (Name, Email, Phone, Vehicle Specs / Shop Details).
     - Direct Action Buttons in Modal: `Approve Account` (emerald) and `Reject with Feedback` (crimson).
  5. **Reject Reason Modal:**
     - Textarea for custom feedback.
     - Canned quick-fill buttons:
       - "Blurry or unreadable document scan."
       - "Expired business permit or government ID."
       - "Name on document does not match account applicant name."
       - "Vehicle OR/CR document is invalid or incomplete."
     - Form post to `route('admin.kyc.reject', applicant.id)`.
  6. **1-Click Approve:**
     - Direct post to `route('admin.kyc.approve', applicant.id)` with instant UI update and success toast.
  7. **Sidebar Link:** Add `KYC Verification Queue` link to `resources/js/Layouts/DashboardLayout.tsx` admin nav menu with pending badge count.

---

## 5. Verification Method

To verify the frontend implementation once built:

1. **TypeScript Compilation & Build:**
   ```bash
   npm run build
   ```
   Must succeed with 0 type errors.

2. **Customer Registration Verification:**
   - Visit `/register`, fill in name, email, password, and attach test ID image.
   - Verify form submits as `multipart/form-data`.
   - Verify user is redirected to `/pending-approval` with "Pending Verification" card.

3. **Seller Registration Verification:**
   - Visit `/seller/register`, fill store name, owner name, email, password, attach Gov ID and Business Permit.
   - Verify redirected to `/pending-approval`.

4. **Courier Registration Verification:**
   - Visit `/courier/register`, fill vehicle type, plate number, attach Driver's License and OR/CR.
   - Verify redirected to `/pending-approval`.

5. **Admin KYC Queue Verification:**
   - Log in as Admin (`admin@bagoo.test`), navigate to `/admin/kyc`.
   - Verify pending sellers and couriers are listed in the table.
   - Click "Inspect Docs", verify document image viewer renders uploaded files.
   - Click "Approve", verify applicant status updates to `approved`.
   - Click "Reject" on another applicant with feedback reason, verify status updates to `rejected` and feedback persists.

6. **Pending Gate Resubmission Verification:**
   - Log in as the rejected user.
   - Verify `/pending-approval` displays the rejection reason and shows document resubmission inputs.
   - Re-upload document and submit; verify status reverts to `pending_approval`.
