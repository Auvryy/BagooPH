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
        $unreadMessagesCount = 0;

        if ($user) {
            $cart = \App\Models\Cart::where('user_id', $user->id)->first();
            $cartCount = $cart ? $cart->items()->sum('quantity') : 0;
            $unreadMessagesCount = \App\Models\Message::where('receiver_id', $user->id)
                ->where('is_read', false)
                ->count();
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
                    'shop' => ($user && $user->role === 'seller') ? (function () use ($user, $request) {
                        $activeId = $request->session()->get('active_seller_shop_id');
                        $shop = null;
                        if ($activeId) {
                            $shop = \App\Models\Shop::with('rootCategory')->where('id', $activeId)->where('user_id', $user->id)->first();
                        }
                        if (!$shop) {
                            $shop = \App\Models\Shop::with('rootCategory')->where('user_id', $user->id)->where('is_default', true)->first()
                                ?? \App\Models\Shop::with('rootCategory')->where('user_id', $user->id)->first();
                        }
                        return $shop;
                    })() : null,
                    'sellerShops' => ($user && $user->role === 'seller') ? \App\Models\Shop::with('rootCategory:id,name,slug')
                        ->where('user_id', $user->id)
                        ->orderByDesc('is_default')
                        ->get() : [],
                    'courier_profile' => $user->role === 'courier' ? $user->courierProfile : null,
                    'logisticsCompany' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? ($user->logisticsCompany ?? \App\Models\LogisticsCompany::where('is_active', true)->first())
                        : null,
                    'activeHub' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? (function () use ($request, $user) {
                            $hubId = $request->session()->get('active_hub_id');
                            if ($hubId) {
                                $h = \App\Models\LogisticsHub::find($hubId);
                                if ($h) return $h;
                            }
                            $handler = \App\Models\HubHandler::where('user_id', $user->id)->first();
                            if ($handler) {
                                return $handler->hub;
                            }
                            return \App\Models\LogisticsHub::where('is_active', true)->first();
                        })()
                        : null,
                    'allHubs' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? \App\Models\LogisticsHub::where('is_active', true)->orderBy('tier')->get(['id', 'name', 'code', 'tier', 'city_municipality'])
                        : [],
                ] : null,
            ],
            'cartCount' => $cartCount,
            'unreadMessagesCount' => $unreadMessagesCount,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
