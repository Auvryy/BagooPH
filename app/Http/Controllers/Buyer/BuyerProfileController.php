<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $savedAddresses = [
            [
                'id' => 1,
                'is_default' => true,
                'recipient_name' => $user->name,
                'phone' => $user->phone ?? '+63 912 345 6789',
                'province' => 'Metro Manila',
                'city' => 'Taguig City',
                'barangay' => 'Fort Bonifacio (BGC)',
                'street' => 'Unit 1204, High Street Residences, 28th St.',
                'type' => 'Home',
            ],
            [
                'id' => 2,
                'is_default' => false,
                'recipient_name' => $user->name,
                'phone' => $user->phone ?? '+63 912 345 6789',
                'province' => 'Metro Manila',
                'city' => 'Makati City',
                'barangay' => 'Bel-Air',
                'street' => '8th Floor, Ayala Tower One, Ayala Ave.',
                'type' => 'Office / Work',
            ],
        ];

        $wallet = [
            'balance' => 5000.00,
            'currency' => 'PHP',
            'status' => 'Active',
            'account_number' => 'BG-WLT-' . str_pad((string)$user->id, 6, '0', STR_PAD_LEFT),
            'recent_transactions' => [
                ['id' => 'tx-1', 'title' => 'Top-up via Sandbox Simulation', 'amount' => 5000.00, 'type' => 'credit', 'date' => 'Today'],
                ['id' => 'tx-2', 'title' => 'Order Payment #ORD-8821', 'amount' => -1250.00, 'type' => 'debit', 'date' => 'Yesterday'],
            ],
        ];

        $orders = Order::where('buyer_id', $user->id)
            ->with(['items.product.shop', 'delivery.courier'])
            ->latest()
            ->get();

        $initialTab = $request->query('tab', 'orders');

        return Inertia::render('Buyer/Profile', [
            'user' => $user,
            'addresses' => $savedAddresses,
            'wallet' => $wallet,
            'orders' => $orders,
            'ordersCount' => $orders->count(),
            'initialTab' => $initialTab,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'birthday' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
        ]);

        $user->update($validated);

        return back()->with('success', 'Profile updated successfully.');
    }
}
