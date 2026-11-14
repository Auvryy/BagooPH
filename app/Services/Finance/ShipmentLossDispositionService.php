<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnManifestParcel;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossDisposition;
use App\Models\User;
use App\Models\WalletPayment;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ShipmentLossDispositionService
{
    public function record(User $actor, ShipmentFinancialPlan $original, array $input, UploadedFile $proof): ShipmentLossDisposition
    {
        abort_unless(Schema::hasTable('shipment_loss_dispositions'), 409, 'Apply the additive loss-disposition migration before recording this evidence.');
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'disposition_confirmed' => ['required', 'accepted'], 'received' => ['prohibited'], 'arrival_confirmed' => ['prohibited'],
            'amount' => ['prohibited'], 'amount_cents' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
            'manifest_id' => ['prohibited'], 'manifest_parcel_id' => ['prohibited'], 'buyer_return_manifest_parcel_id' => ['prohibited'],
            'status' => ['prohibited'], 'custody' => ['prohibited']])->validate();
        $token = hash('sha256', strtolower($data['request_token']));
        $info = app(ShipmentFinanceFiles::class)->inspect($proof);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], $data['reason'], $info['proof_hash']], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $original, $data, $proof, $token, $info, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $plan = ShipmentFinancialPlan::findOrFail($original->id);
                Order::whereKey($plan->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($plan->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', [$actor->id, $plan->buyer_id, $plan->seller_id])->orderBy('id')->lockForUpdate()->get();
                $admin = app(WalletCreditService::class)->admin($actor);
                $plan = ShipmentFinancialPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                abort_unless($plan->kind === 'transit_loss', 409, 'Use the original independently reviewed loss authority.');
                if ($prior = ShipmentLossDisposition::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $admin->id
                        && hash_equals($prior->request_fingerprint, $fingerprint), 409, 'Retain the exact original loss-disposition request.');

                    return $prior;
                }
                app(ShipmentRefusalReview::class)->unused($token);
                abort_if(ShipmentLossDisposition::where('shipment_financial_plan_id', $plan->id)->exists(), 409,
                    'This original loss already has its retained operational disposition.');
                $version = (int) $plan->events()->max('sequence');
                abort_unless($version === (int) $data['expected_version'], 409, 'Review the current original loss history before recording its disposition.');
                abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'], true), 403,
                    'An independent current admin must record the loss disposition.');
                WalletPayment::where('order_id', $plan->order_id)->lockForUpdate()->first();
                LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
                LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
                if ($plan->buyer_return_case_id) {
                    $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                    $case->parcel()->lockForUpdate()->firstOrFail();
                    BuyerReturnManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail();
                } else {
                    LogisticsManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail();
                }
                app(ShipmentLossSource::class)->assertApproved($plan);
                $path = $proof->storeAs('shipment-loss-dispositions', Str::uuid().'.'.$info['extension'], 'local');
                abort_unless($path && Storage::disk('local')->exists($path), 503, 'The private loss-disposition evidence could not be saved.');
                $paths[] = $path;
                $disposition = ShipmentLossDisposition::create(['shipment_financial_plan_id' => $plan->id,
                    'order_id' => $plan->order_id, 'delivery_id' => $plan->delivery_id, 'buyer_return_case_id' => $plan->buyer_return_case_id,
                    'logistics_company_id' => $plan->logistics_company_id, 'manifest_id' => $plan->snapshot['source']['manifest_id'],
                    'loss_observation_id' => $plan->loss_observation_id,
                    'manifest_parcel_id' => $plan->buyer_return_case_id ? null : $plan->snapshot['source']['member_id'],
                    'buyer_return_manifest_parcel_id' => $plan->buyer_return_case_id ? $plan->snapshot['source']['member_id'] : null,
                    'actor_id' => $admin->id, 'financial_version' => $version, 'state' => 'confirmed_lost', 'financial_snapshot' => $plan->snapshot,
                    'physical_snapshot' => $plan->snapshot['source']['physical_state'], 'reason' => $data['reason'], 'proof_path' => $path,
                    ...array_diff_key($info, ['extension' => true]), 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
                ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id,
                    'event_type' => 'loss-disposition-recorded', 'sequence' => $version + 1, 'amount_cents' => 0, 'reason' => $data['reason'],
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'context' => ['plan_reference' => $plan->reference,
                        'disposition_reference' => $disposition->reference, 'observation_reference' => $plan->snapshot['source']['observation_reference'],
                        'no_money_moved' => true, 'no_physical_receipt' => true, 'no_transport_arrival' => true, 'custody_unchanged' => true], 'created_at' => now()]);

                return $disposition;
            }, 3);
            $this->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $this->discardUnused($paths);
            throw $error;
        }
    }

    private function discardUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! ShipmentLossDisposition::where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
