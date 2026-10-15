<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ShopReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerShopController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $shops = Shop::with('rootCategory')->where('user_id', $request->user()->id)->limit(2)->get();
        abort_if($shops->count() > 1, 409, 'This account has conflicting shop ownership. Contact an administrator.');

        if ($shops->isNotEmpty()) {
            $request->session()->reflash();

            return redirect(str_starts_with($request->getHost(), 'seller.') ? '/settings' : route('seller.settings', absolute: false));
        }

        return Inertia::render('Seller/Shops', [
            'shop' => null,
        ]);
    }

    public function resubmit(Request $request, Shop $shop, ShopReviewService $reviews): RedirectResponse
    {
        abort_unless($shop->user_id === $request->user()->id, 403);
        $reviews->submit($request, $shop);

        return back()->with('success', 'Current shop details submitted for review.');
    }
}
