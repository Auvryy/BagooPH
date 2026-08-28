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
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Display the dedicated seller registration view.
     */
    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerRegister');
    }

    /**
     * Display the dedicated courier registration view.
     */
    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierRegister');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = $request->input('role', 'buyer');

        // Base validation rules
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'nullable|string|in:buyer,seller,courier',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];

        // Role-specific validation rules
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

        // Upload KYC documents to public storage disk
        $idPath = $request->hasFile('id_document')
            ? '/storage/' . $request->file('id_document')->store('kyc_documents', 'public')
            : null;

        $permitPath = $request->hasFile('business_permit')
            ? '/storage/' . $request->file('business_permit')->store('kyc_documents', 'public')
            : null;

        $licensePath = $request->hasFile('driver_license')
            ? '/storage/' . $request->file('driver_license')->store('kyc_documents', 'public')
            : null;

        $orCrPath = $request->hasFile('or_cr_document')
            ? '/storage/' . $request->file('or_cr_document')->store('kyc_documents', 'public')
            : null;

        // Create User with pending_approval status
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

        // Create associated role profile
        if ($role === 'seller') {
            $shopName = $validated['shop_name'];
            Shop::create([
                'user_id' => $user->id,
                'name' => $shopName,
                'slug' => Str::slug($shopName . '-' . $user->id),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
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

    /**
     * Display the KYC pending approval / holding view.
     */
    public function pendingApproval(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // If user is already active and approved, redirect to their role dashboard
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
                'address' => $user->address,
                'city' => $user->city,
                'postal_code' => $user->postal_code,
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

    /**
     * Handle KYC document re-submission for rejected applicants.
     */
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
            if ($user->shop) {
                $user->shop->update(['business_permit_path' => $updates['business_permit_path']]);
            }
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
