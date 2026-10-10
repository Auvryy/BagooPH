<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerSettlementEvent;
use App\Rules\PhilippineContact;
use App\Services\AccountSettingsService;
use App\Services\Commerce\ReviewService;
use App\Services\Commerce\SellerInventoryService;
use App\Services\Commerce\SellerSalesMetricsService;
use App\Services\IdentityCorrectionService;
use App\Services\Orders\OrderWorkspaceService;
use App\Services\ProfileInputService;
use App\Services\ShopEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SellerDashboardController extends Controller
{
    use HasSellerShop;

    public function __construct(private readonly SellerSalesMetricsService $salesMetrics) {}

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request);

        $totalProducts = Product::where('shop_id', $shop->id)->count();
        $stockCounts = app(SellerInventoryService::class)->stockCounts($shop->id);
        $salesSummary = $this->salesMetrics->dashboardSummary($shop->id);
        $shopOrders = fn () => Order::query()->whereHas(
            'items',
            fn ($query) => $query->where('shop_id', $shop->id)
        );

        $workspace = app(OrderWorkspaceService::class);
        $counts = $workspace->counts($shopOrders(), 'seller');
        $recentOrders = $workspace->stable($shopOrders())->with([
            'items' => fn ($items) => $items->where('shop_id', $shop->id)->with('product')->orderBy('id'),
        ])->take(6)->get();

        $topProducts = $this->salesMetrics
            ->withProductLifecycleTotals(Product::query()->where('shop_id', $shop->id))
            ->with('category')
            ->orderByDesc('completed_units')
            ->orderBy('name')
            ->take(5)
            ->get();

        return Inertia::render('Seller/Dashboard', [
            'shop' => $shop,
            'stats' => [
                ...$salesSummary,
                'totalProducts' => $totalProducts,
                ...$stockCounts,
                'pendingPackCount' => $counts['to_pack'],
                'readyPickupCount' => $counts['to_pickup'],
                'shippedCount' => $counts['in_transit'],
                'completedCount' => $counts['completed'],
                'deliveredCount' => $counts['delivered'],
                'deliveryIssueCount' => $counts['delivery_failed'],
                'cancelledCount' => $counts['cancelled'],
                'returnedCount' => $counts['returned'],
                'returnCount' => $counts['return_custody'],
            ],
            'dailySales' => $this->salesMetrics->sevenDayCompletedSales($shop->id),
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
            'categories' => Category::where('is_active', true)->select('id', 'name', 'slug')->get(),
        ]);
    }

    public function reports(Request $request): Response
    {
        $shop = $this->getActiveShop($request);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);
        $fromDate = CarbonImmutable::parse($validated['from_date'] ?? now()->subDays(30)->format('Y-m-d'));
        $toDate = CarbonImmutable::parse($validated['to_date'] ?? now()->format('Y-m-d'));

        $query = $this->salesMetrics
            ->completedItemsBetween($shop->id, $fromDate->startOfDay(), $toDate->endOfDay())
            ->with(['order.buyer', 'order.commissionLedger', 'product.category']);

        $orderItems = $query->get();

        $completedGrossSales = round((float) $orderItems->sum('subtotal'), 2);
        $completedUnits = (int) $orderItems->sum('quantity');
        $estimatedPlatformCommission = round($completedGrossSales * 0.10, 2);
        $estimatedSellerShare = round($completedGrossSales * 0.90, 2);
        $orderCount = $orderItems->pluck('order_id')->unique()->count();
        $averageCompletedOrderValue = $orderCount > 0
            ? round($completedGrossSales / $orderCount, 2)
            : 0.0;
        $settledSellerAmount = (int) SellerSettlementEvent::where('event_type', 'payment_recorded')
            ->whereHas('settlement', fn ($record) => $record->where('seller_id', $shop->user_id)
                ->whereIn('order_id', $orderItems->pluck('order_id')->unique()))->sum('amount_cents') / 100;

        return Inertia::render('Seller/Reports', [
            'shop' => $shop,
            'filters' => [
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
            ],
            'report' => [
                'completedGrossSales' => $completedGrossSales,
                'completedUnits' => $completedUnits,
                'completedOrderCount' => $orderCount,
                'averageCompletedOrderValue' => $averageCompletedOrderValue,
                'estimatedPlatformCommission' => $estimatedPlatformCommission,
                'estimatedSellerShare' => $estimatedSellerShare,
                'settledSellerAmount' => $settledSellerAmount,
                'pendingSettlementAmount' => max(0, round($estimatedSellerShare - $settledSellerAmount, 2)),
            ],
            'orderItems' => $orderItems
                ->sortByDesc(fn (OrderItem $item) => $item->order?->completed_at)
                ->take(25)
                ->values(),
        ]);
    }

    public function settings(Request $request): Response
    {
        $shop = $this->getActiveShop($request, history: true);

        return Inertia::render('Seller/Settings', [
            'shop' => $shop,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->updateSettingsInShop($request), history: true);
    }

    private function updateSettingsInShop(Request $request): RedirectResponse
    {
        $shop = $this->getActiveShop($request);

        $rules = [
            'description' => 'nullable|string|max:5000',
            'phone' => ['sometimes', 'required', 'string', new PhilippineContact(true)],
        ];

        if ($request->has('phone')) {
            $request->merge(['phone' => PhilippineContact::canonical($request->input('phone'), true) ?? $request->input('phone')]);
        }

        if ($request->hasFile('logo')) {
            $rules['logo'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } elseif ($request->hasFile('logo_file')) {
            $rules['logo_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } else {
            $rules['logo'] = 'nullable|string|max:1000';
        }

        if ($request->hasFile('banner')) {
            $rules['banner'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:5120';
        } elseif ($request->hasFile('banner_file')) {
            $rules['banner_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:5120';
        } else {
            $rules['banner'] = 'nullable|string|max:1000';
        }

        $validated = $request->validate($rules);
        app(ShopEligibilityService::class)->protectReviewedDetails($shop, $request->all());

        if ($request->hasFile('logo') || $request->hasFile('logo_file')) {
            $file = $request->file('logo') ?? $request->file('logo_file');
            $path = $file->store('shops/logos', 'public');
            $shop->logo = '/storage/'.$path;
        } elseif ($request->filled('logo')) {
            $shop->logo = $validated['logo'];
        }

        if ($request->hasFile('banner') || $request->hasFile('banner_file')) {
            $file = $request->file('banner') ?? $request->file('banner_file');
            $path = $file->store('shops/banners', 'public');
            $shop->banner = '/storage/'.$path;
        } elseif ($request->filled('banner')) {
            $shop->banner = $validated['banner'];
        }

        if (array_key_exists('description', $validated)) {
            $shop->description = $validated['description'];
        }
        if (isset($validated['phone'])) {
            $shop->phone = $validated['phone'];
        }

        $shop->save();

        return back()->with('success', 'Storefront settings updated successfully.');
    }

    public function profile(Request $request): Response
    {
        $user = $request->user()->fresh();
        $shop = $this->getActiveShop($request, history: true);

        return Inertia::render('Seller/Profile', [
            ...app(AccountSettingsService::class)->presentation($user),
            'user' => $user,
            'shop' => $shop,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        return app(IdentityCorrectionService::class)->mutateProfile($request, function () use ($request) {
            $user = $request->user();

            $validated = app(ProfileInputService::class)->validate($request, ['name', 'phone', 'email'], [
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:3072',
                'remove_avatar' => 'nullable|boolean',
            ]);

            app(IdentityCorrectionService::class)->protectReviewedIdentity($user, $validated);

            if ($request->boolean('remove_avatar')) {
                if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $user->avatar);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
                $user->avatar = null;
            } elseif ($request->hasFile('avatar')) {
                if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $user->avatar);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }

                $path = $request->file('avatar')->store('avatars', 'public');
                $user->avatar = '/storage/'.$path;
            }

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->phone = $validated['phone'] ?? null;
            $user->save();

            return back()->with('success', 'Profile updated successfully.');
        });
    }

    public function previewStorefront(Request $request): Response
    {
        $shop = $this->getActiveShop($request);
        $shop->load('user');
        $shop->loadCount(['products' => function ($q) {
            $q->where('status', 'active');
        }]);

        $products = Product::where('shop_id', $shop->id)->withReviewSummary()
            ->where('status', 'active')
            ->with('images')
            ->latest()
            ->paginate(12);

        return Inertia::render('Marketplace/ShopDetail', [
            'shop' => $shop,
            'shopStats' => app(ReviewService::class)->shopSummary($shop),
            'products' => $products,
            'isOwner' => true,
            'isPreview' => true,
        ]);
    }
}
