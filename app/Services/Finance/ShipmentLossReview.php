<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossDisposition;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ShipmentLossReview
{
    public function preview(User $actor, LogisticsManifestEvent $observation, array $input): array
    {
        $admin = app(WalletCreditService::class)->admin($actor);
        $data = Validator::make($input, ['seller_protection' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'investigation_reference' => ['required', new ApplicationText('notes', 3, 120)],
            'company_id' => ['prohibited'], 'buyer_id' => ['prohibited'], 'buyer_refund_cents' => ['prohibited'], 'amount_cents' => ['prohibited']])->validate();
        $snapshot = app(ShipmentLossSource::class)->snapshot($observation, CodMoney::cents($data['seller_protection']));
        abort_if(in_array($admin->id, $snapshot['source_actor_ids'], true), 403, 'An independent current admin must review the original company and loss evidence.');
        $snapshot['investigation_reference'] = trim($data['investigation_reference']);

        return ['snapshot' => $snapshot, 'review_token' => app(AccountRestrictionService::class)->token($snapshot)];
    }

    public function approve(User $actor, LogisticsManifestEvent $observation, array $input, UploadedFile $proof): ShipmentFinancialPlan
    {
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'], 'review_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'loss_confirmed' => ['required', 'accepted'],
            'seller_protection' => ['required', 'string', 'regex:'.CodMoney::RULE], 'investigation_reference' => ['required', new ApplicationText('notes', 3, 120)],
            'company_id' => ['prohibited'], 'buyer_id' => ['prohibited'], 'buyer_refund_cents' => ['prohibited'], 'amount_cents' => ['prohibited']])->validate();
        $data['reason'] = trim($data['reason']);
        $data['investigation_reference'] = trim($data['investigation_reference']);
        $token = hash('sha256', strtolower($data['request_token']));
        $files = app(ShipmentFinanceFiles::class);
        $info = $files->inspect($proof);
        $fingerprint = hash('sha256', json_encode([$observation->id, $actor->id, $data['review_token'], $data['seller_protection'],
            $data['investigation_reference'], $data['reason'], $info['proof_hash']], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $observation, $data, $files, $proof, $info, $token, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $admin = app(WalletCreditService::class)->admin($actor);
                if ($prior = ShipmentFinancialPlan::where('request_token_hash', $token)->first()) {
                    Order::whereKey($prior->order_id)->lockForUpdate()->firstOrFail();
                    abort_unless($prior->actor_id === $admin->id && $prior->loss_observation_id === $observation->id
                        && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                    return $prior;
                }
                $source = app(ShipmentLossSource::class)->context($observation);
                $order = Order::whereKey($source['order']->id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($order->delivery->id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', [$actor->id, $order->buyer_id, $order->items->first()->shop->user_id])->orderBy('id')->lockForUpdate()->get();
                WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
                LogisticsManifest::whereKey($source['manifest']->id)->lockForUpdate()->firstOrFail();
                if ($source['case']) {
                    BuyerReturnCase::whereKey($source['case']->id)->lockForUpdate()->firstOrFail();
                }
                app(ShipmentRefusalReview::class)->unused($token);
                $case = $source['case'];
                abort_if($case ? ShipmentFinancialPlan::where('active_return_case_id', $case->id)->exists()
                    : ShipmentFinancialPlan::where('active_order_id', $order->id)->exists(), 409, 'This original financial claim already has an active review.');
                $review = $this->preview($admin, $observation, $data);
                abort_unless(hash_equals($review['review_token'], $data['review_token']), 409, 'The original custody, payment or reviewed amounts changed. Review their current evidence.');
                $snapshot = $review['snapshot'];
                $path = $files->store($proof, $info);
                $paths[] = $path;
                $plan = ShipmentFinancialPlan::create(['order_id' => $order->id, 'undelivered_order_id' => $case ? null : $order->id,
                    'buyer_return_case_id' => $case?->id, 'delivery_id' => $snapshot['delivery_id'], 'buyer_id' => $snapshot['buyer_id'], 'seller_id' => $snapshot['seller_id'],
                    'logistics_company_id' => $snapshot['logistics_company_id'], 'actor_id' => $admin->id, 'kind' => 'transit_loss', 'loss_observation_id' => $observation->id,
                    'snapshot' => $snapshot, 'reason' => $data['reason'], 'proof_path' => $path, ...array_diff_key($info, ['extension' => true]),
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
                ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id, 'event_type' => 'approved', 'sequence' => 1,
                    'amount_cents' => 0, 'reason' => $data['reason'], 'context' => ['plan_reference' => $plan->reference, 'observation_reference' => $observation->reference,
                        'no_money_moved' => true, 'no_physical_receipt' => true], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);

                return $plan;
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }

    public function voidUnfunded(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentFinancialEvent
    {
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'unfunded_void_confirmed' => ['required', 'accepted']])->validate();
        $data['reason'] = trim($data['reason']);
        $token = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], $data['reason'], 'unfunded-void'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            $admin = app(WalletCreditService::class)->admin($actor);
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            abort_unless($plan->kind === 'transit_loss', 409);
            if ($prior = $plan->events()->where('request_token_hash', $token)->first()) {
                abort_unless($prior->actor_id === $admin->id && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            $version = (int) $plan->events()->max('sequence');
            abort_if(Schema::hasTable('shipment_loss_dispositions') && ShipmentLossDisposition::where('shipment_financial_plan_id', $plan->id)->exists(), 409,
                'The original operational loss disposition retains this authority. An unfunded void cannot erase its independently recorded outcome.');
            abort_unless($version === (int) $data['expected_version'] && ($plan->active_order_id || $plan->active_return_case_id)
                && ! WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->exists()
                && ! $plan->events()->where(fn ($events) => $events->where('amount_cents', '>', 0)->orWhereNotNull('wallet_transaction_id'))->exists()
                && ! WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)->where('status', 'pending')->exists(), 409,
                'Only an original loss review without money, offered transfers or pending credit requests can be voided. Retain and reconcile any actual funding first.');
            $event = ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id, 'event_type' => 'voided', 'sequence' => $version + 1,
                'amount_cents' => 0, 'reason' => $data['reason'], 'context' => ['plan_reference' => $plan->reference, 'no_money_moved' => true, 'active_claim_released' => true],
                'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            $plan->update(['active_order_id' => null, 'active_return_case_id' => null]);

            return $event;
        }, 3);
    }
}
