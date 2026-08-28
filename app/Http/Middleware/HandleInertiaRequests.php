<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $cartCount = 0;

        if ($user) {
            $cart = \App\Models\Cart::where('user_id', $user->id)->first();
            $cartCount = $cart ? $cart->items()->sum('quantity') : 0;
        }

        return [
            ...parent::share($request),
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
                    'id_document_path' => $user->id_document_path,
                    'business_permit_path' => $user->business_permit_path,
                    'driver_license_path' => $user->driver_license_path,
                    'or_cr_path' => $user->or_cr_path,
                    'shop' => $user->role === 'seller' ? $user->shop : null,
                    'courier_profile' => $user->role === 'courier' ? $user->courierProfile : null,
                ] : null,
            ],
            'cartCount' => $cartCount,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
