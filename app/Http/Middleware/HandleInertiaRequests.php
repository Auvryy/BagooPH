<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Buyer\BuyerHomeController;
use App\Http\Controllers\Buyer\BuyerProductController;
use App\Models\Cart;
use App\Models\LogisticsCompany;
use App\Models\Message;
use App\Models\SavedProduct;
use App\Models\Shop;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\SidebarAttentionService;
use App\Services\VerificationDocumentService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $speculative = $request->prefetch();
        $cataloguePrefetch = $speculative && $request->header('X-Bagoo-Catalogue') === '1';
        Inertia::encryptHistory(false);
        try {
            if ($speculative && (! $cataloguePrefetch || ! config('catalogue.prefetch_enabled') || ! $request->isMethod('GET')
                || ! $request->header('X-Inertia') || ($request->user() && ! $request->user()->isBuyer())
                || ! in_array($request->route()?->getActionName(), [
                    BuyerHomeController::class.'@index', BuyerProductController::class.'@search', BuyerProductController::class.'@show',
                ], true) || $request->session()->get('_flash.old', []) || $request->session()->get('_flash.new', []))) {
                return response()->json(['message' => 'Load this page normally.'], 409)->header('Cache-Control', 'no-store, private');
            }
            $response = parent::handle($request, $next);
        } finally {
            Inertia::encryptHistory(false);
            if ($speculative && $request->hasSession()) {
                // Background browsing must not consume the next intentional visit's feedback.
                $request->session()->reflash();
            }
        }

        if ($request->header('X-Inertia')) {
            foreach (['Location', 'X-Inertia-Location'] as $header) {
                $target = $response->headers->get($header);
                if ($target && parse_url($target, PHP_URL_HOST) === $request->getHost()
                    && (parse_url($target, PHP_URL_PORT) === null || parse_url($target, PHP_URL_PORT) === $request->getPort())) {
                    // Keep redirects on the browser's origin when HTTPS terminates before PHP.
                    $path = parse_url($target, PHP_URL_PATH) ?: '/';
                    if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
                        continue;
                    }
                    $query = parse_url($target, PHP_URL_QUERY);
                    $fragment = parse_url($target, PHP_URL_FRAGMENT);
                    $response->headers->set($header, $path.($query !== null ? '?'.$query : '').($fragment !== null ? '#'.$fragment : ''));
                }
            }
        }

        if ($request->user() || $request->header('X-Inertia') || $request->is('login', '*/login', 'logout')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

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
        $user = $request->user()?->fresh();
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
                    'has_google_identity' => is_string($user->google_id) && $user->google_id !== '',
                    'needs_password_setup' => is_string($user->google_id) && $user->google_id !== '' && ! $user->password_login_enabled,
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
                    'shop' => $user->isSeller() ? Shop::with('rootCategory:id,name,slug')->where('user_id', $user->id)->first() : null,
                    'courier_profile' => $user->role === 'courier' ? $user->courierProfile?->attributesToArray() : null,
                    'logisticsCompany' => $activeHub?->company,
                    'canSwitchHubs' => $activeHub && $eligibility->isCompanyManager($user),
                    'canScanParcels' => $user->isLogistics() && $activeHub && $eligibility->canScan($user, $activeHub),
                    'canManageResources' => $user->canAccessPortal() && ($user->isAdmin() || $eligibility->isCompanyManager($user)),
                    'canSetupCompany' => $user->canAccessPortal() && ($user->isAdmin() || LogisticsCompany::eligible()->where('user_id', $user->id)->exists()),
                    'canManageReturnLiabilities' => $user->closed_at === null && $user->email_verified_at !== null
                        && (($user->isAdmin() && $user->canAccessPortal()) || ($user->isSeller() && $user->isKycApproved())
                            || ($user->isLogistics() && $user->isKycApproved() && LogisticsCompany::where('user_id', $user->id)->exists())),
                    'canViewFinancialOversight' => $user->closed_at === null && $user->email_verified_at !== null
                        && (($user->isAdmin() && $user->canAccessPortal()) || ($user->isSeller() && $user->isKycApproved())
                            || ($user->isLogistics() && $user->canAccessPortal() && $user->logisticsCompany()->count() === 1)),
                    'resourceGovernanceUrl' => $request->route()?->getDomain() ? '/resources' : ($user->isAdmin() ? '/admin/resources' : '/hub/resources'),
                    'activeHub' => $activeHub,
                    'allHubs' => $hubs->map(fn ($hub) => $hub->only(['id', 'name', 'code', 'tier', 'city_municipality'])),
                ] : null,
            ],
            'cartCount' => $cartCount,
            'cataloguePrefetchEnabled' => (bool) config('catalogue.prefetch_enabled'),
            'buyerApplication' => fn () => app(BuyerAccessService::class)->applicationPresentation($user),
            'savedProductIds' => fn () => $user && $user->isBuyer() && $user->canAccessPortal()
                ? SavedProduct::where('buyer_id', $user->id)->pluck('product_id') : [],
            'cartItemIdsByProduct' => fn () => $this->cartItemIdsByProduct($request, $user),
            'unreadMessagesCount' => $unreadMessagesCount,
            'sidebarAttention' => fn () => app(SidebarAttentionService::class)->for($user, $unreadMessagesCount),
            'notificationSummary' => app(NotificationCenterService::class)->summary($user),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }

    private function cartItemIdsByProduct(Request $request, ?User $user): array
    {
        if ($user && ! $user->isBuyer()) {
            return [];
        }
        if (! in_array($request->route()?->getName(), [
            'marketplace', 'buyer.index', 'buyer.search', 'buyer.catalog', 'buyer.products.show',
            'products.index', 'catalog.index', 'products.show', 'shop.show', 'shop.categories.show',
            'saved-products.index', 'promotions.show',
        ], true)) {
            return [];
        }

        $cart = Cart::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->whereNull('user_id')->where('session_id', $request->session()->getId()))
            ->first();
        if (! $cart) {
            return [];
        }

        return $cart->items()->orderByDesc('id')->get(['id', 'product_id'])
            ->unique('product_id')->mapWithKeys(fn ($item) => [(int) $item->product_id => (int) $item->id])->all();
    }
}
