<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesVoucherCampaignWrites;
use App\Models\Voucher;
use App\Services\ShopEligibilityService;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerVoucherController extends Controller
{
    use HandlesVoucherCampaignWrites, HasSellerShop;

    public function index(Request $request, VoucherCampaignService $campaigns): Response
    {
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/Vouchers', [...$campaigns->listing($request->user(), $shop), 'shop' => $shop]);
    }

    public function show(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): Response
    {
        return Inertia::render('Seller/VoucherHistory', [...$campaigns->detail($request->user(), $voucher, $this->getActiveShop($request)),
            'backUrl' => $request->is('seller/*') ? '/seller/vouchers' : '/vouchers']);
    }

    public function store(Request $request, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, fn () => app(ShopEligibilityService::class)->mutate($request, function () use ($request, $campaigns) {
            $campaigns->saveDraft($request->user(), $request->all(), shop: $this->getActiveShop($request));

            return back()->with('success', 'Voucher draft saved. Review the terms before publishing.');
        }));
    }

    public function update(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, fn () => app(ShopEligibilityService::class)->mutate($request, function () use ($request, $voucher, $campaigns) {
            $campaigns->saveDraft($request->user(), $request->all(), $voucher, $this->getActiveShop($request));

            return back()->with('success', 'Revised draft saved. Published terms and placed orders keep their history.');
        }));
    }

    public function action(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, fn () => app(ShopEligibilityService::class)->mutate($request, function () use ($request, $voucher, $campaigns) {
            $campaigns->act($request->user(), $voucher, $request->all(), $this->getActiveShop($request));

            return back()->with('success', 'Campaign change recorded. Placed orders keep their original savings.');
        }));
    }

    public function toggle(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        $shop = $this->getActiveShop($request);
        abort_unless($voucher->shop_id === $shop->id, 403);
        if ($voucher->archived_at || ! $voucher->current_revision_id) {
            return back()->with('error', 'Save complete funded draft terms before publishing a campaign.');
        }
        $request->merge(['action' => $voucher->is_active ? 'pause' : 'resume']);

        return $this->action($request, $voucher, $campaigns);
    }

    public function destroy(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        $request->merge(['action' => 'archive']);

        return $this->action($request, $voucher, $campaigns);
    }
}
