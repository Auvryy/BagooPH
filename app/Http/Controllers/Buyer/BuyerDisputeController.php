<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\BuyerAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerDisputeController extends Controller
{
    public function index(Request $request): Response
    {
        app(BuyerAccessService::class)->requirePortal($request->user());

        return Inertia::render('Buyer/Disputes', [
            'available' => false,
        ]);
    }
}
