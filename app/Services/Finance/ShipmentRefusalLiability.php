<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnMoneyEvent;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletPayment;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\BuyerAccessService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ShipmentRefusalLiability
{
    public function state(ShipmentFinancialPlan $plan): array
    {
        abort_unless($plan->kind === 'buyer_refusal', 409);
        $events = $plan->events()->get();
        $received = (int) $events->whereIn('event_type', ['receive-shipping-payment', 'pay-wallet-shipping'])->sum('amount_cents');
        $pending = $events->where('event_type', 'offer-shipping-payment')->filter(fn ($offer) => ! $events->contains('consumed_source_id', $offer->id));
        $due = $plan->snapshot['buyer_liability_cents'];
        abort_unless($received >= 0 && $received <= $due, 409, 'Review the retained actual shipping-liability receipts.');

        return ['assessed' => $events->whereIn('event_type', ['wallet-refund', 'zero-refund-assessed'])->isNotEmpty(),
            'due_cents' => $due, 'received_cents' => $received, 'remaining_cents' => $due - $received,
            'pending_offer_cents' => (int) $pending->sum('amount_cents'), 'version' => (int) $events->max('sequence')];
    }

    public function command(User $actor, ShipmentFinancialPlan $original, string $action, array $input, ?UploadedFile $proof = null): ShipmentFinancialEvent
    {
        abort_unless(in_array($action, ['offer-shipping-payment', 'receive-shipping-payment', 'pay-wallet-shipping'], true), 404);
        $offer = $action === 'offer-shipping-payment';
        $receive = $action === 'receive-shipping-payment';
        $virtual = $action === 'pay-wallet-shipping';
        foreach (['reason', 'cash_reference'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['cash_reference'])) {
            $input['cash_reference'] = strtoupper($input['cash_reference']);
        }
        $data = Validator::make([...$input, 'proof' => $proof], ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'amount' => [$offer || $virtual ? 'required' : 'prohibited', 'string', 'regex:'.CodMoney::RULE],
            'cash_reference' => [$offer ? 'required' : 'prohibited', new ApplicationText('notes', 3, 120)],
            'recipient_id' => $offer ? ['required', new AsciiPositiveInteger, 'integer'] : ['prohibited'],
            'source_event_id' => $receive ? ['required', new AsciiPositiveInteger, 'integer'] : ['prohibited'],
            'payment_confirmed' => ['required', 'accepted'], 'proof' => [$virtual ? 'prohibited' : 'required', 'nullable', 'file', 'max:5120'],
            'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'logistics_company_id' => ['prohibited']])->validate();
        unset($data['proof']);
        $files = app(ShipmentFinanceFiles::class);
        $info = $proof ? $files->inspect($proof) : null;
        $token = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, $action, (int) $data['expected_version'], $data['reason'],
            $data['amount'] ?? null, isset($data['recipient_id']) ? (int) $data['recipient_id'] : null,
            isset($data['source_event_id']) ? (int) $data['source_event_id'] : null, $data['cash_reference'] ?? null, $info['proof_hash'] ?? null], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $original, $action, $data, $offer, $receive, $virtual, $proof, $files, $info, $token, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', array_filter([$actor->id, $original->buyer_id, $data['recipient_id'] ?? null]))->orderBy('id')->lockForUpdate()->get();
                WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
                $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
                $actor = User::findOrFail($actor->id);
                abort_unless($actor->closed_at === null && $actor->email_verified_at && $plan->kind === 'buyer_refusal'
                    && $virtual === ($plan->snapshot['payment_method'] === 'wallet'), 403);
                if ($receive) {
                    app(WalletCreditService::class)->admin($actor);
                } else {
                    app(BuyerAccessService::class)->requireOrderHistory($actor);
                    abort_unless($actor->id === $plan->buyer_id, 403);
                }
                if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $actor->id
                        && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                    return $prior;
                }
                app(ShipmentRefusalReview::class)->unused($token);
                $state = $this->state($plan);
                abort_unless($state['assessed'] && $state['version'] === (int) $data['expected_version'], 409, 'Review the actual current liability and its received payments.');
                $source = null;
                $recipient = null;
                if ($receive) {
                    $source = $plan->events()->whereKey($data['source_event_id'])->where('event_type', 'offer-shipping-payment')->first();
                    abort_unless($source && $source->actor_id === $plan->buyer_id && $source->recipient_id === $actor->id
                        && $source->actor_id !== $actor->id && ! $plan->events()->where('consumed_source_id', $source->id)->exists(), 409,
                        'Only the named receiving admin can acknowledge this original buyer transfer once.');
                    $amount = $source->amount_cents;
                    $recipient = $actor->id;
                } else {
                    $amount = CodMoney::cents($data['amount']);
                    if ($offer) {
                        $recipient = app(WalletCreditService::class)->admin(User::findOrFail($data['recipient_id']))->id;
                        abort_if($recipient === $actor->id, 409);
                    }
                }
                abort_unless($amount > 0 && $amount <= $state['remaining_cents'] - ($offer ? $state['pending_offer_cents'] : 0), 409,
                    'The actual payment cannot exceed the remaining reviewed shipping liability.');
                abort_if($offer && (ShipmentFinancialEvent::where('cash_reference', $data['cash_reference'])->exists()
                    || BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists()), 409,
                    'This actual transfer reference already belongs to retained financial evidence.');
                $transaction = null;
                if ($virtual) {
                    $ledger = app(WalletLedger::class);
                    $account = $ledger->user($actor);
                    $reverse = $ledger->platform('reverse_shipping');
                    $transaction = $ledger->post('shipment-shipping:'.$plan->id.':'.strtolower($data['request_token']), 'wallet_refusal_shipping_payment', $amount,
                        [$account->id => -$amount, $reverse->id => $amount], $actor, $data['reason'],
                        ['shipment_financial_plan_reference' => $plan->reference, 'company_id' => $plan->logistics_company_id, 'demo_only' => true],
                        orderId: $order->id, token: strtolower($data['request_token']));
                }
                $outbound = $virtual || $offer ? 0 : min($amount, max(0, $plan->snapshot['buyer_outbound_shipping_cents'] - $state['received_cents']));
                $path = $proof ? $files->store($proof, $info) : null;
                if ($path) {
                    $paths[] = $path;
                }

                return ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                    'event_type' => $action, 'sequence' => $state['version'] + 1, 'amount_cents' => $amount, 'recipient_id' => $recipient,
                    'source_event_id' => $source?->id, 'consumed_source_id' => $source?->id, 'wallet_transaction_id' => $transaction?->id,
                    'cash_reference' => $data['cash_reference'] ?? null, 'proof_path' => $path,
                    ...($info ? array_diff_key($info, ['extension' => true]) : []), 'reason' => $data['reason'],
                    'context' => ['plan_reference' => $plan->reference, 'company_id' => $plan->logistics_company_id,
                        'wallet_reference' => $transaction?->reference, 'demo_only' => $virtual,
                        'actually_received_cents' => $offer ? 0 : $amount, 'outbound_received_cents' => $outbound,
                        'reverse_received_cents' => $offer ? 0 : $amount - $outbound],
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }
}
