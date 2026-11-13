<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Returns\BuyerReturnService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ShipmentLossCustody
{
    public const HOLD = 'This parcel has an approved loss review. Record an actual found-parcel intake and reconcile it before ordinary handling.';

    public function authority(Delivery|BuyerReturnParcel $parcel): ?ShipmentFinancialPlan
    {
        if (! Schema::hasTable('shipment_financial_plans')) {
            return null;
        }
        $query = ShipmentFinancialPlan::where('kind', 'transit_loss');
        if ($parcel instanceof BuyerReturnParcel) {
            $query->where('buyer_return_case_id', $parcel->buyer_return_case_id)->where('active_return_case_id', $parcel->buyer_return_case_id);
        } else {
            $query->where('delivery_id', $parcel->id)->whereNull('buyer_return_case_id')->where('active_order_id', $parcel->order_id);
        }
        if ($plan = $query->first()) {
            return $plan;
        }
        // A later financial void does not release actual quarantined property.
        if (Schema::hasTable('shipment_loss_findings')) {
            return ShipmentFinancialPlan::where('kind', 'transit_loss')
                ->whereIn('id', ShipmentLossFinding::select('shipment_financial_plan_id'))
                ->when($parcel instanceof BuyerReturnParcel,
                    fn ($query) => $query->where('buyer_return_case_id', $parcel->buyer_return_case_id),
                    fn ($query) => $query->where('delivery_id', $parcel->id)->whereNull('buyer_return_case_id'))->first();
        }

        return null;
    }

    public function assertMovable(Delivery|BuyerReturnParcel $parcel): void
    {
        if ($this->authority($parcel)) {
            throw new DomainException(self::HOLD);
        }
    }

    public function intake(User $actor, ShipmentFinancialPlan $original, LogisticsHub $hub, array $input, UploadedFile $proof): ShipmentLossFinding
    {
        abort_unless(Schema::hasTable('shipment_loss_findings'), 409, 'Apply the additive found-parcel migration before recording actual quarantine custody.');
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'barcode' => ['required', 'string', 'max:100'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'quarantine_confirmed' => ['required', 'accepted'],
            'received_at' => ['prohibited'], 'arrival_confirmed' => ['prohibited'], 'amount' => ['prohibited'],
            'custody' => ['prohibited'], 'status' => ['prohibited'], 'route_position' => ['prohibited']])->validate();
        $info = app(ShipmentFinanceFiles::class)->inspect($proof);
        $token = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, $hub->id, (int) $data['expected_version'],
            $data['barcode'], $data['reason'], $info['proof_hash']], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $original, $hub, $data, $info, $proof, $token, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
                $delivery = Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
                $eligibility = app(LogisticsEligibilityService::class);
                $eligibility->lockNetwork($original->logistics_company_id, [$actor->id], [$hub->id]);
                $actor = User::findOrFail($actor->id);
                $hub = LogisticsHub::findOrFail($hub->id);
                abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $actor->email_verified_at
                    && $hub->logistics_company_id === $original->logistics_company_id && $eligibility->canScan($actor, $hub), 403);
                $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
                if ($prior = ShipmentLossFinding::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $actor->id
                        && hash_equals($prior->request_fingerprint, $fingerprint), 409, 'Retain the exact original found-parcel request.');

                    return $prior;
                }
                app(ShipmentRefusalReview::class)->unused($token);
                abort_unless($plan->kind === 'transit_loss' && $data['barcode'] === $plan->snapshot['source']['barcode'], 422,
                    'Scan this original parcel’s exact waybill. A purchase number or another return barcode cannot record its custody.');
                abort_if(ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->exists(), 409, 'This found parcel already has its retained quarantine intake.');
                abort_unless((int) $plan->events()->max('sequence') === (int) $data['expected_version'], 409, 'The original loss changed. Review its current history before intake.');
                $manifest = LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
                $case = $plan->buyer_return_case_id ? BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail() : null;
                $parcel = $case ? BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail() : $delivery;
                $case ? BuyerReturnManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail()
                    : LogisticsManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail();
                app(ShipmentLossSource::class)->assertApproved($plan);
                $path = $proof->storeAs('shipment-loss-findings', Str::uuid().'.'.$info['extension'], 'local');
                abort_unless($path && Storage::disk('local')->exists($path), 503, 'The private found-parcel evidence could not be saved.');
                $paths[] = $path;
                $reference = (string) Str::uuid();
                $custody = ['kind' => 'loss_quarantine', 'hub_id' => $hub->id, 'user_id' => $actor->id, 'finding_reference' => $reference];
                $finding = ShipmentLossFinding::create(['reference' => $reference, 'shipment_financial_plan_id' => $plan->id,
                    'manifest_id' => $manifest->id, 'manifest_parcel_id' => $case ? null : $plan->snapshot['source']['member_id'],
                    'buyer_return_manifest_parcel_id' => $case ? $plan->snapshot['source']['member_id'] : null,
                    'hub_id' => $hub->id, 'actor_id' => $actor->id, 'financial_version' => (int) $data['expected_version'],
                    'barcode_scanned' => $data['barcode'], 'source_state' => $plan->snapshot['source']['physical_state'], 'custody_after' => $custody,
                    'reason' => $data['reason'], 'proof_path' => $path, ...array_diff_key($info, ['extension' => true]),
                    'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
                if ($case) {
                    $before = $parcel->state();
                    $parcel->fill(['current_hub_id' => $hub->id, 'custody' => $custody, 'version' => $parcel->version + 1])->save();
                    $case->increment('version');
                    app(BuyerReturnService::class)->append($case, $actor, 'loss-found-quarantined', $case->status, $data['reason'], $token, $fingerprint,
                        ['finding_reference' => $reference, 'source_state' => $before, 'target_state' => $parcel->state(), 'no_route_advance' => true]);
                } else {
                    $before = DeliveryCheckpoint::state($delivery);
                    $custodyBefore = DeliveryCheckpoint::lastCustody($delivery);
                    $delivery->update(['current_hub_id' => $hub->id]);
                    DeliveryCheckpoint::record($delivery, 'loss_found_quarantine', notes: $data['reason'], actor: $actor, hub: $hub,
                        manifestNumber: $manifest->reference, barcodeScanned: $data['barcode'], evidence: ['source_state' => $before,
                            'target_state' => DeliveryCheckpoint::state($delivery), 'custody_before' => $custodyBefore, 'custody_after' => $custody]);
                }
                ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $actor->id,
                    'event_type' => 'loss-found-quarantined', 'sequence' => (int) $data['expected_version'] + 1, 'amount_cents' => 0,
                    'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                    'context' => ['finding_reference' => $reference, 'no_money_moved' => true, 'no_transport_arrival' => true,
                        'no_original_manifest_receipt' => true, 'no_route_advance' => true], 'created_at' => now()]);

                return $finding;
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
            if (! ShipmentLossFinding::where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
