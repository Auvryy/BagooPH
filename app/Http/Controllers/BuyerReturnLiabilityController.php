<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\ShipmentFinancialPlan;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\ShipmentFinancialAccess;
use App\Services\Finance\ShipmentFinancialPresentation;
use App\Services\Finance\ShipmentLossAccess;
use App\Services\Finance\ShipmentLossQueue;
use App\Services\Returns\BuyerReturnFinancialScope;
use App\Services\Returns\BuyerReturnLiabilityBalance;
use App\Services\Returns\BuyerReturnMoneyPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BuyerReturnLiabilityController extends Controller
{
    use Concerns\EncryptsSensitiveHistory;

    public function index(Request $request, BuyerReturnFinancialScope $scope): Response|HttpResponse
    {
        $shipments = $request->input('view') === 'shipments';
        $losses = $request->input('view') === 'losses';
        $data = $request->validate(['view' => ['nullable', 'in:returns,shipments,losses'], 'review_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'search' => ['nullable', 'string', 'max:80'],
            'order_id' => ['sometimes', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'status' => ['nullable', $losses ? 'in:awaiting_payment,financially_resolved,voided'
                : ($shipments ? 'in:awaiting_execution,shipping_due,resolved' : 'in:repayment_ready,payment_recorded,repayment_disputed,repaid,closed_no_repayment')]]);
        if ($losses) {
            return $this->losses($request, $data);
        }
        if ($shipments) {
            return $this->shipments($request, $data);
        }
        $query = $scope->query($request->user());
        $query->when(isset($data['order_id']), fn ($rows) => $rows->where('order_id', $data['order_id']));
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
                'refund_cents' => $case->repaymentPlan->snapshot['buyer_refund_cents'], 'outstanding_cents' => $outstanding,
                'kind' => 'return', 'href' => '/return-liabilities/'.$case->id];
        });

        $this->encryptSensitiveHistory($request);

        return Inertia::render('Returns/Liabilities', ['cases' => $cases, 'filters' => ['search' => $search, 'status' => $data['status'] ?? '', 'view' => 'returns',
            ...isset($data['order_id']) ? ['order_id' => (int) $data['order_id']] : []]]);
    }

    private function shipments(Request $request, array $data): Response
    {
        $query = app(ShipmentFinancialAccess::class)->query($request->user());
        $query->when(isset($data['order_id']), fn ($rows) => $rows->where('order_id', $data['order_id']));
        $search = trim($data['search'] ?? '');
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        if ($search !== '') {
            $query->where(fn ($rows) => $rows->whereRaw("reference LIKE ? ESCAPE '!'", [$needle])
                ->orWhereHas('order', fn ($orders) => $orders->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle])));
        }
        $presentation = app(ShipmentFinancialPresentation::class);
        $presentation->filterStatus($query, $data['status'] ?? '');
        $cases = $query->with('order')->orderByDesc('id')->paginate(12)->withQueryString();
        $cases->getCollection()->transform(fn ($plan) => $presentation->row($plan));
        $candidates = null;
        if ($request->user()->isAdmin()) {
            $orders = Order::where('status', 'returned')->where('shipping_quote->refund_policy_version', 2)
                ->when(isset($data['order_id']), fn ($rows) => $rows->whereKey($data['order_id']))
                ->whereNotIn('id', ShipmentFinancialPlan::whereNotNull('active_order_id')->select('active_order_id'))
                ->whereHas('delivery', fn ($parcels) => $parcels->whereIn('id', DeliveryAttempt::where('reason_code', 'customer_refused')->select('delivery_id')));
            if ($search !== '') {
                $orders->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle]);
            }
            $candidates = $orders->orderByDesc('id')->paginate(12, ['id', 'order_number'], 'review_page')->withQueryString();
        }

        $this->encryptSensitiveHistory($request);

        return Inertia::render('Returns/Liabilities', ['cases' => $cases, 'reviewCandidates' => $candidates,
            'filters' => ['search' => $search, 'status' => $data['status'] ?? '', 'view' => 'shipments',
                ...isset($data['order_id']) ? ['order_id' => (int) $data['order_id']] : []]]);
    }

    private function losses(Request $request, array $data): HttpResponse
    {
        $query = app(ShipmentLossAccess::class)->query($request->user());
        $query->when(isset($data['order_id']), fn ($rows) => $rows->where('order_id', $data['order_id']));
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($plans) => $plans->whereRaw("reference LIKE ? ESCAPE '!'", [$needle])
                ->orWhereHas('order', fn ($orders) => $orders->whereRaw("order_number LIKE ? ESCAPE '!'", [$needle])));
        }
        $queue = app(ShipmentLossQueue::class);
        $queue->filter($query, $data['status'] ?? '');
        $cases = $query->with('order')->orderByDesc('id')->paginate(12)->withQueryString();
        $cases->getCollection()->transform(fn ($plan) => $queue->row($request->user(), $plan));

        $this->encryptSensitiveHistory($request);

        return Inertia::render('Finance/LossQueue', ['cases' => $cases,
            'reviewCandidates' => $request->user()->isAdmin() && ! isset($data['order_id']) ? $queue->candidates($request->user(), $search) : null,
            'filters' => ['view' => 'losses', 'search' => $search, 'status' => $data['status'] ?? '',
                ...isset($data['order_id']) ? ['order_id' => (int) $data['order_id']] : []]])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, BuyerReturnCase $case, BuyerReturnFinancialScope $scope, BuyerReturnMoneyPresentation $money): Response
    {
        $case = $scope->query($request->user())->whereKey($case->id)->with('order')->firstOrFail();
        $this->encryptSensitiveHistory($request);

        return Inertia::render('Returns/Liability', ['financialCase' => $case->only(['id', 'reference', 'status', 'version']) + [
            'order' => $case->order->only(['id', 'order_number']), 'money' => $money->present($request->user(), $case),
            'canViewReturn' => ! $request->user()->isLogistics()]]);
    }
}
