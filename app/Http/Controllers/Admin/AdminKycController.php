<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KycDecisionService;
use App\Services\VerificationDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminKycController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->status === 'active', 403);
        $status = $request->input('status', 'pending_approval');
        $role = $request->input('role', 'all');
        $search = $request->input('search');

        $query = User::with(['shop' => fn ($query) => $query->orderBy('id')->with('rootCategory'), 'courierProfile', 'logisticsCompany'])->whereIn('role', ['buyer', 'seller', 'courier', 'logistics']);

        if ($status !== 'all') {
            $query->whereIn('kyc_status', $status === 'approved' ? User::APPROVED_KYC_STATUSES : [$status]);
        }

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $applicants = $query->latest('kyc_submitted_at')->paginate(15)->withQueryString();
        $applicants->through(fn (User $user) => [...$user->toArray(), ...app(VerificationDocumentService::class)->links($user), ...app(KycDecisionService::class)->presentation($user)]);

        $stats = [
            'pending_count' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->where('kyc_status', 'pending_approval')->count(),
            'approved_count' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->whereIn('kyc_status', User::APPROVED_KYC_STATUSES)->count(),
            'rejected_count' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->where('kyc_status', 'rejected')->count(),
            'total_count' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->count(),
            'pending_sellers' => User::where('role', 'seller')->where('kyc_status', 'pending_approval')->count(),
            'pending_couriers' => User::where('role', 'courier')->where('kyc_status', 'pending_approval')->count(),
            'pending_logistics' => User::where('role', 'logistics')->where('kyc_status', 'pending_approval')->count(),
            'pending_buyers' => User::where('role', 'buyer')->where('kyc_status', 'pending_approval')->count(),
        ];

        return Inertia::render('Admin/KycQueue', [
            'applicants' => $applicants,
            'filters' => [
                'status' => $status,
                'role' => $role,
                'search' => $search ?? '',
            ],
            'stats' => $stats,
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->status === 'active', 403);
        $validated = $request->validate([
            'review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'evidence_confirmed' => 'required|accepted',
        ]);
        app(KycDecisionService::class)->decide($request, $user, 'approved', $validated);

        return back()->with('success', 'Approval recorded. Independent account and profile restrictions remain in effect.');
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->status === 'active', 403);
        $validated = $request->validate([
            'review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        app(KycDecisionService::class)->decide($request, $user, 'rejected', $validated);

        return back()->with('success', "Applicant {$user->name} has been rejected with feedback.");
    }
}
