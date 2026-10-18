<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Commerce\BuyerDiscoveryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BuyerHomeController extends Controller
{
    public function index(Request $request): InertiaResponse|SymfonyResponse
    {
        $user = $request->user();
        $host = $request->getHost();

        // Prevent worker subdomains from accidentally rendering the buyer marketplace
        if (str_starts_with($host, 'seller.')) {
            if ($user && $user->isSeller()) {
                return redirect('/dashboard');
            }

            return Inertia::render('Seller/Landing');
        }
        if (str_starts_with($host, 'courier.')) {
            if ($user && $user->isCourier()) {
                return redirect('/deliveries');
            }

            return app(AuthenticatedSessionController::class)->createCourier();
        }
        if (str_starts_with($host, 'hub.')) {
            if ($user && ($user->isLogistics() || $user->isAdmin())) {
                return redirect('/dashboard');
            }

            return app(AuthenticatedSessionController::class)->createHub();
        }
        if (str_starts_with($host, 'admin.')) {
            if ($user && $user->isAdmin()) {
                return redirect('/dashboard');
            }

            return app(AuthenticatedSessionController::class)->createAdmin();
        }

        $banners = app(AdvertisementReader::class)->homepage();

        $categories = app(BuyerDiscoveryService::class)->categories();

        // 5. "Daily Discover" & Search Product Feed
        $query = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])->availableForSale()->withReviewSummary()
            ->where('status', 'active');

        $discovery = app(BuyerDiscoveryService::class);
        if ($request->input('sort') === 'new_arrivals' || (! $request->filled('sort') && $request->input('tab') === 'new_arrivals')) {
            $request->query->set('sort', 'newest');
        }
        $filters = $discovery->selection($request);
        $feedProducts = $discovery->paginate($discovery->filter($query, $filters), $filters, 18);

        // 5b. Related / Suggested Products (when search is active or when feed has few results)
        $relatedProducts = [];
        if ($request->filled('search') || $request->filled('category')) {
            $matchedIds = $feedProducts->pluck('id')->toArray();
            $relatedQuery = $discovery->filter($discovery->catalogue(), [...$filters, 'search' => ''])
                ->whereNotIn('id', $matchedIds);

            $relatedProducts = $relatedQuery->orderBy('sales_count', 'desc')
                ->take(6)
                ->get();
        }

        // 7. Active in-transit shipment telemetry if logged in
        $activeShipment = null;
        if ($user) {
            $activeOrder = Order::with(['delivery', 'items.product'])
                ->where('buyer_id', $user->id)
                ->whereIn('status', ['processing', 'ready_for_pickup', 'shipped'])
                ->latest()
                ->first();

            if ($activeOrder && $activeOrder->delivery) {
                $activeShipment = [
                    'order_id' => $activeOrder->id,
                    'order_number' => $activeOrder->order_number,
                    'status' => $activeOrder->status,
                    'tracking_number' => $activeOrder->delivery->tracking_number,
                    'courier_name' => $activeOrder->delivery->logistics_partner,
                    'item_name' => $activeOrder->items->first()?->product?->name ?? 'Package',
                    'item_count' => $activeOrder->items->count(),
                    'estimated_delivery' => $activeOrder->delivery->estimated_delivery_at ? $activeOrder->delivery->estimated_delivery_at->diffForHumans() : 'Within 24 Hours',
                ];
            }
        }

        return Inertia::render('Buyer/Home', [
            'banners' => $banners,
            'categories' => $categories,
            'feedProducts' => $feedProducts,
            'relatedProducts' => $relatedProducts,
            'activeShipment' => $activeShipment,
            'filters' => [...$filters, 'tab' => match ($filters['sort']) {
                'newest' => 'new_arrivals', 'top_sales', 'top_rated' => $filters['sort'], default => 'all',
            }],
        ]);
    }
}
