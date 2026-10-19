<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnCustodyService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletReturnMoneyService
{
    public function actions(User $actor, BuyerReturnCase $case): array
    {
        if (! $actor->isAdmin() || ! $actor->canAccessPortal() || ! $actor->email_verified_at) {
            return [];
        }
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();

        return ! $adjustment && in_array($case->status, ['seller_received', 'condition_review'], true) ? ['approve-repayment']
            : ($adjustment && $case->status === 'repayment_ready' ? ['refund-wallet'] : []);
    }

    private function assertReturnSource(BuyerReturnCase $case): void
    {
        $parcel = BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
        app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
        app(BuyerReturnCustodyService::class)->assertFinishedRoute($case, $parcel, afterSellerReceipt: true);
        $receipt = $case->events()->where('event_type', 'return-seller-receive')->latest('id')->first();
        abort_unless($parcel->status === 'seller_received' && $parcel->custody === ['kind' => 'seller', 'user_id' => $case->seller_id]
            && $receipt?->actor_id === $case->seller_id && $receipt->actor_role === 'seller'
            && $case->evidence()->where('buyer_return_event_id', $receipt->id)->where('purpose', 'seller-receive')->exists(), 409,
            'Record the actual seller receipt through the complete return route before approving a wallet refund.');
    }

    public function command(User $actor, BuyerReturnCase $case, string $action, array $input): BuyerReturnMoneyEvent
    {
        abort_unless(in_array($action, ['approve-repayment', 'refund-wallet'], true), 404);
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'], 'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'reason' => ['required', 'string', new ApplicationText('notes', 10, 1000)],
            'wallet_refund_confirmed' => $action === 'refund-wallet' ? ['accepted'] : ['prohibited'],
            'amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
            'proof' => ['prohibited'], 'cash_reference' => ['prohibited'], 'payment_confirmed' => ['prohibited'],
        ])->validate();
        $token = hash('sha256', $data['request_token']);
        $reason = trim($data['reason']);
        $hash = hash('sha256', json_encode([$case->id, $actor->id, $action, (int) $data['expected_version'], $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $case, $action, $data, $token, $reason, $hash) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $case->buyer_id, $case->seller_id])->orderBy('id')->lockForUpdate()->get();
            $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $case->load('order', 'items');
            $admin = app(WalletCreditService::class)->admin($actor);
            app(BuyerReturnService::class)->owned($admin, $case);
            abort_unless($order->payment_method === 'wallet' && $case->buyer_id === $order->buyer_id, 409);
            if ($prior = BuyerReturnMoneyEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $admin->id && hash_equals($prior->request_fingerprint, $hash), 409);

                return $prior;
            }
            abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists(), 409, 'This request already belongs to another return action.');
            abort_unless($case->version === (int) $data['expected_version'] && in_array($action, $this->actions($admin, $case), true), 409,
                'This return changed. Review its saved state before approving payment.');
            $this->assertReturnSource($case);
            abort_unless(app(WalletPaymentService::class)->paid($order), 409, 'The original wallet payment needs review.');
            $basis = app(BuyerReturnAmounts::class)->forCase($case);
            $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
            $transaction = null;
            if ($action === 'approve-repayment') {
                $adjustment = BuyerReturnAdjustment::create($basis + ['buyer_return_case_id' => $case->id, 'order_id' => $order->id,
                    'seller_payment_event_id' => null, 'recovery_due_cents' => 0, 'actor_id' => $admin->id, 'created_at' => now()]);
            } else {
                abort_unless($adjustment && $adjustment->buyer_cents === $basis['buyer_cents'] && $adjustment->seller_cents === $basis['seller_cents'], 409,
                    'The approved return amount differs from the original purchased units.');
                $transaction = app(WalletSettlementService::class)->refundReturn($order, $case, $basis, $admin, $reason, $data['request_token']);
            }
            $before = $case->status;
            $case->status = $action === 'approve-repayment' ? 'repayment_ready' : ($basis['buyer_cents'] === 0 ? 'closed_no_repayment' : 'repaid');
            $case->version++;
            $case->save();
            $event = BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()),
                'buyer_return_case_id' => $case->id, 'order_id' => $order->id, 'actor_id' => $admin->id, 'actor_role' => 'admin',
                'event_type' => $action, 'sequence' => $case->version, 'amount_cents' => $transaction?->amount_cents ?? 0,
                'recipient_id' => $case->buyer_id, 'reason' => $reason, 'request_token_hash' => $token, 'request_fingerprint' => $hash,
                'context' => ['adjustment_id' => $adjustment->id, 'wallet_transaction_id' => $transaction?->id,
                    'wallet_reference' => $transaction?->reference, 'demo_only' => true], 'created_at' => now()]);
            app(BuyerReturnService::class)->append($case, $admin, 'money-'.$action, $before, $reason, $token, $hash,
                ['money_event_id' => $event->id, 'wallet_reference' => $transaction?->reference, 'amount_cents' => $transaction?->amount_cents ?? 0]);

            return $event;
        }, 3);
    }

    public function presentation(User $actor, BuyerReturnCase $case): array
    {
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
        $events = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->orderBy('sequence')->get();

        return ['mode' => 'wallet', 'actions' => $this->actions($actor, $case),
            'adjustment' => $adjustment?->only(['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents', 'buyer_cents', 'seller_cents', 'platform_cents']),
            'history' => $events->map(fn ($event) => [...$event->only(['id', 'reference', 'event_type', 'amount_cents', 'reason', 'source_event_id', 'created_at']),
                'cash_reference' => null, 'proof_url' => null, 'wallet_reference' => $event->context['wallet_reference'] ?? null])->all()];
    }
}
