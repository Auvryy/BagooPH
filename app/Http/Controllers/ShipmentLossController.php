<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnManifestParcel;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\ShipmentFinancialPlan;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    private function order(LogisticsManifestEvent $observation): Order
    {
        $order = $observation->manifest?->direction === 'buyer_return'
            ? BuyerReturnManifestParcel::whereKey($observation->payload['return_member_id'] ?? 0)
                ->where('manifest_id', $observation->manifest_id)->first()?->parcel?->returnCase?->order
            : $observation->parcel?->delivery?->order;
        abort_unless($order, 409, 'Retain the original missing observation and its purchased shipment before reviewing a loss.');

        return $order;
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
