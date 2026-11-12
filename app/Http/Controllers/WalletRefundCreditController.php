<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Models\WalletRefundCreditRequest;
use App\Services\Returns\BuyerReturnFinancialScope;
use App\Services\Wallet\WalletRefundCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WalletRefundCreditController extends Controller
{
    public function store(Request $request, BuyerReturnCase $case, BuyerReturnFinancialScope $scope, WalletRefundCreditService $credits): JsonResponse|RedirectResponse
    {
        abort_unless($scope->query($request->user())->whereKey($case->id)->exists(), 404);
        $saved = $credits->request($request->user(), $case, $request->all());

        return $request->expectsJson() ? response()->json(['request' => $saved->only(['id', 'reference', 'status', 'version'])], 201)
            : redirect($this->target($request, $case))->with('success', 'The demonstration funding-credit request is awaiting independent review.');
    }

    public function review(Request $request, BuyerReturnCase $case, WalletRefundCreditRequest $funding, BuyerReturnFinancialScope $scope, WalletRefundCreditService $credits): JsonResponse|RedirectResponse
    {
        abort_unless($scope->query($request->user())->whereKey($case->id)->exists()
            && $case->repaymentPlan?->id === $funding->repayment_plan_id, 404);
        $saved = $credits->review($request->user(), $funding, $request->all());

        return $request->expectsJson() ? response()->json(['request' => $saved->only(['id', 'reference', 'status', 'version'])])
            : redirect($this->target($request, $case))->with('success', $saved->status === 'approved'
                ? 'The demonstration credits reached the liable account. Its refund funding is still a separate transfer.' : 'The original funding request was declined with the saved review.');
    }

    private function target(Request $request, BuyerReturnCase $case): string
    {
        return $request->user()->isLogistics() ? '/return-liabilities/'.$case->id : '/buyer-returns/'.$case->id;
    }
}
