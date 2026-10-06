<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogisticsHubController extends Controller
{
    public function index(Request $request, AdminOverviewService $overview): Response
    {
        return Inertia::render('Admin/Logistics', $overview->logistics($request->user(), $request->query()));
    }
}
