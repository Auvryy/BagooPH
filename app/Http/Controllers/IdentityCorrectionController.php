<?php

namespace App\Http\Controllers;

use App\Models\IdentityCorrectionRequest;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\BirthDateEligibility;
use App\Services\IdentityCorrectionService;
use App\Services\MasterCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdentityCorrectionController extends Controller
{
    public function index(Request $request, IdentityCorrectionService $corrections, AccountRestrictionService $restrictions): Response
    {
        $restrictions->currentActor($request->user());
        $candidates = User::where(fn ($query) => $query->whereIn('kyc_status', User::APPROVED_KYC_STATUSES)->orWhere('role', 'admin'))
            ->whereNull('closed_at')
            ->where(function ($query) {
                $dates = app(BirthDateEligibility::class)->limits();
                $query->whereNull('birthday')->orWhereDate('birthday', '>', $dates['past_maximum'])
                    ->orWhere(fn ($worker) => $worker->whereIn('role', ['seller', 'courier', 'logistics', 'admin'])->whereDate('birthday', '>', $dates['adult_maximum']))
                    ->orWhereHas('shop', fn ($shop) => $shop->whereNull('root_category_id')->orWhereNotIn('root_category_id', app(MasterCategoryService::class)->activeRoots()->select('id')));
            })->orderBy('id')->paginate(20)->through(fn ($user) => $user->only(['id', 'name', 'role', 'birthday']));
        $requests = IdentityCorrectionRequest::whereDoesntHave('decision')->whereIn('user_id', User::whereNull('closed_at')->select('id'))->orderBy('id')->paginate(20, pageName: 'requests_page')
            ->through(fn ($correction) => ['id' => $correction->id, 'user_id' => $correction->user_id, 'name' => User::find($correction->user_id)?->name,
                'reason' => $correction->reason, 'requested_at' => $correction->requested_at->toISOString()]);

        return Inertia::render('Admin/IdentityCorrections', ['candidates' => $candidates, 'requests' => $requests,
            'baseUrl' => $request->is('admin/*') ? '/admin' : '']);
    }

    public function show(Request $request, IdentityCorrectionService $corrections, ?User $user = null): Response|RedirectResponse
    {
        $user ??= $request->user();
        $admin = $request->route('user') !== null;
        if ($admin) {
            app(AccountRestrictionService::class)->currentActor($request->user());
        }
        $data = $request->validate(['shop_id' => 'nullable|integer|min:1']);
        if (! $admin) {
            $corrections->form($request->user(), $user, $data['shop_id'] ?? null);

            return redirect('/account/settings#identity-correction');
        }

        return Inertia::render('Governance/IdentityCorrection', ['subject' => $corrections->form($request->user(), $user, $data['shop_id'] ?? null),
            'categories' => app(MasterCategoryService::class)->choices(), 'adminReview' => $admin,
            'baseUrl' => $admin ? ($request->is('admin/*') ? '/admin/users/'.$user->id.'/identity-corrections' : '/users/'.$user->id.'/identity-corrections') : '/account/identity-corrections',
            'decisionBaseUrl' => $request->is('admin/*') ? '/admin/identity-corrections' : '/identity-corrections',
            'affectedWork' => $admin ? app(AccountRestrictionService::class)->presentation($request->user(), $user)['affected_work'] : [],
            'listings' => $admin ? $corrections->affectedListings($request->user(), $user, $data['shop_id'] ?? null) : []]);
    }

    public function store(Request $request, IdentityCorrectionService $corrections, ?User $user = null): RedirectResponse|JsonResponse
    {
        if ($request->route('user') !== null) {
            app(AccountRestrictionService::class)->currentActor($request->user());
        }
        $correction = $corrections->submit($request, $user ?? $request->user());
        if ($request->expectsJson()) {
            return response()->json(['id' => $correction->id]);
        }

        return back()->with('success', 'Correction requested. Reviewed details stay unchanged until the evidence is approved.');
    }

    public function decide(Request $request, IdentityCorrectionRequest $correction, IdentityCorrectionService $corrections): RedirectResponse|JsonResponse
    {
        $decision = $corrections->decide($request, $correction);
        if ($request->expectsJson()) {
            return response()->json(['id' => $decision->id, 'action' => $decision->action]);
        }

        return back()->with('success', 'Correction decision recorded. Existing restrictions and work remain in place.');
    }

    public function document(Request $request, IdentityCorrectionRequest $correction, string $document, IdentityCorrectionService $corrections): StreamedResponse
    {
        return $corrections->document($request, $correction, $document);
    }
}
