<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDirectoryService;
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

    public function users(Request $request, AdminDirectoryService $directory): Response
    {
        return Inertia::render('Admin/Users', $directory->users($request->query(), $request->getPathInfo()));
    }

    public function products(Request $request, AdminDirectoryService $directory): Response
    {
        return Inertia::render('Admin/Products', $directory->products($request->query(), $request->getPathInfo()));
    }
}
