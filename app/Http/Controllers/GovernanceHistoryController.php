<?php

namespace App\Http\Controllers;

use App\Services\AccountContextService;
use App\Services\GovernanceHistoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceHistoryController extends Controller
{
    public function index(Request $request, GovernanceHistoryService $history): Response
    {
        return Inertia::render('Governance/History', [
            'events' => $history->search($request->user(), $request->query()),
            'filters' => $request->only(['source', 'subject_type', 'subject_id', 'actor_id', 'from', 'to']),
            'sources' => $history->sourceOptions($request->user()), 'subjectTypes' => $history->subjectTypes($request->user()),
            'scope' => $history->actor($request->user())->isAdmin() ? 'platform' : 'own_company_resources',
        ]);
    }

    public function show(Request $request, string $source, int $decisionId, GovernanceHistoryService $history): Response
    {
        return Inertia::render('Governance/HistoryDetail', ['event' => $history->detail($request->user(), $source, $decisionId)]);
    }

    public function account(Request $request, int $userId, AccountContextService $context): Response
    {
        return Inertia::render('Admin/AccountContext', ['context' => $context->presentation($request->user(), $userId),
            'backUrl' => $request->is('admin/*') ? '/admin/users' : '/users']);
    }
}
