<?php

namespace App\Http\Middleware;

use App\Models\Cart;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Message;
use App\Models\Shop;
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
                    'shop' => ($user && $user->role === 'seller') ? (function () use ($user, $request) {
                        $activeId = $request->session()->get('active_seller_shop_id');
                        $shop = null;
                        if ($activeId) {
                            $shop = Shop::with('rootCategory')->where('id', $activeId)->where('user_id', $user->id)->first();
                        }
                        if (! $shop) {
                            $shop = Shop::with('rootCategory')->where('user_id', $user->id)->where('is_default', true)->first()
                                ?? Shop::with('rootCategory')->where('user_id', $user->id)->first();
                        }

                        return $shop;
                    })() : null,
                    'sellerShops' => ($user && $user->role === 'seller') ? Shop::with('rootCategory:id,name,slug')
                        ->where('user_id', $user->id)
                        ->orderByDesc('is_default')
                        ->get() : [],
                    'courier_profile' => $user->role === 'courier' ? $user->courierProfile : null,
                    'logisticsCompany' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? ($user->logisticsCompany ?? ($user->isAdmin()
                            ? LogisticsCompany::where('is_active', true)->first()
                            : HubHandler::where('user_id', $user->id)->where('is_active', true)->first()?->hub?->company))
                        : null,
                    'canSwitchHubs' => $user->role === 'logistics' && (bool) $user->logisticsCompany,
                    'activeHub' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? (function () use ($request, $user) {
                            $accessibleHubs = LogisticsHub::query()
                                ->where('is_active', true)
                                ->when(! $user->isAdmin(), function ($query) use ($user) {
                                    $companyId = $user->logisticsCompany?->id;
                                    if ($companyId) {
                                        $query->where('logistics_company_id', $companyId);
                                    } else {
                                        $query->whereIn('id', HubHandler::where('user_id', $user->id)
                                            ->where('is_active', true)
                                            ->pluck('hub_id'));
                                    }
                                });
                            $hubId = $request->session()->get('active_hub_id');
                            if ($hubId) {
                                $h = (clone $accessibleHubs)->find($hubId);
                                if ($h) {
                                    return $h;
                                }
                            }
                            $handler = HubHandler::where('user_id', $user->id)->where('is_active', true)->first();
                            if ($handler) {
                                $handlerHub = (clone $accessibleHubs)->find($handler->hub_id);
                                if ($handlerHub) {
                                    return $handlerHub;
                                }
                            }

                            return $accessibleHubs->first();
                        })()
                        : null,
                    'allHubs' => ($user && ($user->role === 'logistics' || $user->role === 'admin'))
                        ? LogisticsHub::query()
                            ->where('is_active', true)
                            ->when(! $user->isAdmin(), function ($query) use ($user) {
                                $companyId = $user->logisticsCompany?->id;
                                if ($companyId) {
                                    $query->where('logistics_company_id', $companyId);
                                } else {
                                    $query->whereIn('id', HubHandler::where('user_id', $user->id)
                                        ->where('is_active', true)
                                        ->pluck('hub_id'));
                                }
                            })
                            ->orderBy('tier')
                            ->get(['id', 'name', 'code', 'tier', 'city_municipality'])
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
