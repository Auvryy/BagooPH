<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Services\Finance\ShipmentFinancialAccess;
use App\Services\Finance\ShipmentFinancialPresentation;
use App\Services\Finance\ShipmentLossAccess;
use App\Services\Finance\ShipmentLossPresentation;
use App\Services\Finance\ShipmentRefusalLiability;
use App\Services\Finance\ShipmentRefusalMoney;
use App\Services\Finance\ShipmentRefusalReview;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShipmentFinancialController extends Controller
{
    public function review(Request $request, Order $order, ShipmentRefusalReview $review)
    {
        app(WalletCreditService::class)->admin($request->user());
        abort_unless(Schema::hasTable('shipment_financial_plans'), 409, 'Shipment financial reviews are currently unavailable.');
        if ($prior = ShipmentFinancialPlan::where('active_order_id', $order->id)->first()) {
            return redirect('/shipment-finances/'.$prior->id);
        }
        $blocker = null;
        $source = null;
        $preview = null;
        $prepared = $request->session()->get('shipment_refusal_preview');
        $prepared = is_array($prepared) && ($prepared['order_id'] ?? null) === $order->id && ($prepared['actor_id'] ?? null) === $request->user()->id ? $prepared : null;
        try {
            $current = $review->preview($request->user(), $order, $prepared['value']['input'] ?? ['reverse_fee' => '0.00', 'cost_reference' => 'Awaiting actual cost review']);
            $source = $this->source($current['snapshot']);
            if ($prepared && hash_equals($current['review_token'], $prepared['value']['review_token'])) {
                $preview = $prepared['value'];
            }
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            $blocker = $error->getMessage();
        }

        return Inertia::render('Finance/ShipmentReview', ['order' => $order->only(['id', 'order_number']), 'source' => $source, 'preview' => $preview, 'blocker' => $blocker]);
    }

    public function preview(Request $request, Order $order, ShipmentRefusalReview $review)
    {
        try {
            $value = $review->preview($request->user(), $order, $request->all());
        } catch (HttpExceptionInterface $error) {
            return $this->conflict($request, $error, 'review');
        }
        $snapshot = $value['snapshot'];
        $data = ['review_token' => $value['review_token'], 'input' => ['reverse_fee' => $request->string('reverse_fee')->toString(), 'cost_reference' => trim($request->string('cost_reference')->toString())],
            'amounts' => Arr::only($snapshot, ['actual_buyer_paid_cents', 'original_merchandise_cents', 'buyer_outbound_shipping_cents',
                'reverse_fee_cents', 'reverse_fee_withheld_cents', 'buyer_refund_cents', 'buyer_liability_cents'])];

        if ($request->expectsJson()) {
            return response()->json(['preview' => $data])->header('Cache-Control', 'private, no-store');
        }
        $request->session()->put('shipment_refusal_preview', ['order_id' => $order->id, 'actor_id' => $request->user()->id, 'value' => $data]);

        return redirect('/shipment-finances/orders/'.$order->id.'/review');
    }

    public function approve(Request $request, Order $order, ShipmentRefusalReview $review)
    {
        app(WalletCreditService::class)->admin($request->user());
        $request->validate(['proof' => ['required', 'file', 'max:5120']]);
        try {
            $plan = $review->approve($request->user(), $order, $request->except('proof'), $request->file('proof'));
        } catch (HttpExceptionInterface $error) {
            return $this->conflict($request, $error, 'review');
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable $error) {
            return $this->unavailable($request, $error, 'review');
        }
        if (($request->session()->get('shipment_refusal_preview.order_id')) === $order->id) {
            $request->session()->forget('shipment_refusal_preview');
        }

        return $request->expectsJson() ? response()->json(['plan' => $plan->only(['id', 'reference', 'kind']), 'href' => '/shipment-finances/'.$plan->id], 201)->header('Cache-Control', 'private, no-store')
            : redirect('/shipment-finances/'.$plan->id)->with('success', 'The original refusal amounts were approved. No money moved.');
    }

    public function show(Request $request, ShipmentFinancialPlan $plan, ShipmentFinancialPresentation $presentation)
    {
        if ($plan->kind === 'transit_loss') {
            return Inertia::render('Finance/LossOutcome', ['loss' => app(ShipmentLossPresentation::class)->present($request->user(), $plan)])
                ->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Finance/ShipmentOutcome', ['financialPlan' => $presentation->present($request->user(), $plan)]);
    }

    public function action(Request $request, ShipmentFinancialPlan $plan, ShipmentFinancialAccess $access)
    {
        abort_unless($access->query($request->user())->whereKey($plan->id)->exists(), 404);
        $data = $request->validate(['action' => ['required', Rule::in(['execute-wallet-refusal', 'assess-cod-refusal',
            'offer-shipping-payment', 'receive-shipping-payment', 'pay-wallet-shipping'])]]);
        try {
            if (in_array($data['action'], ['execute-wallet-refusal', 'assess-cod-refusal'], true)) {
                abort_unless(($data['action'] === 'execute-wallet-refusal') === ($plan->snapshot['payment_method'] === 'wallet'), 409);
                $request->validate(['proof' => ['prohibited']]);
                $event = app(ShipmentRefusalMoney::class)->execute($request->user(), $plan, $request->except(['action', 'proof']));
            } else {
                $request->validate(['proof' => $data['action'] === 'pay-wallet-shipping' ? ['prohibited'] : ['required', 'file', 'max:5120']]);
                $event = app(ShipmentRefusalLiability::class)->command($request->user(), $plan, $data['action'], $request->except(['action', 'proof']), $request->file('proof'));
            }
        } catch (HttpExceptionInterface $error) {
            return $this->conflict($request, $error, 'payment');
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable $error) {
            return $this->unavailable($request, $error, 'payment');
        }

        return $request->expectsJson() ? response()->json(['event' => $event->only(['id', 'reference', 'event_type', 'amount_cents'])])->header('Cache-Control', 'private, no-store')
            : redirect('/shipment-finances/'.$plan->id)->with('success', 'The financial update was saved. Check its actual receipt status.');
    }

    public function proof(Request $request, ShipmentFinancialPlan $plan, ShipmentFinancialAccess $access)
    {
        if ($plan->kind === 'transit_loss') {
            $access = app(ShipmentLossAccess::class);
        }
        abort_unless($access->proof($request->user(), $plan), 404);

        return $this->download($plan);
    }

    public function eventProof(Request $request, ShipmentFinancialPlan $plan, ShipmentFinancialEvent $event, ShipmentFinancialAccess $access)
    {
        if ($plan->kind === 'transit_loss') {
            $access = app(ShipmentLossAccess::class);
        }
        abort_unless($access->proof($request->user(), $plan, $event), 404);

        return $this->download($event);
    }

    private function source(array $snapshot): array
    {
        return Arr::only($snapshot, ['payment_method', 'actual_buyer_paid_cents', 'original_merchandise_cents', 'buyer_outbound_shipping_cents', 'original_payment_reference'])
            + ['attempt_reference' => $snapshot['source']['attempt_reference'], 'return_route_reference' => $snapshot['source']['return_route_reference'],
                'seller_receipt_reference' => $snapshot['source']['seller_receipt_reference'], 'attempt_proof_url' => '/exceptions/attempt/'.$snapshot['source']['attempt_id'].'/proof'];
    }

    private function conflict(Request $request, HttpExceptionInterface $error, string $field)
    {
        if ($request->expectsJson() || $error->getStatusCode() !== 409) {
            throw $error;
        }

        return back()->withErrors([$field => $error->getMessage()]);
    }

    private function unavailable(Request $request, Throwable $error, string $field)
    {
        Log::warning('Shipment financial update could not be confirmed.', ['exception_type' => $error::class]);
        $message = 'This financial update could not be confirmed. Reload its saved history before retrying.';

        return $request->expectsJson() ? response()->json(['message' => $message], 503) : back()->withErrors([$field => $message]);
    }

    private function download(ShipmentFinancialPlan|ShipmentFinancialEvent $record)
    {
        $disk = Storage::disk('local');
        abort_unless($record->proof_path && preg_match('/\Ashipment-finance\/[a-f0-9-]+\.(?:jpg|png|webp|pdf)\z/', $record->proof_path)
            && $disk->exists($record->proof_path) && hash_equals($record->proof_hash, hash('sha256', $disk->get($record->proof_path))), 404,
            'The private evidence is unavailable. Its original financial record is retained.');

        return $disk->response($record->proof_path, 'Shipment-finances-'.$record->reference.'.'.pathinfo($record->proof_path, PATHINFO_EXTENSION),
            ['Content-Type' => $record->proof_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'attachment');
    }
}
