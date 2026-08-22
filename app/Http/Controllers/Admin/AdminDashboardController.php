<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(): Response
    {
        $totalUsers = User::count();
        $usersByRole = [
            'buyers' => User::where('role', 'buyer')->count(),
            'sellers' => User::where('role', 'seller')->count(),
            'couriers' => User::where('role', 'courier')->count(),
            'admins' => User::where('role', 'admin')->count(),
        ];

        $totalRevenue = Order::where('payment_status', 'paid')->sum('total_amount');
        $totalOrders = Order::count();
        $totalProducts = Product::count();
        $activeDeliveries = Delivery::whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])->count();

        $recentOrders = Order::with(['buyer', 'delivery.courier'])->latest()->take(6)->get();
        $recentUsers = User::latest()->take(6)->get();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalUsers' => $totalUsers,
                'usersByRole' => $usersByRole,
                'totalRevenue' => (float) $totalRevenue,
                'totalOrders' => $totalOrders,
                'totalProducts' => $totalProducts,
                'activeDeliveries' => $activeDeliveries,
            ],
            'recentOrders' => $recentOrders,
            'recentUsers' => $recentUsers,
        ]);
    }

    public function users(Request $request): Response
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->with('shop')->latest()->paginate(15)->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    public function updateUserRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,seller,buyer,courier,logistics',
            'status' => 'required|in:active,pending,suspended',
        ]);

        $user->update($validated);

        return back()->with('success', "User {$user->name}'s role updated to {$validated['role']}.");
    }

    public function products(): Response
    {
        $products = Product::with(['shop', 'category'])->latest()->paginate(15);

        return Inertia::render('Admin/Products', [
            'products' => $products,
        ]);
    }

    public function toggleProductStatus(Request $request, Product $product): RedirectResponse
    {
        $product->update([
            'status' => $product->status === 'active' ? 'draft' : 'active',
        ]);

        return back()->with('success', 'Product status toggled.');
    }
}
