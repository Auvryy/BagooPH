<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Services\Returns\BuyerReturnFinancialScope;
use App\Services\Returns\BuyerReturnLiabilityBalance;
use App\Services\Returns\BuyerReturnMoneyPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class BuyerReturnLiabilityController extends Controller
{
    public function index(Request $request, BuyerReturnFinancialScope $scope): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:repayment_ready,payment_recorded,repayment_disputed,repaid,closed_no_repayment']]);
        $query = $scope->query($request->user());
        if (Schema::hasTable('buyer_return_repayment_plans')) {
            $query->whereHas('repaymentPlan');
        } else {
            $query->whereKey(0);
        }
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($cases) => $cases->whereRaw("reference LIKE ? ESCAPE '!'", [$needle])
                ->orWhereHas('order', fn ($order) => $order->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle])));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $cases = $query->with('order', 'repaymentPlan')->orderByDesc('id')->paginate(12)->withQueryString();
        $cases->getCollection()->transform(function ($case) use ($request) {
            $balance = app(BuyerReturnLiabilityBalance::class)->forCase($case);
            $actor = $request->user();
            $outstanding = $actor->isBuyer() ? null : ($actor->isSeller() ? $balance['recovery_remaining_cents']
                : ($actor->isLogistics() ? $balance['compensation_remaining_cents'] : $balance['recovery_remaining_cents'] + $balance['compensation_remaining_cents']));

            return ['id' => $case->id, 'reference' => $case->reference, 'status' => $case->status,
                'order_number' => $case->order->order_number, 'mode' => $case->order->payment_method,
                'refund_cents' => $case->repaymentPlan->snapshot['buyer_refund_cents'], 'outstanding_cents' => $outstanding];
        });

        return Inertia::render('Returns/Liabilities', ['cases' => $cases, 'filters' => ['search' => $search, 'status' => $data['status'] ?? '']]);
    }

    public function show(Request $request, BuyerReturnCase $case, BuyerReturnFinancialScope $scope, BuyerReturnMoneyPresentation $money): Response
    {
        $case = $scope->query($request->user())->whereKey($case->id)->with('order')->firstOrFail();

        return Inertia::render('Returns/Liability', ['financialCase' => $case->only(['id', 'reference', 'status', 'version']) + [
            'order' => $case->order->only(['id', 'order_number']), 'money' => $money->present($request->user(), $case),
            'canViewReturn' => ! $request->user()->isLogistics()]]);
    }
}
