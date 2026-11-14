<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnManifestParcel;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossDisposition;
use App\Models\ShipmentLossFinding;
use App\Models\ShipmentLossReconciliation;
use App\Models\WalletRefundCreditRequest;
use App\Services\Finance\ShipmentLossAccess;
use App\Services\Finance\ShipmentLossActions;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletLossCreditService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShipmentLossController extends Controller
{
    public function review(Request $request, LogisticsManifestEvent $observation, ShipmentLossReview $review)
    {
        $this->admin($request);
        $order = $this->order($observation);
        if ($plan = ShipmentFinancialPlan::where('loss_observation_id', $observation->id)
            ->where(fn ($query) => $query->whereNotNull('active_order_id')->orWhereNotNull('active_return_case_id'))->first()) {
            return redirect('/shipment-finances/'.$plan->id);
        }
        $prepared = $request->session()->get('shipment_loss_preview');
        $prepared = is_array($prepared) && ($prepared['observation_id'] ?? null) === $observation->id
            && ($prepared['actor_id'] ?? null) === $request->user()->id ? $prepared : null;
        $source = $basis = $preview = $blocker = null;
        try {
            $current = $review->preview($request->user(), $observation, $prepared['value']['input']
                ?? ['seller_protection' => '0.00', 'investigation_reference' => 'Awaiting independent investigation']);
            $snapshot = $current['snapshot'];
            $source = Arr::only($snapshot['source'], ['observation_id', 'observation_reference', 'manifest_reference', 'barcode', 'direction'])
                + ['scope' => $snapshot['scope'], 'company_name' => $observation->manifest->company->name];
            $basis = Arr::only($snapshot, ['original_merchandise_cents', 'buyer_paid_shipping_cents', 'actual_buyer_paid_cents',
                'buyer_refund_cents', 'seller_protection_cap_cents', 'protected_earned_shipping_cents']);
            if ($prepared && hash_equals($current['review_token'], $prepared['value']['review_token'])) {
                $preview = $prepared['value'];
            } elseif ($prepared) {
                $request->session()->forget('shipment_loss_preview');
            }
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            $blocker = $error->getMessage();
            if ($prepared) {
                $request->session()->forget('shipment_loss_preview');
            }
        }

        return Inertia::render('Finance/LossReview', ['order' => $order->only(['id', 'order_number']),
            'source' => $source, 'basis' => $basis, 'preview' => $preview, 'blocker' => $blocker, 'requestToken' => (string) Str::uuid()])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, LogisticsManifestEvent $observation, ShipmentLossReview $review)
    {
        $this->admin($request);
        try {
            $value = $review->preview($request->user(), $observation, $request->all());
        } catch (ValidationException|HttpExceptionInterface $error) {
            return $this->rejected($request, $error);
        }
        $preview = ['input' => ['seller_protection' => $request->string('seller_protection')->toString(),
            'investigation_reference' => trim($request->string('investigation_reference')->toString())],
            'review_token' => $value['review_token'], 'amounts' => Arr::only($value['snapshot'], ['buyer_refund_cents',
                'seller_protection_cents', 'protected_earned_shipping_cents', 'company_funding_due_cents',
                'original_escrow_required_cents', 'reserve_goal_cents'])];
        if ($request->expectsJson()) {
            return response()->json(['preview' => $preview])->header('Cache-Control', 'private, no-store');
        }
        $request->session()->put('shipment_loss_preview', ['observation_id' => $observation->id,
            'actor_id' => $request->user()->id, 'value' => $preview]);

        return redirect('/shipment-finances/missing/'.$observation->id.'/review');
    }

    public function approve(Request $request, LogisticsManifestEvent $observation, ShipmentLossReview $review)
    {
        $this->admin($request);
        try {
            $request->validate(['proof' => ['required', 'file', 'max:5120']]);
            $plan = $review->approve($request->user(), $observation, $request->except('proof'), $request->file('proof'));
        } catch (ValidationException|HttpExceptionInterface $error) {
            return $this->rejected($request, $error);
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Shipment loss review could not be confirmed.', ['exception_type' => $error::class]);
            $message = 'This loss review could not be confirmed. Reload its saved history before retrying.';

            return $request->expectsJson() ? response()->json(['message' => $message], 503)
                : back()->withErrors(['review' => $message]);
        }
        if ($request->session()->get('shipment_loss_preview.observation_id') === $observation->id
            && $request->session()->get('shipment_loss_preview.actor_id') === $request->user()->id) {
            $request->session()->forget('shipment_loss_preview');
        }

        return $request->expectsJson() ? response()->json(['plan' => $plan->only(['id', 'reference', 'kind']),
            'href' => '/shipment-finances/'.$plan->id], 201)->header('Cache-Control', 'private, no-store')
            : redirect('/shipment-finances/'.$plan->id)->with('success', 'The original loss amounts were approved. No money moved.');
    }

    private function admin(Request $request): void
    {
        app(WalletCreditService::class)->admin($request->user());
        abort_unless(Schema::hasTable('shipment_financial_plans'), 409, 'Shipment financial reviews are currently unavailable.');
    }

    public function action(Request $request, ShipmentFinancialPlan $plan)
    {
        abort_unless(app(ShipmentLossAccess::class)->query($request->user())->whereKey($plan->id)->exists(), 404);

        return $this->perform($request, $plan, function () use ($request, $plan) {
            $data = $request->validate(['action' => ['required', 'string', 'max:60']]);
            $service = app(ShipmentLossActions::class);
            $spec = $service->definition($data['action'], $plan->snapshot['payment_method']);
            $request->validate(['proof' => [$spec['proof'] ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120']]);
            $event = $service->execute($request->user(), $plan, $data['action'], $request->except(['action', 'proof', '_token']), $request->file('proof'));

            return ['event' => $event->only(['id', 'reference', 'event_type', 'amount_cents'])];
        });
    }

    public function requestCredits(Request $request, ShipmentFinancialPlan $plan, WalletLossCreditService $credits)
    {
        abort_unless(app(ShipmentLossAccess::class)->query($request->user())->whereKey($plan->id)->exists(), 404);

        return $this->perform($request, $plan, function () use ($request, $plan, $credits) {
            $request->validate(array_fill_keys(array_diff(array_keys($request->all()), ['expected_version', 'request_token', 'reason', 'amount', '_token']), ['prohibited']));
            $saved = $credits->request($request->user(), $plan, $request->except('_token'));

            return ['request' => $saved->only(['id', 'reference', 'status', 'version'])];
        });
    }

    public function reviewCredits(Request $request, ShipmentFinancialPlan $plan, WalletRefundCreditRequest $funding, WalletLossCreditService $credits)
    {
        abort_unless(app(ShipmentLossAccess::class)->query($request->user())->whereKey($plan->id)->exists()
            && $funding->shipment_financial_plan_id === $plan->id && $funding->repayment_plan_id === null, 404);

        return $this->perform($request, $plan, function () use ($request, $funding, $credits) {
            $request->validate(array_fill_keys(array_diff(array_keys($request->all()), ['version', 'request_token', 'decision', 'reason', 'demo_credit_confirmed', '_token']), ['prohibited']));
            $saved = $credits->review($request->user(), $funding, $request->except('_token'));

            return ['request' => $saved->only(['id', 'reference', 'status', 'version'])];
        });
    }

    public function findingProof(Request $request, ShipmentFinancialPlan $plan)
    {
        return $this->serveLocalProof(
            $request,
            $plan,
            fn () => ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->firstOrFail(),
            'shipment-loss-findings',
            fn () => 'Found-parcel-evidence',
        );
    }

    public function dispositionProof(Request $request, ShipmentFinancialPlan $plan)
    {
        return $this->serveLocalProof(
            $request,
            $plan,
            fn () => ShipmentLossDisposition::where('shipment_financial_plan_id', $plan->id)->firstOrFail(),
            'shipment-loss-dispositions',
            fn (object $record) => 'Loss-evidence-'.$record->reference,
        );
    }

    private function perform(Request $request, ShipmentFinancialPlan $plan, Closure $operation)
    {
        try {
            $saved = $operation();
        } catch (ValidationException|HttpExceptionInterface $error) {
            return $this->rejected($request, $error);
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Shipment loss financial update could not be confirmed.', ['exception_type' => $error::class]);
            $message = 'This financial update could not be confirmed. Reload its saved history before retrying.';

            return $request->expectsJson() ? response()->json(['message' => $message], 503) : back()->withErrors(['review' => $message]);
        }

        return $request->expectsJson() ? response()->json($saved)->header('Cache-Control', 'private, no-store')
            : redirect('/shipment-finances/'.$plan->id)->with('success', 'The update was recorded. Check its actual funding and receipt status.');
    }

    private function order(LogisticsManifestEvent $observation): Order
    {
        $order = $observation->manifest?->direction === 'buyer_return'
            ? BuyerReturnManifestParcel::whereKey($observation->payload['return_member_id'] ?? 0)
                ->where('manifest_id', $observation->manifest_id)->first()?->parcel?->returnCase?->order
            : $observation->parcel?->delivery?->order;
        abort_unless($order, 409, 'Retain the original missing observation and its purchased shipment before reviewing a loss.');

        return $order;
    }

    public function reconciliationProof(Request $request, ShipmentFinancialPlan $plan, ShipmentLossReconciliation $reconciliation)
    {
        return $this->serveLocalProof(
            $request,
            $plan,
            fn () => $reconciliation,
            'shipment-loss-reconciliations',
            fn (object $record) => 'Loss-reconciliation-'.$record->reference,
            $reconciliation->shipment_financial_plan_id === $plan->id,
        );
    }

    private function serveLocalProof(Request $request, ShipmentFinancialPlan $plan, Closure $resolveRecord, string $directory, Closure $downloadBaseName, bool $belongsToPlan = true)
    {
        $this->admin($request);
        abort_unless($belongsToPlan && app(ShipmentLossAccess::class)->query($request->user())->whereKey($plan->id)->exists(), 404);
        $record = $resolveRecord();
        $disk = Storage::disk('local');
        $pattern = '/\A'.preg_quote($directory, '/').'\/[a-f0-9-]+\.(?:jpg|png|webp|pdf)\z/';
        abort_unless(preg_match($pattern, $record->proof_path)
            && $record->proof_bytes > 0 && $record->proof_bytes <= 5242880
            && $disk->exists($record->proof_path)
            && $disk->size($record->proof_path) === $record->proof_bytes
            && hash_equals($record->proof_hash, hash_file('sha256', $disk->path($record->proof_path))), 404,
            'The private loss evidence is unavailable. Its original record is retained.');

        return $disk->response($record->proof_path, $downloadBaseName($record).'.'.pathinfo($record->proof_path, PATHINFO_EXTENSION),
            ['Content-Type' => $record->proof_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'attachment');
    }

    private function rejected(Request $request, ValidationException|HttpExceptionInterface $error)
    {
        if ($request->expectsJson() || ($error instanceof HttpExceptionInterface && $error->getStatusCode() !== 409)) {
            throw $error;
        }

        // Private reasons, files and consent must never enter Laravel's old-input session cache.
        return back()->withErrors($error instanceof ValidationException ? $error->errors() : ['review' => $error->getMessage()]);
    }
}
