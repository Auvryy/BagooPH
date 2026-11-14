<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBagParcel;
use App\Models\MotherBagReturnParcel;
use App\Models\Order;
use App\Models\ParcelRecoveryCase;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Logistics\WaybillScanInputService;
use App\Services\Returns\BuyerReturnService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShipmentLossCustody
{
    public const HOLD = 'This parcel has an approved loss review. Record an actual found-parcel intake and reconcile it before ordinary handling.';

    public const SALVAGE_HOLD = 'This salvaged parcel was already financially settled and released to salvage custody; ordinary delivery and return routing is closed.';

    public function releasedReconciliation(Delivery|BuyerReturnParcel $parcel): ?ShipmentLossReconciliation
    {
        return app(ShipmentLossReconciliationService::class)->activeForParcel($parcel);
    }

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
        $plan = $query->first();
        if (! $plan && Schema::hasTable('shipment_loss_findings')) {
            // A later financial void does not release actual quarantined property.
            $plan = ShipmentFinancialPlan::where('kind', 'transit_loss')
                ->whereIn('id', ShipmentLossFinding::select('shipment_financial_plan_id'))
                ->when($parcel instanceof BuyerReturnParcel,
                    fn ($query) => $query->where('buyer_return_case_id', $parcel->buyer_return_case_id),
                    fn ($query) => $query->where('delivery_id', $parcel->id)->whereNull('buyer_return_case_id'))->first();
        }
        if ($plan && Schema::hasTable('shipment_loss_reconciliations')) {
            $released = ShipmentLossReconciliation::where('active_plan_id', $plan->id)
                ->where('status', 'released')
                ->first();
            if ($released && in_array($released->outcome_path, ['unpaid_undelivered', 'post_delivery_partial_return', 'already_settled_seller_return'], true)) {
                return null;
            }
        }

        return $plan;
    }

    public function assertMovable(Delivery|BuyerReturnParcel $parcel): void
    {
        if ($plan = $this->authority($parcel)) {
            $released = $this->releasedReconciliation($parcel);
            if ($released && $released->outcome_path === 'already_settled_salvage') {
                throw new DomainException(self::SALVAGE_HOLD);
            }

            throw new DomainException(self::HOLD);
        }
    }

    public function release(User $actor, ShipmentFinancialPlan $original, LogisticsHub $hub, array $input): ShipmentLossReconciliation
    {
        $reconciliations = app(ShipmentLossReconciliationService::class);
        abort_unless($reconciliations->available(), 409, 'Apply the additive reconciliation migration before releasing quarantine.');
        $inputs = app(WaybillScanInputService::class);
        $allowed = ['expected_version', 'reconciliation_reference', 'request_token', 'barcode', 'reason', 'release_confirmed', 'hub_id'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        $normalized = $inputs->normalize($input);
        if (is_string($normalized['reason'] ?? null)) {
            $normalized['reason'] = trim($normalized['reason']);
        }
        if (is_string($normalized['reconciliation_reference'] ?? null)) {
            $normalized['reconciliation_reference'] = strtolower(trim($normalized['reconciliation_reference']));
        }
        if (is_string($normalized['request_token'] ?? null)) {
            $normalized['request_token'] = strtolower(trim($normalized['request_token']));
        }
        $data = Validator::make($normalized, $rules + [
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'reconciliation_reference' => ['required', 'uuid'],
            'request_token' => ['required', 'uuid'],
            'barcode' => $inputs->barcodeRules(true),
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'release_confirmed' => ['required', 'accepted'],
            'hub_id' => ['nullable', new AsciiPositiveInteger, 'integer'],
        ])->validate();
        if (isset($data['hub_id'])) {
            abort_unless((int) $data['hub_id'] === $hub->id, 409, 'Select the current holding facility to release quarantine.');
        }
        $data['expected_version'] = (int) $data['expected_version'];
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode([
            $original->id,
            $actor->id,
            $hub->id,
            'release-quarantine',
            $data['reconciliation_reference'],
            $data['expected_version'],
            $data['barcode'],
            $data['reason'],
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $hub, $data, $token, $fingerprint, $reconciliations) {
            app(AccountRestrictionService::class)->lockGuard();
            Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', array_unique([$actor->id, $original->buyer_id, $original->seller_id]))->orderBy('id')->lockForUpdate()->get();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
            $lockedHub = LogisticsHub::whereKey($hub->id)->lockForUpdate()->firstOrFail();
            LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
            $case = $plan->buyer_return_case_id
                ? BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail()
                : null;
            $returnParcel = $case
                ? BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail()
                : null;
            $delivery = Delivery::with('order')->findOrFail($plan->delivery_id);
            $order = $delivery->order;

            if ($prior = ShipmentLossReconciliation::where('release_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id
                    && $prior->released_by_id === $actor->id
                    && hash_equals($prior->release_fingerprint, $fingerprint), 409,
                    'Retain the exact original quarantine release request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);

            $operator = User::findOrFail($actor->id);
            $eligibility = app(LogisticsEligibilityService::class);
            abort_unless($operator->isLogistics() && $operator->canAccessPortal() && $operator->email_verified_at && $operator->closed_at === null, 403,
                'Only active hub operators may release physical quarantine.');
            abort_unless(LogisticsCompany::eligible()->whereKey($plan->logistics_company_id)->exists(), 409,
                'The logistics company must remain active and eligible before releasing quarantine.');
            abort_unless(LogisticsHub::eligible()->whereKey($lockedHub->id)->where('logistics_company_id', $plan->logistics_company_id)->exists()
                && $eligibility->canScan($operator, $lockedHub), 403,
                'Only an eligible hub handler at this facility may release physical quarantine.');

            $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->lockForUpdate()->first();
            abort_unless($finding !== null, 409, 'Record an actual found-parcel quarantine intake first.');
            abort_unless($lockedHub->id === $finding->hub_id, 409, 'Release quarantine only from the facility holding the found parcel.');
            if ($data['barcode'] !== $finding->barcode_scanned) {
                throw ValidationException::withMessages([
                    'barcode' => 'Scan the exact waybill barcode held under quarantine intake.',
                ]);
            }

            $reconciliation = ShipmentLossReconciliation::where('active_plan_id', $plan->id)->lockForUpdate()->first();
            abort_unless($reconciliation !== null && $reconciliation->status === 'approved', 409,
                'An active approved found-parcel reconciliation is required before releasing quarantine.');
            abort_unless($reconciliation->reference === $data['reconciliation_reference'], 409,
                'The active approved reconciliation reference changed. Refresh before releasing custody.');

            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === $data['expected_version'], 409, 'Financial history changed. Refresh the quarantine screen before releasing.');

            $liveFingerprint = $reconciliations->sourceFingerprint($plan);
            abort_unless(hash_equals($reconciliation->source_fingerprint, $liveFingerprint), 409,
                'Source financial, order, or facility facts changed after approval. Invalidate the stale reconciliation and re-approve before releasing custody.');

            $assessment = $reconciliations->assessment($plan);
            abort_unless(in_array($reconciliation->outcome_path, $assessment['eligible_paths'], true), 409,
                $assessment['blocked_reasons'][$reconciliation->outcome_path] ?? 'This reconciliation path is no longer eligible.');

            $expectedRoute = $reconciliations->routePlan($plan, $finding, $reconciliation->outcome_path);
            abort_unless($expectedRoute['forward_route_hub_ids'] === $reconciliation->forward_route_hub_ids
                && $expectedRoute['return_route_hub_ids'] === $reconciliation->return_route_hub_ids, 409,
                'The approved transit corridor changed. Invalidate and re-approve reconciliation before releasing custody.');

            $path = $reconciliation->outcome_path;
            $sourceState = DeliveryCheckpoint::state($delivery);
            $custodyBefore = DeliveryCheckpoint::lastCustody($delivery);

            $custodyAfter = match ($path) {
                'unpaid_undelivered' => [
                    'kind' => 'hub',
                    'hub_id' => $lockedHub->id,
                    'reconciliation_reference' => $reconciliation->reference,
                    'outcome_path' => $path,
                    'forward_route_hub_ids' => $reconciliation->forward_route_hub_ids,
                    'at_destination_hub' => $lockedHub->id === $delivery->destination_bayan_hub_id,
                ],
                'already_settled_seller_return' => [
                    'kind' => 'hub',
                    'hub_id' => $lockedHub->id,
                    'reconciliation_reference' => $reconciliation->reference,
                    'outcome_path' => $path,
                    'return_route_hub_ids' => $reconciliation->return_route_hub_ids,
                    'at_origin_hub' => $lockedHub->id === $delivery->origin_bayan_hub_id,
                ],
                'post_delivery_partial_return' => [
                    'kind' => 'hub',
                    'hub_id' => $lockedHub->id,
                    'reconciliation_reference' => $reconciliation->reference,
                    'outcome_path' => $path,
                    'return_route_hub_ids' => $reconciliation->return_route_hub_ids,
                    'at_final_hub' => $lockedHub->id === ($returnParcel->hub_ids[count($returnParcel->hub_ids) - 1] ?? 0),
                ],
                'already_settled_salvage' => [
                    'kind' => 'salvage',
                    'hub_id' => $lockedHub->id,
                    'reconciliation_reference' => $reconciliation->reference,
                    'outcome_path' => $path,
                    'is_return' => $case !== null,
                ],
            };

            // Mark the reconciliation released BEFORE clearing active parcel locks on source bags (trigger requirement).
            $reconciliation->update([
                'status' => 'released',
                'active_plan_id' => $plan->id,
                'released_at' => now(),
                'released_by_id' => $operator->id,
                'release_barcode_scanned' => $data['barcode'],
                'release_reason' => $data['reason'],
                'release_custody_after' => $custodyAfter,
                'release_token_hash' => $token,
                'release_fingerprint' => $fingerprint,
            ]);

            if ($returnParcel && $case) {
                BuyerReturnManifestParcel::where('active_return_parcel_id', $returnParcel->id)->update(['active_return_parcel_id' => null]);
                MotherBagReturnParcel::where('active_return_parcel_id', $returnParcel->id)->update(['active_return_parcel_id' => null]);

                $oldCaseStatus = $case->status;
                if ($path === 'post_delivery_partial_return') {
                    $finalHubId = $returnParcel->hub_ids[count($returnParcel->hub_ids) - 1] ?? 0;
                    $atFinal = count($reconciliation->return_route_hub_ids ?? []) === 1 && $lockedHub->id === $finalHubId;
                    $returnParcel->fill([
                        'status' => 'at_hub',
                        'current_hub_id' => $lockedHub->id,
                        'route_position' => $atFinal ? count($returnParcel->hub_ids) - 1 : $returnParcel->route_position,
                        'custody' => $custodyAfter,
                        'version' => $returnParcel->version + 1,
                    ])->save();
                    if ($atFinal) {
                        $case->status = 'at_origin_hub';
                    }
                    $case->version += 1;
                    $case->save();
                    app(BuyerReturnService::class)->append(
                        $case->fresh(),
                        $operator,
                        'loss-quarantine-released',
                        $oldCaseStatus,
                        $data['reason'],
                        hash('sha256', 'loss-release:'.$data['request_token']),
                        $fingerprint,
                        [
                            'reconciliation_reference' => $reconciliation->reference,
                            'outcome_path' => $path,
                            'hub_id' => $lockedHub->id,
                            'return_route_hub_ids' => $reconciliation->return_route_hub_ids,
                        ]
                    );
                } else {
                    // already_settled_salvage on a paid return: retain buyer_return_unit_claims!
                    $returnParcel->fill([
                        'status' => 'salvaged',
                        'current_hub_id' => $lockedHub->id,
                        'custody' => $custodyAfter,
                        'version' => $returnParcel->version + 1,
                    ])->save();
                    $case->status = 'salvaged';
                    $case->version += 1;
                    $case->save();
                    app(BuyerReturnService::class)->append(
                        $case,
                        $operator,
                        'loss-salvage-released',
                        $oldCaseStatus,
                        $data['reason'],
                        hash('sha256', 'loss-release:'.$data['request_token']),
                        $fingerprint,
                        [
                            'reconciliation_reference' => $reconciliation->reference,
                            'outcome_path' => $path,
                            'hub_id' => $lockedHub->id,
                        ]
                    );
                }
            } else {
                LogisticsManifestParcel::where('active_delivery_id', $delivery->id)->update(['active_delivery_id' => null]);
                MotherBagParcel::where('active_delivery_id', $delivery->id)->update(['active_delivery_id' => null]);
                if (Schema::hasTable('parcel_recovery_cases')) {
                    ParcelRecoveryCase::where('active_delivery_id', $delivery->id)->update(['active_delivery_id' => null]);
                }

                $checkpointType = match ($path) {
                    'unpaid_undelivered' => 'loss_quarantine_released',
                    'already_settled_seller_return' => 'loss_seller_return_released',
                    'already_settled_salvage' => 'loss_salvage_released',
                };

                if ($path === 'unpaid_undelivered') {
                    $atDest = count($reconciliation->forward_route_hub_ids ?? []) === 1 && $lockedHub->id === $delivery->destination_bayan_hub_id;
                    $delivery->status = $atDest
                        ? OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB
                        : ($lockedHub->isMotherHub()
                            ? OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB
                            : OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB);
                    $delivery->current_hub_id = $lockedHub->id;
                    $order->status = 'at_sorting_center';
                } elseif ($path === 'already_settled_seller_return') {
                    $delivery->status = OrderStateMachineService::STATUS_RETURN_TO_SENDER;
                    $delivery->current_hub_id = $lockedHub->id;
                    $order->status = 'cancelled';
                } else {
                    // already_settled_salvage
                    $delivery->status = 'cancelled';
                    $delivery->current_hub_id = $lockedHub->id;
                    $order->status = 'cancelled';
                }

                $delivery->save();
                $order->save();

                DeliveryCheckpoint::record(
                    delivery: $delivery,
                    type: $checkpointType,
                    location: $lockedHub->name,
                    notes: $data['reason'],
                    actor: $operator,
                    hub: $lockedHub,
                    barcodeScanned: $data['barcode'],
                    evidence: [
                        'source_state' => $sourceState,
                        'target_state' => DeliveryCheckpoint::state($delivery),
                        'custody_before' => $custodyBefore,
                        'custody_after' => $custodyAfter,
                    ],
                    scanProvenance: 'loss_reconciliation_release',
                );
            }

            ShipmentFinancialEvent::create([
                'shipment_financial_plan_id' => $plan->id,
                'actor_id' => $operator->id,
                'event_type' => 'loss-quarantine-released',
                'sequence' => $version + 1,
                'amount_cents' => 0,
                'reason' => $data['reason'],
                'request_token_hash' => $token,
                'request_fingerprint' => $fingerprint,
                'context' => [
                    'reconciliation_reference' => $reconciliation->reference,
                    'outcome_path' => $reconciliation->outcome_path,
                    'finding_reference' => $finding->reference,
                    'hub_id' => $lockedHub->id,
                    'barcode_scanned' => $data['barcode'],
                    'custody_after' => $custodyAfter,
                ],
                'created_at' => now(),
            ]);

            return $reconciliation->fresh();
        }, 3);
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
