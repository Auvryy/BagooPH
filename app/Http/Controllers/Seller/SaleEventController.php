<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SaleEvent;
use App\Models\SaleEventVoucher;
use App\Models\Voucher;
use App\Services\Commerce\SalePriceService;
use App\Services\Promotions\SaleEventPresenter;
use App\Services\Promotions\SaleEventService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sellers join Bagoo sale events with product discounts and their own claimable vouchers. */
class SaleEventController extends Controller
{
    use HasSellerShop;

    public function __construct(private readonly SaleEventService $events, private readonly SaleEventPresenter $presenter) {}

    private function base(Request $request): string
    {
        return $request->is('seller/*') ? '/seller/sale-events' : '/sale-events';
    }

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request);
        $events = SaleEvent::open()->orderBy('starts_at')->limit(30)->get()->map(fn (SaleEvent $event) => [
            ...$this->presenter->summary($event),
            'my_products' => ProductSale::where('sale_event_id', $event->id)->whereNull('ended_at')->whereHas('product', fn ($q) => $q->where('shop_id', $shop->id))->count(),
            'my_vouchers' => SaleEventVoucher::where('sale_event_id', $event->id)->where('shop_id', $shop->id)->count(),
        ]);

        return Inertia::render('Seller/SaleEvents/Index', ['events' => $events, 'base' => $this->base($request), 'serverNow' => now()->toIso8601String()]);
    }

    public function show(Request $request, SaleEvent $event): Response
    {
        $shop = $this->getActiveShop($request);
        $joined = ProductSale::where('sale_event_id', $event->id)->whereNull('ended_at')->pluck('product_id');
        $prices = app(SalePriceService::class);
        $eligibility = app(ShopEligibilityService::class);
        $available = Product::where('shop_id', $shop->id)->where('status', 'active')->whereNotIn('id', $joined)->orderBy('name')->limit(300)->get()
            ->filter(fn (Product $product) => $eligibility->productIsEligible($product))
            ->map(fn (Product $product) => ['id' => $product->id, 'name' => html_entity_decode($product->name, ENT_QUOTES | ENT_HTML5), 'image' => $product->featured_image,
                'price' => min($prices->regularPrices($product)) / 100, 'stock' => (int) $product->stock])->values();
        $attached = SaleEventVoucher::where('sale_event_id', $event->id)->pluck('voucher_id');
        $voucherOptions = Voucher::with('currentRevision')->where('issuer_type', 'shop')->where('shop_id', $shop->id)->where('is_active', true)
            ->whereNull('archived_at')->whereNotIn('id', $attached)->orderByDesc('id')->limit(100)->get()
            ->filter(fn (Voucher $voucher) => $voucher->currentRevision && $voucher->currentRevision->ends_at->isFuture())
            ->map(fn (Voucher $voucher) => ['id' => $voucher->id, 'code' => $voucher->currentRevision->code, 'name' => $voucher->currentRevision->name])->values();

        return Inertia::render('Seller/SaleEvents/Show', [
            'event' => $this->presenter->summary($event),
            'products' => $this->presenter->products($event, $shop->id),
            'vouchers' => $this->presenter->vouchers($event, shopId: $shop->id, includeUnavailable: true),
            'availableProducts' => $available,
            'voucherOptions' => $voucherOptions,
            'base' => $this->base($request),
            'vouchersUrl' => $request->is('seller/*') ? '/seller/vouchers' : '/vouchers',
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    public function addProducts(Request $request, SaleEvent $event): RedirectResponse
    {
        $count = app(ShopEligibilityService::class)->mutate($request, fn () => $this->events->addProducts(
            $this->getActiveShop($request), $event, (array) $request->input('product_ids', []), (int) $request->input('percentage')));

        return back()->with('success', $count === 1 ? '1 product added to the event.' : "{$count} products added to the event.");
    }

    public function removeProduct(Request $request, SaleEvent $event, ProductSale $sale): RedirectResponse
    {
        abort_unless($sale->sale_event_id === $event->id, 404);
        app(ShopEligibilityService::class)->mutate($request, fn () => $this->events->removeProduct($request->user(), $sale, $this->getActiveShop($request)));

        return back()->with('success', 'Product removed from the event. Its regular price is back.');
    }

    public function addVoucher(Request $request, SaleEvent $event): RedirectResponse
    {
        $this->events->addVoucher($request->user(), $event, $request->only(['voucher_id', 'claim_limit']), $this->getActiveShop($request));

        return back()->with('success', 'Voucher added. Buyers can claim it on the event page.');
    }

    public function removeVoucher(Request $request, SaleEvent $event, SaleEventVoucher $entry): RedirectResponse
    {
        abort_unless($entry->sale_event_id === $event->id, 404);
        $this->events->removeVoucher($request->user(), $entry, $this->getActiveShop($request));

        return back()->with('success', 'Voucher removed from the event.');
    }
}
