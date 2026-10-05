<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Rules\ApplicationText;
use App\Services\ShopReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminShopReviewController extends Controller
{
    public function index(Request $request, ShopReviewService $reviews): Response
    {
        abort_unless($request->user()->isAdmin() && $request->user()->canAccessPortal(), 403);
        $filters = $request->validate(['status' => 'nullable|in:all,legacy,pending_approval,approved,rejected', 'search' => 'nullable|string|max:100']);
        $status = $filters['status'] ?? 'pending_approval';
        $shops = Shop::with(['user', 'rootCategory'])->when($status !== 'all', function ($query) use ($status) {
            $status === 'legacy' ? $query->whereNull('review_status') : $query->where('review_status', $status);
        })->when($filters['search'] ?? '', fn ($query, $search) => $query->where(fn ($q) => $q->whereLike('name', '%'.$search.'%')
            ->orWhereHas('user', fn ($owner) => $owner->whereLike('email', '%'.$search.'%'))))
            ->orderByDesc('review_submitted_at')->orderByDesc('id')->paginate(15)->withQueryString();
        $shops->through(fn ($shop) => $reviews->presentation($shop));

        return Inertia::render('Admin/ShopReviews', ['shops' => $shops, 'filters' => ['status' => $status, 'search' => $filters['search'] ?? '']]);
    }

    public function approve(Request $request, Shop $shop, ShopReviewService $reviews): RedirectResponse
    {
        $data = $request->validate(['review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/', 'evidence_confirmed' => 'required|accepted']);
        $reviews->decide($request, $shop, 'approved', $data);

        return back()->with('success', 'Shop approval recorded. Independent account and shop restrictions remain in effect.');
    }

    public function reject(Request $request, Shop $shop, ShopReviewService $reviews): RedirectResponse
    {
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim(\Normalizer::normalize($request->input('reason'), \Normalizer::FORM_KC), ' ')]);
        }
        $data = $request->validate(['review_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'reason' => ['bail', 'required', 'string', new ApplicationText('notes', 5, 1000)]]);
        $reviews->decide($request, $shop, 'rejected', $data);

        return back()->with('success', 'Shop rejection recorded with feedback.');
    }
}
