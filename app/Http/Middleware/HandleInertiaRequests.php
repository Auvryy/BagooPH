<?php

namespace App\Http\Middleware;

use App\Models\Cart;
use App\Models\Message;
use App\Models\Shop;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\VerificationDocumentService;
use Closure;
use Illuminate\Http\Request;
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
        $response = parent::handle($request, $next);

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
                    'canSwitchHubs' => $activeHub && $eligibility->isCompanyAdministrator($user),
                    'canManageResources' => $user->canAccessPortal() && ($user->isAdmin() || $eligibility->isCompanyAdministrator($user)),
                    'resourceGovernanceUrl' => $request->route()?->getDomain() ? '/resources' : ($user->isAdmin() ? '/admin/resources' : '/hub/resources'),
                    'activeHub' => $activeHub,
                    'allHubs' => $hubs->map(fn ($hub) => $hub->only(['id', 'name', 'code', 'tier', 'city_municipality'])),
                ] : null,
            ],
            'cartCount' => $cartCount,
            'unreadMessagesCount' => $unreadMessagesCount,
            'notificationSummary' => app(NotificationCenterService::class)->summary($user),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
