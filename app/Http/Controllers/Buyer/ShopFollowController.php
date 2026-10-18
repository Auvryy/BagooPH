<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Rules\AsciiPositiveInteger;
use App\Services\Commerce\ShopFollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopFollowController extends Controller
{
    public function index(Request $request, ShopFollowService $follows): Response
    {
        $request->validate(['page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000']]);

        return Inertia::render('Buyer/FollowedShops', ['entries' => $follows->paginate($request->user())]);
    }

    public function store(Request $request, Shop $shop, ShopFollowService $follows): JsonResponse|RedirectResponse
    {
        $follows->follow($request->user(), $shop);

        return $request->expectsJson() ? response()->json(['shop_id' => $shop->id, 'following' => true]) : back()->with('success', 'Shop followed.');
    }

    public function destroy(Request $request, Shop $shop, ShopFollowService $follows): JsonResponse|RedirectResponse
    {
        $follows->unfollow($request->user(), $shop->id);

        return $request->expectsJson() ? response()->json(['shop_id' => $shop->id, 'following' => false]) : back()->with('success', 'Shop unfollowed.');
    }
}
