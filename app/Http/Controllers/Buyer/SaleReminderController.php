<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\ProductSale;
use App\Services\Commerce\SaleReminderService;
use Illuminate\Http\Request;

class SaleReminderController extends Controller
{
    public function store(Request $request, ProductSale $sale, SaleReminderService $service)
    {
        $service->set($request->user(), $sale, true);

        return back()->with('success', 'Sale reminder saved. Check your notifications shortly before the slot starts.');
    }

    public function destroy(Request $request, ProductSale $sale, SaleReminderService $service)
    {
        $service->set($request->user(), $sale, false);

        return back()->with('success', 'Sale reminder removed.');
    }
}
