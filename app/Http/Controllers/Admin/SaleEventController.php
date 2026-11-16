<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductSale;
use App\Models\SaleEvent;
use App\Models\SaleEventVoucher;
use App\Models\Voucher;
use App\Services\Promotions\SaleEventPresenter;
use App\Services\Promotions\SaleEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admins schedule sale events, add Bagoo vouchers and moderate the products sellers put on discount. */
class SaleEventController extends Controller
{
    public function __construct(private readonly SaleEventService $events, private readonly SaleEventPresenter $presenter) {}

    private function base(Request $request): string
    {
        return $request->is('admin/*') ? '/admin/sale-events' : '/sale-events';
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);
        $events = SaleEvent::withCount(['vouchers', 'productSales as products_count' => fn ($q) => $q->whereNull('ended_at')])
            ->withSum('vouchers as claims_count', 'claimed_count')
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate(20)->withPath($request->getPathInfo())
            ->through(fn (SaleEvent $event) => [...$this->presenter->summary($event),
                'vouchers_count' => $event->vouchers_count, 'products_count' => $event->products_count, 'claims_count' => (int) $event->claims_count]);

        return Inertia::render('Admin/SaleEvents/Index', ['events' => $events, 'base' => $this->base($request), 'serverNow' => now()->toIso8601String()]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Admin/SaleEvents/Edit', ['event' => null, 'vouchers' => [], 'products' => [], 'voucherOptions' => [],
            'base' => $this->base($request), 'serverNow' => now()->toIso8601String()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = $this->events->save($request->user(), $request->only(['title', 'badge', 'starts_at', 'ends_at']), $request->file('banner'));

        return redirect($this->base($request).'/'.$event->id)->with('success', 'Event saved. Add vouchers next; sellers can now add their products.');
    }

    public function show(Request $request, SaleEvent $event): Response
    {
        abort_unless($request->user()->isAdmin(), 403);
        $attached = $event->vouchers()->pluck('voucher_id');
        $options = Voucher::with('currentRevision')->where('issuer_type', 'platform')->where('is_active', true)->whereNull('archived_at')
            ->whereNotIn('id', $attached)->orderByDesc('id')->limit(100)->get()
            ->filter(fn (Voucher $voucher) => $voucher->currentRevision && $voucher->currentRevision->ends_at->isFuture())
            ->map(fn (Voucher $voucher) => ['id' => $voucher->id, 'code' => $voucher->currentRevision->code, 'name' => $voucher->currentRevision->name])->values();

        return Inertia::render('Admin/SaleEvents/Edit', [
            'event' => $this->presenter->summary($event),
            'vouchers' => $this->presenter->vouchers($event, includeUnavailable: true),
            'products' => $this->presenter->products($event, includeEnded: false),
            'voucherOptions' => $options,
            'base' => $this->base($request),
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    public function update(Request $request, SaleEvent $event): RedirectResponse
    {
        $this->events->save($request->user(), $request->only(['title', 'badge', 'starts_at', 'ends_at']), $request->file('banner'), $event);

        return back()->with('success', 'Event updated.');
    }

    public function end(Request $request, SaleEvent $event): RedirectResponse
    {
        $this->events->end($request->user(), $event);

        return back()->with('success', 'Event ended. Its product discounts stopped now.');
    }

    public function addVoucher(Request $request, SaleEvent $event): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->events->addVoucher($request->user(), $event, $request->only(['voucher_id', 'claim_limit']));

        return back()->with('success', 'Voucher added to the event.');
    }

    public function removeVoucher(Request $request, SaleEvent $event, SaleEventVoucher $entry): RedirectResponse
    {
        abort_unless($entry->sale_event_id === $event->id, 404);
        $this->events->removeVoucher($request->user(), $entry);

        return back()->with('success', 'Voucher removed from the event.');
    }

    public function removeProduct(Request $request, SaleEvent $event, ProductSale $sale): RedirectResponse
    {
        abort_unless($sale->sale_event_id === $event->id, 404);
        $this->events->removeProduct($request->user(), $sale);

        return back()->with('success', 'Product removed from the event. Its discount stopped now.');
    }
}
