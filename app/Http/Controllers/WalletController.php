<?php

namespace App\Http\Controllers;

use App\Models\WalletPayment;
use App\Models\WalletTopUpRequest;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    use Concerns\HandlesWalletCommands;

    public function requestCredits(Request $request): RedirectResponse|JsonResponse
    {
        $credit = $this->walletCommand($request, 'amount', fn () => app(WalletCreditService::class)->request($request->user(), $request->all()));

        return $request->expectsJson() ? response()->json(['reference' => $credit->reference, 'status' => $credit->status])
            : back()->with('success', 'Your demo credit request is awaiting admin review.');
    }

    public function admin(Request $request): Response
    {
        app(WalletCreditService::class)->admin($request->user());
        $status = $request->validate(['status' => 'nullable|in:pending,approved,rejected,all', 'page' => 'nullable|integer|min:1|max:100000'])['status'] ?? 'pending';
        $requests = WalletTopUpRequest::with('buyer:id,name,email')->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(15)->withQueryString()->through(fn ($credit) => [
                ...$credit->only(['id', 'reference', 'amount_cents', 'reason', 'status', 'review_reason', 'created_at', 'reviewed_at']),
                'buyer' => $credit->buyer->only(['id', 'name', 'email']),
            ]);

        return Inertia::render('Admin/WalletCredits', ['creditRequests' => $requests, 'currentStatus' => $status,
            'pendingCount' => WalletTopUpRequest::where('status', 'pending')->count(), 'requestToken' => (string) Str::uuid()]);
    }

    public function reviewCredits(Request $request, WalletTopUpRequest $credit): RedirectResponse|JsonResponse
    {
        $result = $this->walletCommand($request, 'reason', fn () => app(WalletCreditService::class)->review($request->user(), $credit, $request->all()));

        return $request->expectsJson() ? response()->json(['reference' => $result->reference, 'status' => $result->status])
            : back()->with('success', $result->status === 'approved' ? 'Demo credits added to the original buyer wallet.' : 'Credit request declined with your feedback.');
    }

    public function payment(Request $request, WalletPayment $payment): Response
    {
        return Inertia::render('Buyer/WalletPayment', ['payment' => app(WalletViewService::class)->payment($request->user(), $payment),
            'canUsePortal' => $request->user()->fresh()->canAccessPortal()]);
    }

    public function pay(Request $request, WalletPayment $payment, string $action): RedirectResponse|JsonResponse
    {
        $attempt = $this->walletCommand($request, 'payment', fn () => app(WalletPaymentService::class)->command($request->user(), $payment, $action, $request->all()));
        $failed = in_array($attempt->outcome, ['failed', 'expired', 'cancelled', 'refunded'], true);
        if ($request->expectsJson()) {
            return response()->json(['outcome' => $attempt->outcome, 'message' => $attempt->message, 'reference' => $attempt->reference], $failed ? 422 : 200);
        }
        if ($action === 'cancel') {
            return redirect()->route('buyer.orders.show', $payment->order_id)->with('info', $attempt->message);
        }

        return $failed ? back()->withErrors(['payment' => $attempt->message])
            : redirect()->route('buyer.orders.show', $payment->order_id)->with('success', $attempt->message);
    }
}
