<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesVoucherCampaignWrites;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoucherCampaignController extends Controller
{
    use HandlesVoucherCampaignWrites;

    public function index(Request $request, VoucherCampaignService $campaigns): Response
    {
        return Inertia::render('Admin/Vouchers', $campaigns->listing($request->user()));
    }

    public function show(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): Response
    {
        return Inertia::render('Admin/VoucherHistory', [...$campaigns->detail($request->user(), $voucher),
            'backUrl' => $request->is('admin/*') ? '/admin/vouchers' : '/vouchers']);
    }

    public function store(Request $request, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, function () use ($request, $campaigns) {
            $campaigns->saveDraft($request->user(), $request->all());

            return back()->with('success', 'Bagoo-funded draft saved. Review its spending ceiling before publishing.');
        });
    }

    public function update(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, function () use ($request, $voucher, $campaigns) {
            $campaigns->saveDraft($request->user(), $request->all(), $voucher);

            return back()->with('success', 'New Bagoo draft terms saved; previous terms stay recorded.');
        });
    }

    public function action(Request $request, Voucher $voucher, VoucherCampaignService $campaigns): RedirectResponse
    {
        return $this->campaignWrite($request, function () use ($request, $voucher, $campaigns) {
            $campaigns->act($request->user(), $voucher, $request->all());

            return back()->with('success', 'Campaign decision recorded with its original history.');
        });
    }
}
