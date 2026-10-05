<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Services\MasterCategoryService;
use App\Services\ShopEligibilityService;
use App\Services\ShopReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SellerShopController extends Controller
{
    public function index(Request $request, ShopReviewService $reviews): Response
    {
        return Inertia::render('Seller/Shops', [
            'shops' => Shop::with('rootCategory')->where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('id')
                ->get()->map(fn ($shop) => $reviews->presentation($shop)),
            'categories' => app(MasterCategoryService::class)->choices(),
        ]);
    }

    public function store(Request $request, ShopReviewService $reviews): RedirectResponse
    {
        $reviews->submit($request);

        return redirect()->route('seller.shops.index')->with('success', 'Shop submitted for Platform Admin review. It can accept new work after approval.');
    }

    public function resubmit(Request $request, Shop $shop, ShopReviewService $reviews): RedirectResponse
    {
        abort_unless($shop->user_id === $request->user()->id, 403);
        $reviews->submit($request, $shop);

        return back()->with('success', 'Current shop details submitted for review.');
    }

    public function switchShop(Request $request, ShopEligibilityService $eligibility): RedirectResponse
    {
        $validated = $request->validate(['shop_id' => 'required|integer|min:1']);
        $shop = DB::transaction(function () use ($request, $validated, $eligibility) {
            $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->isSeller() && $owner->canAccessPortal(), 403);
            $shop = Shop::where('user_id', $owner->id)->whereKey($validated['shop_id'])->lockForUpdate()->first();
            abort_unless($shop, 403);
            $eligibility->lockCategories();
            $eligibility->assertEligible($shop);

            return $shop;
        }, 3);
        $request->session()->put('active_seller_shop_id', $shop->id);

        return redirect()->route('seller.dashboard')->with('success', "Selected {$shop->name}.");
    }
}
