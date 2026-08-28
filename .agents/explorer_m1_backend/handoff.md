# Milestone M1 Backend Investigation & Architecture Blueprint
**Target:** Authentication, Multi-Role KYC Registration, Gate Middleware & Admin Verification Queue  
**Investigator:** Teamwork Explorer Subagent (`explorer_m1_backend`)  
**Date:** 2026-08-27  

---

## 1. Observation

A systematic code audit of Laravel routes, HTTP controllers, middleware, request validation, and storage configurations was performed:

### 1.1 Multi-Role Registration & File Upload Handling
- **File:** `app/Http/Controllers/Auth/RegisteredUserController.php`
  - **Lines 22–41:** Methods `create()` (Buyer: `Auth/Register`), `createSeller()` (Seller: `Auth/SellerRegister`), and `createCourier()` (Courier: `Auth/CourierRegister`) exist and point to dedicated Inertia pages.
  - **Lines 50–56:** `store()` currently only validates:
    ```php
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
        'role' => 'nullable|string|in:buyer,seller,courier',
        'shop_name' => 'nullable|string|max:255',
        'password' => ['required', 'confirmed', Rules\Password::defaults()],
    ]);
    ```
  - **Defects Observed:**
    1. No validation or handling for KYC uploaded files (`id_document`, `business_permit`, `driver_license`, `or_cr_document`).
    2. Missing validation for courier fields (`vehicle_type`, `plate_number`, `license_number`) and contact details (`phone`, `address`, `city`, `postal_code`).
    3. Lines 60–65 create the `User` without populating KYC columns (`kyc_status`, `id_document_path`, `business_permit_path`, `driver_license_path`, `or_cr_path`, `kyc_submitted_at`).
    4. Lines 67–75 create `Shop` for seller, but no `CourierProfile` record is created for courier registrations.
    5. Lines 79–86 immediately log in the user and redirect to the active role portal (`route('seller.dashboard')`, `route('courier.deliveries')`, `route('buyer.index')`), completely bypassing Admin KYC verification.

### 1.2 Auth Gate Enforcement & Access Middleware
- **File:** `app/Http/Middleware/RoleMiddleware.php`
  - **Lines 17–36:**
    ```php
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }
        $userRole = $request->user()->role;
        if ($userRole === 'admin') {
            return $next($request);
        }
        if (! in_array($userRole, $roles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $userRole . ').');
        }
        return $next($request);
    }
    ```
  - **Defects Observed:**
    1. Zero check for account `status` or `kyc_status`.
    2. Any user with `status = 'pending_approval'`, `kyc_status = 'pending_approval'`, or `kyc_status = 'rejected'` has unrestricted access to role dashboards once authenticated.
    3. No redirect mechanism to `/pending-approval`.
- **File:** `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
  - **Lines 30–45:** `store()` authenticates credentials and redirects directly to `$targetRoute` matching `$user->role`, without checking if the account is `pending_approval`, `rejected`, or `suspended`.
- **File:** `routes/web.php`
  - **Lines 80–88:** The universal `/dashboard` route redirects directly based on `$user->role` without gating unapproved or rejected accounts.
  - There is NO `/pending-approval` route or resubmission endpoint declared.

### 1.3 Admin KYC Verification Queue & Document Handling
- **File:** `app/Http/Controllers/Admin/AdminDashboardController.php`
  - **Lines 50–72:** `users()` loads users with `User::with('shop')->latest()`, but does not filter by KYC status or provide document URLs.
  - **Lines 74–84:** `updateUserRole()` allows changing status manually via a dropdown, but lacks dedicated 1-click `Approve` and `Reject (with feedback)` endpoints.
  - There is NO `AdminKycController.php` in `app/Http/Controllers/Admin/`.
- **Storage Configuration (`config/filesystems.php`):**
  - Lines 41–48 define the `public` disk rooted at `storage_path('app/public')` with URL `/storage`.
  - Storing uploaded KYC documents under `storage/app/public/kyc_documents` allows direct, performant preview in React modals (via `/storage/kyc_documents/...`) while remaining secure inside the application storage.

---

## 2. Logic Chain

1. **Premise 1 (R4 Compliance):** `ORIGINAL_REQUEST.md` R4 and `PROJECT.md` M1 mandate that:
   - Registration forms must accept required identity/business/vehicle documents.
   - Newly created accounts must default to `status = 'pending_approval'` and `kyc_status = 'pending_approval'`.
   - Unapproved accounts must be blocked from role dashboards and held on a `/pending-approval` screen.
   - Admins must have an actionable verification queue with document preview and 1-click Approve / Reject with feedback.
2. **Controller & Route Layering:**
   - **`RegisteredUserController`**: Handles multi-role input validation, document uploads to the `public` disk (`kyc_documents`), user/shop/courier_profile creation with `kyc_status = 'pending_approval'`, and redirects the newly registered user to `route('kyc.pending')`.
   - **`RoleMiddleware`**: Must intercept all role requests. If `$user->kyc_status === 'pending_approval'` or `$user->kyc_status === 'rejected'`, redirect to `route('kyc.pending')`. If `$user->isAdmin()`, bypass.
   - **`AuthenticatedSessionController` & `/dashboard`**: If user is pending or rejected upon login, redirect to `route('kyc.pending')`.
   - **`AdminKycController`**: Provide dedicated actions:
     - `GET /admin/kyc`: Paginated queue of pending/reviewed applicants with document URLs.
     - `POST /admin/kyc/{user}/approve`: Sets `kyc_status = 'approved'`, `status = 'active'`, `kyc_reviewed_at = now()`, activates shop/courier.
     - `POST /admin/kyc/{user}/reject`: Sets `kyc_status = 'rejected'`, records `kyc_feedback = $reason`, `kyc_reviewed_at = now()`.
   - **`KycController` / `RegisteredUserController` Resubmission**:
     - `GET /pending-approval`: Renders `Auth/PendingApproval` with user KYC data and feedback.
     - `POST /kyc/resubmit`: Allows rejected applicants to upload replacement documents and reset status to `pending_approval`.
3. **Inertia Props Synchronization:**
   - `HandleInertiaRequests.php` must include `status`, `kyc_status`, `kyc_feedback`, `kyc_submitted_at`, and `courier_profile` in `auth.user` so frontend components have access to live verification state.

---

## 3. Caveats

1. **Storage Symbolic Link:** Ensure `php artisan storage:link` has executed so `public/storage` points to `storage/app/public`.
2. **Buyer KYC Policy:** In accordance with R4, all newly registered accounts start at `pending_approval`. The registration controller must support optional ID upload for buyers while making ID/permits mandatory for sellers and couriers.
3. **Admin Bypass:** Administrators (`role === 'admin'`) must always bypass KYC gates to prevent lockout.
4. **Resubmission & Logout Accessibility:** The routes `GET /pending-approval`, `POST /kyc/resubmit`, and `POST /logout` must be accessible to authenticated users with `pending_approval` or `rejected` status.

---

## 4. Conclusion & Actionable Backend Implementation Blueprint

### 4.1 Route Declarations

#### In `routes/auth.php`:
```php
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::get('seller/register', [RegisteredUserController::class, 'createSeller'])->name('seller.register');
    Route::get('courier/register', [RegisteredUserController::class, 'createCourier'])->name('courier.register');
    Route::post('register', [RegisteredUserController::class, 'store']);
    // Login routes...
});

Route::middleware('auth')->group(function () {
    // KYC Holding and Resubmission (Accessible to pending/rejected users)
    Route::get('pending-approval', [RegisteredUserController::class, 'pendingApproval'])->name('kyc.pending');
    Route::post('kyc/resubmit', [RegisteredUserController::class, 'resubmitKyc'])->name('kyc.resubmit');
    
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
```

#### In `routes/web.php`:
```php
// Admin KYC Verification Queue (Inside middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.'))
Route::get('/kyc', [App\Http\Controllers\Admin\AdminKycController::class, 'index'])->name('kyc.index');
Route::post('/kyc/{user}/approve', [App\Http\Controllers\Admin\AdminKycController::class, 'approve'])->name('kyc.approve');
Route::post('/kyc/{user}/reject', [App\Http\Controllers\Admin\AdminKycController::class, 'reject'])->name('kyc.reject');
```

---

### 4.2 `RegisteredUserController.php` Implementation Plan

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CourierProfile;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerRegister');
    }

    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierRegister');
    }

    public function store(Request $request): RedirectResponse
    {
        $role = $request->input('role', 'buyer');

        // 1. Base validation
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:buyer,seller,courier',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];

        // 2. Role-specific validation rules
        if ($role === 'seller') {
            $rules['shop_name'] = 'required|string|max:255';
            $rules['phone'] = 'required|string|max:255';
            $rules['address'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['id_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['business_permit'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        } elseif ($role === 'courier') {
            $rules['phone'] = 'required|string|max:255';
            $rules['address'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['vehicle_type'] = 'required|string|max:100';
            $rules['plate_number'] = 'required|string|max:50';
            $rules['license_number'] = 'nullable|string|max:50';
            $rules['id_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['driver_license'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['or_cr_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        }

        $validated = $request->validate($rules);

        // 3. Handle File Uploads to public storage
        $idPath = $request->hasFile('id_document') ? '/storage/' . $request->file('id_document')->store('kyc_documents', 'public') : null;
        $permitPath = $request->hasFile('business_permit') ? '/storage/' . $request->file('business_permit')->store('kyc_documents', 'public') : null;
        $licensePath = $request->hasFile('driver_license') ? '/storage/' . $request->file('driver_license')->store('kyc_documents', 'public') : null;
        $orCrPath = $request->hasFile('or_cr_document') ? '/storage/' . $request->file('or_cr_document')->store('kyc_documents', 'public') : null;

        // 4. Create User with pending_approval status
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
            'id_document_path' => $idPath,
            'business_permit_path' => $permitPath,
            'driver_license_path' => $licensePath,
            'or_cr_path' => $orCrPath,
            'kyc_submitted_at' => now(),
        ]);

        // 5. Create associated role profile
        if ($role === 'seller') {
            Shop::create([
                'user_id' => $user->id,
                'name' => $validated['shop_name'],
                'slug' => Str::slug($validated['shop_name'] . '-' . $user->id),
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'city' => $validated['city'],
                'business_permit_path' => $permitPath,
                'status' => 'pending',
            ]);
        } elseif ($role === 'courier') {
            CourierProfile::create([
                'user_id' => $user->id,
                'vehicle_type' => $validated['vehicle_type'],
                'plate_number' => $validated['plate_number'],
                'license_number' => $validated['license_number'] ?? null,
                'or_cr_status' => 'Pending Verification',
                'is_available' => false,
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('kyc.pending');
    }

    public function pendingApproval(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // If user is active and approved, redirect to role dashboard
        if ($user->kyc_status === 'approved' && $user->status === 'active') {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/PendingApproval', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'status' => $user->status,
                'kyc_status' => $user->kyc_status,
                'kyc_feedback' => $user->kyc_feedback,
                'kyc_submitted_at' => $user->kyc_submitted_at ? $user->kyc_submitted_at->toIso8601String() : null,
                'kyc_reviewed_at' => $user->kyc_reviewed_at ? $user->kyc_reviewed_at->toIso8601String() : null,
                'id_document_path' => $user->id_document_path,
                'business_permit_path' => $user->business_permit_path,
                'driver_license_path' => $user->driver_license_path,
                'or_cr_path' => $user->or_cr_path,
            ],
            'shop' => $user->shop,
            'courierProfile' => $user->courierProfile,
        ]);
    }

    public function resubmitKyc(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'business_permit' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'driver_license' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'or_cr_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];

        $request->validate($rules);

        $updates = [
            'kyc_status' => 'pending_approval',
            'status' => 'pending_approval',
            'kyc_feedback' => null,
            'kyc_submitted_at' => now(),
        ];

        if ($request->hasFile('id_document')) {
            $updates['id_document_path'] = '/storage/' . $request->file('id_document')->store('kyc_documents', 'public');
        }
        if ($request->hasFile('business_permit')) {
            $updates['business_permit_path'] = '/storage/' . $request->file('business_permit')->store('kyc_documents', 'public');
        }
        if ($request->hasFile('driver_license')) {
            $updates['driver_license_path'] = '/storage/' . $request->file('driver_license')->store('kyc_documents', 'public');
        }
        if ($request->hasFile('or_cr_document')) {
            $updates['or_cr_path'] = '/storage/' . $request->file('or_cr_document')->store('kyc_documents', 'public');
        }

        $user->update($updates);

        return back()->with('success', 'Your verification documents have been resubmitted successfully.');
    }
}
```

---

### 4.3 `RoleMiddleware.php` Implementation Plan

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Admin has full portal bypass
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Check account suspension
        if ($user->status === 'suspended') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been suspended by platform administration.',
            ]);
        }

        // Check KYC approval gate
        if ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected') {
            return redirect()->route('kyc.pending');
        }

        // Check role authorization
        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $user->role . ').');
        }

        return $next($request);
    }
}
```

---

### 4.4 `AuthenticatedSessionController.php` & `/dashboard` Updates

#### In `AuthenticatedSessionController::store`:
```php
public function store(LoginRequest $request): RedirectResponse
{
    $request->authenticate();

    $request->session()->regenerate();

    $user = $request->user();

    if ($user->status === 'suspended') {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->withErrors([
            'email' => 'Your account has been suspended by platform administration.',
        ]);
    }

    if (! $user->isAdmin() && ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected')) {
        return redirect()->route('kyc.pending');
    }

    $targetRoute = match($user->role) {
        'admin' => route('admin.dashboard', absolute: false),
        'seller' => route('seller.dashboard', absolute: false),
        'courier' => route('courier.deliveries', absolute: false),
        default => route('buyer.index', absolute: false),
    };

    return redirect()->intended($targetRoute);
}
```

#### In `routes/web.php` Universal `/dashboard` Route:
```php
Route::get('/dashboard', function () {
    $user = auth()->user();
    if (! $user) {
        return redirect()->route('login');
    }
    if (! $user->isAdmin() && ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected')) {
        return redirect()->route('kyc.pending');
    }
    return redirect()->intended(match($user->role) {
        'admin' => route('admin.dashboard'),
        'seller' => route('seller.dashboard'),
        'courier' => route('courier.deliveries'),
        default => route('buyer.index'),
    });
})->name('dashboard');
```

---

### 4.5 `AdminKycController.php` Implementation Plan

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminKycController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'pending_approval');
        $role = $request->input('role', 'all');
        $search = $request->input('search');

        $query = User::with(['shop', 'courierProfile']);

        if ($status !== 'all') {
            $query->where('kyc_status', $status);
        }

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $applicants = $query->latest('kyc_submitted_at')->paginate(15)->withQueryString();

        $stats = [
            'pending_count' => User::where('kyc_status', 'pending_approval')->count(),
            'approved_count' => User::where('kyc_status', 'approved')->count(),
            'rejected_count' => User::where('kyc_status', 'rejected')->count(),
            'pending_sellers' => User::where('role', 'seller')->where('kyc_status', 'pending_approval')->count(),
            'pending_couriers' => User::where('role', 'courier')->where('kyc_status', 'pending_approval')->count(),
            'pending_buyers' => User::where('role', 'buyer')->where('kyc_status', 'pending_approval')->count(),
        ];

        return Inertia::render('Admin/KycQueue', [
            'applicants' => $applicants,
            'filters' => [
                'status' => $status,
                'role' => $role,
                'search' => $search ?? '',
            ],
            'stats' => $stats,
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        $user->update([
            'kyc_status' => 'approved',
            'status' => 'active',
            'kyc_reviewed_at' => now(),
            'kyc_feedback' => null,
        ]);

        if ($user->role === 'seller' && $user->shop) {
            $user->shop->update(['status' => 'active']);
        }

        if ($user->role === 'courier' && $user->courierProfile) {
            $user->courierProfile->update([
                'or_cr_status' => 'Verified & Registered',
                'is_available' => true,
            ]);
        }

        return back()->with('success', "Applicant {$user->name} ({$user->role}) has been approved successfully.");
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        $user->update([
            'kyc_status' => 'rejected',
            'status' => 'pending_approval',
            'kyc_feedback' => $validated['reason'],
            'kyc_reviewed_at' => now(),
        ]);

        if ($user->role === 'seller' && $user->shop) {
            $user->shop->update(['status' => 'pending']);
        }

        if ($user->role === 'courier' && $user->courierProfile) {
            $user->courierProfile->update([
                'is_available' => false,
            ]);
        }

        return back()->with('success', "Applicant {$user->name} has been rejected with feedback.");
    }
}
```

---

### 4.6 `HandleInertiaRequests.php` Shared User Props Plan

Update `share()` in `app/Http/Middleware/HandleInertiaRequests.php`:
```php
'auth' => [
    'user' => $user ? [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'avatar' => $user->avatar,
        'phone' => $user->phone,
        'address' => $user->address,
        'city' => $user->city,
        'postal_code' => $user->postal_code,
        'status' => $user->status,
        'kyc_status' => $user->kyc_status,
        'kyc_feedback' => $user->kyc_feedback,
        'kyc_submitted_at' => $user->kyc_submitted_at ? $user->kyc_submitted_at->toIso8601String() : null,
        'kyc_reviewed_at' => $user->kyc_reviewed_at ? $user->kyc_reviewed_at->toIso8601String() : null,
        'shop' => $user->role === 'seller' ? $user->shop : null,
        'courier_profile' => $user->role === 'courier' ? $user->courierProfile : null,
    ] : null,
],
```

---

## 5. Verification Method

### 5.1 Automated Feature Test Suite
Implement and run:
1. `tests/Feature/Auth/KycRegistrationTest.php`:
   - `test_seller_registration_with_documents()`
   - `test_courier_registration_with_vehicle_and_license()`
   - `test_registration_defaults_to_pending_approval_and_redirects()`
2. `tests/Feature/Auth/RoleMiddlewareGateTest.php`:
   - `test_pending_user_cannot_access_seller_portal()`
   - `test_rejected_user_held_at_pending_approval_page()`
   - `test_approved_user_accesses_portal()`
   - `test_admin_bypasses_gate()`
3. `tests/Feature/Admin/AdminKycApprovalTest.php`:
   - `test_admin_can_view_kyc_queue()`
   - `test_admin_can_approve_applicant()`
   - `test_admin_can_reject_applicant_with_feedback()`
   - `test_applicant_can_resubmit_documents()`

### 5.2 Verification Commands
- `php artisan test --filter=KycRegistrationTest`
- `php artisan test --filter=RoleMiddlewareGateTest`
- `php artisan test --filter=AdminKycApprovalTest`
- `./bagoo.sh test`
- `npm run build`
