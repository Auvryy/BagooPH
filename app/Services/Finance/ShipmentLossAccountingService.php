<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossAccounting;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\MotherBagService;
use App\Services\Returns\BuyerReturnBagService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Records an independently supported absence, never a parcel or vehicle receipt. */
class ShipmentLossAccountingService
{
    public function record(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentLossAccounting
    {
        abort_unless(Schema::hasTable('shipment_loss_accountings'), 409, 'Apply the additive loss-accounting migration before recording this outcome.');
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'loss_accounting_confirmed' => ['required', 'accepted'],
            'received' => ['prohibited'], 'arrival_confirmed' => ['prohibited'], 'manifest_id' => ['prohibited'],
            'received_at' => ['prohibited'], 'received_by_id' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'amount_cents' => ['prohibited'],
            'manifest_parcel_id' => ['prohibited'], 'buyer_return_manifest_parcel_id' => ['prohibited'],
            'amount' => ['prohibited'], 'custody' => ['prohibited'], 'status' => ['prohibited']])->validate();
        $token = hash('sha256', strtolower($data['request_token']));
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], $data['reason'], 'account-loss'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            $admin = app(WalletCreditService::class)->admin($actor);
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($prior = ShipmentLossAccounting::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $admin->id
                    && hash_equals($prior->request_fingerprint, $fingerprint), 409, 'Retain the exact original loss-accounting request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            abort_unless((int) $plan->events()->max('sequence') === (int) $data['expected_version'], 409, 'Review the current original loss before accounting for its absence.');
            abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'] ?? [], true), 403, 'An independent current admin must account for the original loss.');
            abort_if(ShipmentLossAccounting::where('shipment_financial_plan_id', $plan->id)->exists(), 409, 'This original loss already has its retained accounting.');
            $source = app(ShipmentLossSource::class);
            $disposition = $source->assertRetained($plan);
            abort_unless($disposition, 409, 'Record and retain the independent operational loss disposition first.');
            $source->assertApproved($plan);
            $relations = $this->relations($plan);
            LogisticsManifest::whereIn('id', array_keys($relations))->orderBy('id')->lockForUpdate()->get();
            if ($plan->buyer_return_case_id) {
                BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                BuyerReturnParcel::where('buyer_return_case_id', $plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                BuyerReturnManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail();
            } else {
                LogisticsManifestParcel::whereKey($plan->snapshot['source']['member_id'])->lockForUpdate()->firstOrFail();
            }
            $source->assertApproved($plan);
            abort_unless($relations === $this->relations($plan), 409, 'The original transport obligations changed before this accounting.');
            $record = ShipmentLossAccounting::create(['shipment_financial_plan_id' => $plan->id, 'shipment_loss_disposition_id' => $disposition->id,
                'actor_id' => $admin->id, 'financial_version' => (int) $data['expected_version'], 'reason' => $data['reason'],
                'request_token_hash' => $token, 'request_fingerprint' => $fingerprint, 'created_at' => now()]);
            foreach ($relations as $relation) {
                DB::table('shipment_loss_accounting_manifests')->insert(['shipment_loss_accounting_id' => $record->id, ...$relation]);
            }
            $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
            LogisticsManifestEvent::create(['manifest_id' => $manifest->id, 'actor_id' => $admin->id, 'hub_id' => $manifest->destination_hub_id,
                'manifest_parcel_id' => $plan->buyer_return_case_id ? null : $plan->snapshot['source']['member_id'],
                'event_type' => 'loss-accounted', 'barcode_scanned' => null, 'reason' => $data['reason'],
                'source_state' => $manifest->state(), 'target_state' => $manifest->state(),
                'payload' => ['accounting_reference' => $record->reference, 'disposition_reference' => $disposition->reference,
                    'loss_observation_id' => $plan->loss_observation_id, 'no_parcel_receipt' => true, 'no_transport_arrival' => true]]);
            $manifest->increment('version');
            ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id, 'event_type' => 'loss-accounted',
                'sequence' => (int) $data['expected_version'] + 1, 'amount_cents' => 0, 'reason' => $data['reason'], 'request_token_hash' => $token,
                'request_fingerprint' => $fingerprint, 'context' => ['accounting_reference' => $record->reference,
                    'disposition_reference' => $disposition->reference, 'no_money_moved' => true, 'no_parcel_receipt' => true,
                    'no_transport_arrival' => true, 'custody_unchanged' => true], 'created_at' => now()]);

            foreach (array_keys($relations) as $id) {
                $related = LogisticsManifest::findOrFail($id);
                app(MotherBagService::class)->refreshLossAccounting($related);
                app(BuyerReturnBagService::class)->refreshLossAccounting($related, $admin);
            }

            return $record;
        }, 3);
    }

    /** Each numeric member remains in its own table, including earlier recovery obligations. */
    private function relations(ShipmentFinancialPlan $plan): array
    {
        $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
        $return = $plan->buyer_return_case_id !== null;
        $map = [$manifest->id => ['manifest_id' => $manifest->id, 'relation_kind' => 'source',
            'manifest_parcel_id' => $return ? null : $plan->snapshot['source']['member_id'],
            'buyer_return_manifest_parcel_id' => $return ? $plan->snapshot['source']['member_id'] : null]];
        $recovery = $return ? $manifest->buyerReturnRecoveryRoute?->recovery : $manifest->recoveryCase;
        if ($recovery) {
            $original = $recovery->originalMember;
            abort_unless($original && $original->included && ! $original->received_at
                && $original->manifest->logistics_company_id === $plan->logistics_company_id
                && ($return ? $recovery->buyer_return_case_id === $plan->buyer_return_case_id
                    && $original->buyer_return_parcel_id === $recovery->buyer_return_parcel_id && $original->active_return_parcel_id === null
                    : $recovery->delivery_id === $plan->delivery_id && $original->delivery_id === $plan->delivery_id && $original->active_delivery_id === null)
                && $recovery->state === 'in_transit' && $recovery->events()->where('event_type', 'intake')->exists(), 409,
                'Retain this original recovery intake and its still-unreceived earlier typed member.');
            $map[$original->manifest_id] = ['manifest_id' => $original->manifest_id, 'relation_kind' => 'original',
                'manifest_parcel_id' => $return ? null : $original->id, 'buyer_return_manifest_parcel_id' => $return ? $original->id : null];
            $observation = LogisticsManifestEvent::find($recovery->observation_event_id);
            abort_unless($observation && $observation->event_type === 'report-discrepancy'
                && $observation->manifest->logistics_company_id === $plan->logistics_company_id, 409, 'Retain the original recovery observation.');
            $map[$observation->manifest_id] ??= ['manifest_id' => $observation->manifest_id, 'relation_kind' => 'observed',
                'manifest_parcel_id' => null, 'buyer_return_manifest_parcel_id' => null];
        }
        ksort($map);

        return $map;
    }

    public function verified(ShipmentLossAccounting $record): bool
    {
        try {
            $plan = ShipmentFinancialPlan::findOrFail($record->shipment_financial_plan_id);
            $disposition = app(ShipmentLossSource::class)->assertRetained($plan);
            $source = $plan->snapshot['source'];
            $manifest = LogisticsManifest::findOrFail($source['manifest_id']);
            $return = $plan->buyer_return_case_id !== null;
            $member = $return ? BuyerReturnManifestParcel::find($source['member_id']) : LogisticsManifestParcel::find($source['member_id']);
            if (! $disposition || $disposition->id !== $record->shipment_loss_disposition_id
                || in_array($record->actor_id, $plan->snapshot['source_actor_ids'], true)
                || User::whereKey($record->actor_id)->value('role') !== 'admin'
                || ! $member || $member->manifest_id !== $manifest->id || ! $member->included || $member->received_at
                || ($return ? $member->parcel->buyer_return_case_id !== $plan->buyer_return_case_id || $member->active_return_parcel_id !== $member->buyer_return_parcel_id
                    : $member->delivery_id !== $plan->delivery_id || $member->active_delivery_id !== $plan->delivery_id)
                || ! $this->physicalMatches($plan)
                || ! $plan->events()->where('event_type', 'loss-accounted')->where('actor_id', $record->actor_id)
                    ->where('sequence', $record->financial_version + 1)->where('amount_cents', 0)->whereNull('wallet_transaction_id')
                    ->where('request_token_hash', $record->request_token_hash)->where('request_fingerprint', $record->request_fingerprint)
                    ->where('context->accounting_reference', $record->reference)->where('context->disposition_reference', $disposition->reference)->exists()
                || ! $manifest->events()->where('event_type', 'loss-accounted')->where('actor_id', $record->actor_id)
                    ->where('hub_id', $manifest->destination_hub_id)->whereNull('barcode_scanned')
                    ->where('payload->accounting_reference', $record->reference)->where('payload->disposition_reference', $disposition->reference)
                    ->where('payload->loss_observation_id', $plan->loss_observation_id)->exists()) {
                return false;
            }
            $actual = DB::table('shipment_loss_accounting_manifests')->where('shipment_loss_accounting_id', $record->id)->orderBy('manifest_id')->get()
                ->mapWithKeys(fn ($row) => [(int) $row->manifest_id => ['manifest_id' => (int) $row->manifest_id, 'relation_kind' => $row->relation_kind,
                    'manifest_parcel_id' => $row->manifest_parcel_id === null ? null : (int) $row->manifest_parcel_id,
                    'buyer_return_manifest_parcel_id' => $row->buyer_return_manifest_parcel_id === null ? null : (int) $row->buyer_return_manifest_parcel_id]])->all();

            return $actual === $this->relations($plan);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }

            return false;
        }
    }

    private function physicalMatches(ShipmentFinancialPlan $plan): bool
    {
        $expected = $plan->snapshot['source']['physical_state'];
        $return = $plan->buyer_return_case_id !== null;
        $parcel = $return ? BuyerReturnParcel::where('buyer_return_case_id', $plan->buyer_return_case_id)->firstOrFail() : Delivery::findOrFail($plan->delivery_id);
        $actual = $return ? $parcel->state() : DeliveryCheckpoint::state($parcel) + ['custody' => DeliveryCheckpoint::lastCustody($parcel),
            'checkpoint_id' => $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->max('id')];
        if ($actual === $expected) {
            return true;
        }
        $finding = app(ShipmentLossSource::class)->finding($plan);
        if (! $finding || $finding->source_state !== $expected || ! Storage::disk('local')->exists($finding->proof_path)
            || Storage::disk('local')->size($finding->proof_path) !== $finding->proof_bytes
            || ! hash_equals($finding->proof_hash, hash_file('sha256', Storage::disk('local')->path($finding->proof_path)))
            || ! $plan->events()->where('event_type', 'loss-found-quarantined')->where('actor_id', $finding->actor_id)
                ->where('sequence', $finding->financial_version + 1)->where('request_token_hash', $finding->request_token_hash)
                ->where('request_fingerprint', $finding->request_fingerprint)->where('context->finding_reference', $finding->reference)->where('amount_cents', 0)->exists()) {
            return false;
        }
        if ($return) {
            return $actual === [...$expected, 'current_hub_id' => $finding->hub_id, 'custody' => $finding->custody_after, 'version' => $expected['version'] + 1];
        }
        $checkpoint = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
        unset($actual['custody'], $actual['checkpoint_id'], $expected['custody'], $expected['checkpoint_id']);

        return $actual === [...$expected, 'current_hub_id' => $finding->hub_id] && $checkpoint->checkpoint_type === 'loss_found_quarantine'
            && $checkpoint->scanned_by_id === $finding->actor_id && $checkpoint->custody_after === $finding->custody_after;
    }

    private function records(LogisticsManifest $manifest): Collection
    {
        return Schema::hasTable('shipment_loss_accountings') ? ShipmentLossAccounting::whereIn('id',
            DB::table('shipment_loss_accounting_manifests')->where('manifest_id', $manifest->id)->select('shipment_loss_accounting_id'))
            ->get()->filter(fn ($record) => $this->verified($record))->values() : collect();
    }

    public function members(LogisticsManifest $manifest): Collection
    {
        $return = $manifest->direction === 'buyer_return';
        $field = $return ? 'buyer_return_manifest_parcel_id' : 'manifest_parcel_id';
        $result = collect();
        foreach ($this->records($manifest) as $record) {
            $relation = DB::table('shipment_loss_accounting_manifests')->where('shipment_loss_accounting_id', $record->id)->where('manifest_id', $manifest->id)->first();
            if ($relation && $relation->{$field}) {
                $member = $return ? BuyerReturnManifestParcel::find($relation->{$field}) : LogisticsManifestParcel::find($relation->{$field});
                if ($member?->included && ! $member->received_at) {
                    $result->put($member->id, $record);
                }
            }
        }

        return $result;
    }

    public function observations(LogisticsManifest $manifest): Collection
    {
        $members = $this->members($manifest);
        $records = $this->records($manifest);
        $references = $records->map(fn ($record) => ShipmentFinancialPlan::findOrFail($record->shipment_financial_plan_id))->flatMap(function ($plan) {
            $source = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
            $recovery = $plan->buyer_return_case_id ? $source->buyerReturnRecoveryRoute?->recovery : $source->recoveryCase;

            return [$plan->loss_observation_id, $recovery?->observation_event_id];
        })->filter();

        return $manifest->events()->where('event_type', 'report-discrepancy')->get()->filter(fn ($event) => $references->contains($event->id)
            || ($event->payload['kind'] ?? null) === 'missing' && $members->has($manifest->direction === 'buyer_return'
                ? ($event->payload['return_member_id'] ?? null) : $event->manifest_parcel_id))->pluck('id');
    }

    public function sourceClosed(ShipmentFinancialPlan $plan): bool
    {
        if (! Schema::hasTable('shipment_loss_accountings')) {
            return false;
        }
        $record = ShipmentLossAccounting::where('shipment_financial_plan_id', $plan->id)->first();

        return $record && $this->verified($record) && LogisticsManifestEvent::where('manifest_id', $plan->snapshot['source']['manifest_id'])
            ->where('event_type', 'close-loss-accounting')->whereJsonContains('payload->accounting_references', $record->reference)->exists();
    }
}
