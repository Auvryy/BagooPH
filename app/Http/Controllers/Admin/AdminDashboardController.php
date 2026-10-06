<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(Request $request, AdminOverviewService $overview): Response
    {
        return Inertia::render('Admin/Dashboard', $overview->overview($request->user(), $request->is('admin/*') ? '/admin' : ''));
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
            'activityBaseUrl' => $request->getPathInfo(),
        ]);
    }

    public function products(Request $request): Response
    {
        $products = Product::with(['shop', 'category'])->latest()->paginate(15);

        return Inertia::render('Admin/Products', [
            'products' => $products,
            'moderationBaseUrl' => $request->getPathInfo(),
        ]);
    }
}
