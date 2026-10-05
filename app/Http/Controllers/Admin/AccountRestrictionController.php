<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountRestrictionController extends Controller
{
    public function show(Request $request, User $user, AccountRestrictionService $restrictions): Response
    {
        return Inertia::render('Admin/AccountRestriction', [
            'subject' => $restrictions->presentation($request->user(), $user),
            'endpoint' => $request->getPathInfo(),
            'backUrl' => $request->is('admin/*') ? '/admin/users' : '/users',
        ]);
    }

    public function store(Request $request, User $user, AccountRestrictionService $restrictions): RedirectResponse|JsonResponse
    {
        $decision = $restrictions->decide($request->user(), $user, $request->all());
        if ($request->wantsJson()) {
            return response()->json(['decision_id' => $decision->id, 'message' => 'Account activity decision recorded.']);
        }

        return back()->with('success', 'Account activity decision recorded. Approval, resources, duty and existing work remain separate.');
    }
}
