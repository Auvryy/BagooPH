<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerDisputeController extends Controller
{
    use HasSellerShop;

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/Disputes', [
            'available' => false,
            'shop' => $shop,
        ]);
    }
}
