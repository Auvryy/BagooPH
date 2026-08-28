<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminKycController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'pending_approval');
        $role = $request->input('role', 'all');
        $search = $request->input('search');

        $query = User::with(['shop', 'courierProfile']);

        if ($status !== 'all') {
            $query->where('kyc_status', $status);
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

        $stats = [
            'pending_count' => User::where('kyc_status', 'pending_approval')->count(),
            'approved_count' => User::where('kyc_status', 'approved')->count(),
            'rejected_count' => User::where('kyc_status', 'rejected')->count(),
            'total_count' => User::count(),
            'pending_sellers' => User::where('role', 'seller')->where('kyc_status', 'pending_approval')->count(),
            'pending_couriers' => User::where('role', 'courier')->where('kyc_status', 'pending_approval')->count(),
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
        $user->update([
            'kyc_status' => 'approved',
            'status' => 'active',
            'kyc_reviewed_at' => now(),
            'kyc_feedback' => null,
        ]);

        if ($user->role === 'seller' && $user->shop) {
            $user->shop->update(['status' => 'active']);
        }

        if ($user->role === 'courier' && $user->courierProfile) {
            $user->courierProfile->update([
                'or_cr_status' => 'Verified & Registered',
                'is_available' => true,
            ]);
        }

        return back()->with('success', "Applicant {$user->name} ({$user->role}) has been approved successfully.");
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        $user->update([
            'kyc_status' => 'rejected',
            'status' => 'pending_approval',
            'kyc_feedback' => $validated['reason'],
            'kyc_reviewed_at' => now(),
        ]);

        if ($user->role === 'seller' && $user->shop) {
            $user->shop->update(['status' => 'pending']);
        }

        if ($user->role === 'courier' && $user->courierProfile) {
            $user->courierProfile->update([
                'is_available' => false,
            ]);
        }

        return back()->with('success', "Applicant {$user->name} has been rejected with feedback.");
    }
}
