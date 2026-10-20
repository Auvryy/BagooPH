<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Wallet\WalletDeliveryRefundService;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WalletProceedsController extends Controller
{
    use Concerns\HandlesWalletCommands;

    public function refundReturned(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $result = $this->walletCommand($request, 'reason', fn () => app(WalletDeliveryRefundService::class)->refund($request->user(), $order, $request->all()));

        return $request->expectsJson() ? response()->json(['reference' => $result->reference, 'status' => 'refunded'])
            : back()->with('success', 'The undelivered order payment was returned to the original virtual wallet.');
    }

    public function index(Request $request): Response
    {
        $service = app(WalletSettlementService::class);
        $query = $service->scoped($request->user());
        $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $orders = $query->with(['items.shop.user', 'walletPayment', 'delivery'])->latest('id')->paginate(12)->withQueryString()
            ->through(fn ($order) => $service->present($order));

        return Inertia::render('Finance/WalletProceeds', ['records' => $orders, 'canSettle' => $request->user()->isAdmin(),
            'virtualBalanceCents' => $request->user()->isSeller() ? app(WalletLedger::class)->balance($request->user()) : null,
            'requestToken' => (string) Str::uuid()]);
    }

    public function settle(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $result = $this->walletCommand($request, 'reason', fn () => app(WalletSettlementService::class)->settle($request->user(), $order, $request->all()));

        return $request->expectsJson() ? response()->json(['reference' => $result->reference, 'status' => 'credited'])
            : back()->with('success', 'Virtual seller proceeds credited once. No cash payment was recorded.');
    }
}
