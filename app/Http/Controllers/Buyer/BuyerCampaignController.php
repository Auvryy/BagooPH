<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\SaleEvent;
use App\Models\SaleEventVoucher;
use App\Services\Promotions\SaleEventPresenter;
use App\Services\Promotions\SaleEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer page for a sale event: its countdown, claimable vouchers and discounted products. */
class BuyerCampaignController extends Controller
{
    public function show(Request $request, string $event, SaleEventPresenter $presenter): Response
    {
        $sale = SaleEvent::where('slug', $event)->firstOrFail();
        $products = $presenter->products($sale);

        return Inertia::render('Buyer/Campaign', [
            'campaign' => $presenter->summary($sale),
            'vouchers' => $presenter->vouchers($sale, $request->user()),
            'products' => $products,
            'categories' => $products->pluck('category')->filter()->unique('id')->sortBy('name')->values(),
            'allCampaigns' => SaleEvent::open()->orderBy('starts_at')->limit(12)->get()
                ->map(fn (SaleEvent $item) => ['slug' => $item->slug, 'title' => $item->title, 'badge' => $item->badge, 'status' => $item->status()]),
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    public function claim(Request $request, SaleEventVoucher $entry, SaleEventService $events): JsonResponse|RedirectResponse
    {
        try {
            $result = $events->claim($request->user(), $entry);
        } catch (ValidationException $error) {
            $message = collect($error->errors())->flatten()->first();

            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($result) : back()->with('success', $result['message']);
    }

    public function claimAll(Request $request, SaleEvent $event, SaleEventService $events): JsonResponse|RedirectResponse
    {
        $result = $events->claimAll($request->user(), $event);
        $message = $result['claimed'] === 0 ? 'No vouchers are available to claim right now.'
            : ($result['unavailable'] > 0 ? "{$result['claimed']} vouchers claimed. {$result['unavailable']} were unavailable." : 'All vouchers claimed. They apply at checkout.');
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([...$result, 'message' => $message], $result['claimed'] === 0 ? 422 : 200);
        }

        return back()->with($result['claimed'] === 0 ? 'error' : 'success', $message);
    }
}
