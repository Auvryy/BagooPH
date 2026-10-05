<?php

namespace App\Http\Middleware;

use App\Models\Cart;
use App\Models\Message;
use App\Models\Shop;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\ShopEligibilityService;
use App\Services\VerificationDocumentService;
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
        $user = $user?->isBuyer() ? $user->fresh() : $user;
        $canAccessHub = $user && $user->canAccessPortal() && ($user->isLogistics() || $user->isAdmin());
        $eligibility = app(LogisticsEligibilityService::class);
        [$activeHub, $hubs] = $canAccessHub ? $eligibility->hubContext($request) : [null, collect()];
        $cartCount = 0;
        $unreadMessagesCount = 0;

        if ($user) {
            $cart = Cart::where('user_id', $user->id)->first();
            $cartCount = $cart ? $cart->items()->sum('quantity') : 0;
            $unreadMessagesCount = Message::where('receiver_id', $user->id)
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
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
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
                    ...app(VerificationDocumentService::class)->links($user),
                    'shop' => $user->isSeller() ? (function () use ($user, $request) {
                        $activeId = $request->session()->get('active_seller_shop_id');
                        $query = Shop::with('rootCategory')->where('user_id', $user->id)->eligible();

                        return $activeId !== null ? $query->whereKey(is_scalar($activeId) ? $activeId : 0)->first()
                            : $query->orderByDesc('is_default')->orderBy('id')->first();
                    })() : null,
                    'sellerShops' => $user->isSeller() ? Shop::with('rootCategory:id,name,slug')
                        ->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get()
                        ->map(fn ($shop) => [...$shop->toArray(), 'eligible' => app(ShopEligibilityService::class)->isEligible($shop)]) : [],
                    'courier_profile' => $user->role === 'courier' ? $user->courierProfile?->attributesToArray() : null,
                    'logisticsCompany' => $activeHub?->company,
                    'canSwitchHubs' => $activeHub && $eligibility->isCompanyAdministrator($user),
                    'canManageResources' => $user->canAccessPortal() && ($user->isAdmin() || $eligibility->isCompanyAdministrator($user)),
                    'resourceGovernanceUrl' => $request->route()?->getDomain() ? '/resources' : ($user->isAdmin() ? '/admin/resources' : '/hub/resources'),
                    'activeHub' => $activeHub,
                    'allHubs' => $hubs->map(fn ($hub) => $hub->only(['id', 'name', 'code', 'tier', 'city_municipality'])),
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
