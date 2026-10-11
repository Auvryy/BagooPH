<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Voucher;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerVoucherController extends Controller
{
    use HasSellerShop;

    private function getShop(Request $request): Shop
    {
        return $this->getActiveShop($request);
    }

    public function index(Request $request): Response
    {
        $shop = $this->getShop($request);
        $vouchers = Voucher::where('shop_id', $shop->id)
            ->latest()
            ->paginate(15);

        return Inertia::render('Seller/Vouchers', [
            'vouchers' => $vouchers,
            'shop' => $shop,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->storeInShop($request));
    }

    private function storeInShop(Request $request): RedirectResponse
    {
        $shop = $this->getShop($request);

        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:vouchers,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:1',
            'min_spend' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:1',
            'usage_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date|after:today',
        ]);

        Voucher::create([
            ...$validated,
            'code' => strtoupper(trim($validated['code'])),
            'shop_id' => $shop->id,
            'issuer_type' => 'shop',
            'is_active' => false,
            'used_count' => 0,
        ]);

        return back()->with('success', "Voucher draft '{$validated['code']}' saved. Funding and usage limits need review before publication.");
    }

    public function toggle(Request $request, Voucher $voucher): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->toggleInShop($request, $voucher));
    }

    private function toggleInShop(Request $request, Voucher $voucher): RedirectResponse
    {
        $shop = $this->getShop($request);

        if ($voucher->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        if ($voucher->archived_at || ! $voucher->current_revision_id) {
            return back()->with('error', 'This voucher needs reviewed funding and limits before it can be activated.');
        }

        $voucher->update([
            'is_active' => ! $voucher->is_active,
        ]);

        $status = $voucher->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Voucher '{$voucher->code}' has been {$status}.");
    }

    public function destroy(Request $request, Voucher $voucher): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, fn () => $this->destroyInShop($request, $voucher));
    }

    private function destroyInShop(Request $request, Voucher $voucher): RedirectResponse
    {
        $shop = $this->getShop($request);

        if ($voucher->shop_id !== $shop->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        if ($voucher->current_revision_id || $voucher->used_count > 0 || $voucher->archived_at) {
            $voucher->update(['is_active' => false, 'archived_at' => $voucher->archived_at ?? now()]);

            return back()->with('success', "Voucher '{$voucher->code}' archived; its history is retained.");
        }
        $voucher->delete();

        return back()->with('success', "Voucher '{$voucher->code}' removed.");
    }
}
