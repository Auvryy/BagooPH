<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerDisputeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Sample disputes filed by user
        $disputes = [
            [
                'id' => 'DSP-2026-001',
                'order_number' => 'ORD-2026-8891',
                'product_name' => 'Techwear Ergonomic Commuter Backpack',
                'shop_name' => 'Acro Tactical Gear',
                'reason' => 'Defective zipper & tear on strap',
                'refund_amount' => 2850.00,
                'status' => 'under_review', // pending_seller, under_review, approved, rejected, resolved
                'status_label' => 'Under Platform Review',
                'created_at' => 'Aug 23, 2026',
                'proof_image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&auto=format&fit=crop&q=60',
                'seller_response' => 'We have acknowledged the issue and dispatched replacement unit.',
                'timeline' => [
                    ['title' => 'Issue Reported', 'time' => 'Aug 23, 2026 10:30 AM', 'done' => true],
                    ['title' => 'Seller Review & Evidence Inspection', 'time' => 'Aug 23, 2026 02:15 PM', 'done' => true],
                    ['title' => 'Tripartite Logistics & Platform Mediation', 'time' => 'In Progress', 'done' => false],
                    ['title' => 'Resolution & Refund / Exchange', 'time' => 'Pending', 'done' => false],
                ],
            ],
        ];

        // Eligible delivered orders for filing
        $eligibleOrders = Order::with(['items.product', 'shop'])
            ->where('buyer_id', $user->id)
            ->whereIn('status', ['delivered', 'shipped'])
            ->latest()
            ->get();

        return Inertia::render('Buyer/Disputes', [
            'disputes' => $disputes,
            'eligibleOrders' => $eligibleOrders,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'order_id' => 'required',
            'reason' => 'required|string|max:100',
            'description' => 'required|string|max:1000',
            'proof_image' => 'nullable|string',
        ]);

        return back()->with('success', 'Your return / defect report has been submitted to the dispute resolution center.');
    }
}
