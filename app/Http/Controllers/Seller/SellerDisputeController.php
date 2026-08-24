<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerDisputeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        $disputes = [
            [
                'id' => 'DSP-2026-001',
                'order_number' => 'ORD-2026-8891',
                'buyer_name' => 'Maria Santos',
                'buyer_phone' => '+63 917 123 4567',
                'product_name' => 'Techwear Ergonomic Commuter Backpack',
                'reason' => 'Defective zipper & tear on strap',
                'claim_type' => 'Replacement / Exchange',
                'amount' => 2850.00,
                'status' => 'pending_seller', // pending_seller, under_review, resolved, dismissed
                'status_label' => 'Action Required by Merchant',
                'created_at' => 'Aug 23, 2026',
                'proof_image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&auto=format&fit=crop&q=60',
                'buyer_description' => 'The main zipper broke upon initial opening and there is a stitching tear on the shoulder strap.',
            ],
            [
                'id' => 'DSP-2026-002',
                'order_number' => 'ORD-2026-8742',
                'buyer_name' => 'Juan Dela Cruz',
                'buyer_phone' => '+63 918 765 4321',
                'product_name' => 'ANC Wireless Studio Headphones',
                'reason' => 'Wrong color delivered',
                'claim_type' => 'Replacement',
                'amount' => 4500.00,
                'status' => 'resolved',
                'status_label' => 'Resolved (Exchange Dispatched)',
                'created_at' => 'Aug 20, 2026',
                'proof_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&auto=format&fit=crop&q=60',
                'buyer_description' => 'Ordered Matte Black but received Chalk White instead.',
            ],
        ];

        return Inertia::render('Seller/Disputes', [
            'disputes' => $disputes,
            'shop' => $shop,
        ]);
    }

    public function respond(Request $request, string $disputeId): RedirectResponse
    {
        $request->validate([
            'action' => 'required|in:accept_exchange,accept_refund,dispute_claim',
            'explanation' => 'nullable|string|max:500',
        ]);

        return back()->with('success', 'Dispute response recorded and dispatched to customer and logistics.');
    }
}
