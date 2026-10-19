<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Response;

class SellerDisputeController extends Controller
{
    use HasSellerShop;

    public function index(Request $request): Response
    {
        return app(\App\Http\Controllers\BuyerReturnController::class)->index($request, app(\App\Services\Returns\BuyerReturnService::class));
    }
}
