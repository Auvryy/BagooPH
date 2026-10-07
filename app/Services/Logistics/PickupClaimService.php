<?php

namespace App\Services\Logistics;

use App\Models\CodCustodyEntry;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\PickupClaimEvent;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Notifications\LifecycleNoticeService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PickupClaimService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility, private readonly LifecycleNoticeService $notices) {}

    public function stage(Delivery $delivery, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $hub, $barcode, $expectedStatus) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $this->handler($parcel, $actor, $hub);
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($barcode, $parcel, allowOrderNumber: true);
            $existing = PickupClaim::where('delivery_id', $parcel->id)->first();
            if ($existing) {
                if ($existing->prepared_by_id !== $actor->id || $existing->hub_id !== $hub->id) {
                    throw new DomainException('Another handler already recorded this pickup staging.');
                }

                return $parcel;
            }
            if (! is_string($hub->fresh()->operating_hours) || trim($hub->fresh()->operating_hours) === '') {
                throw new DomainException('The company administrator must record actual pickup hours before staging a parcel.');
            }
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $scan = LogisticsManifestEvent::with(['manifest', 'parcel'])->where('reference', $custody['manifest_scan_reference'] ?? '')->first();
            if ($expectedStatus !== 'arrived_at_destination_hub' || $parcel->status !== $expectedStatus
                || $parcel->order->status !== 'at_sorting_center' || $parcel->current_hub_id !== $hub->id
                || ($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $hub->id
                || ! $scan || $scan->event_type !== 'parcel_received' || $scan->hub_id !== $hub->id
                || $scan->parcel->delivery_id !== $parcel->id || $scan->manifest->direction !== 'outbound' || $scan->manifest->status !== 'closed'
                || $scan->parcel->route_snapshot !== app(DeliveryReturnService::class)->forwardRoute($parcel)) {
                throw new DomainException('Pickup staging requires the actual destination manifest receipt and closure.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $parcel->status = 'ready_for_hub_pickup';
            $parcel->save();
            $parcel->order->update(['status' => 'sorted']);
            $checkpoint = DeliveryCheckpoint::record($parcel, 'ready_for_hub_pickup', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Original parcel staged for secure buyer collection.', evidence: ['source_state' => $source,
                    'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody, 'custody_after' => $custody]);
            $ready = CarbonImmutable::instance($checkpoint->created_at);
            $claim = PickupClaim::create(['delivery_id' => $parcel->id, 'ready_checkpoint_id' => $checkpoint->id,
                'hub_id' => $hub->id, 'buyer_id' => $parcel->order->buyer_id, 'prepared_by_id' => $actor->id,
                'tracking_number_snapshot' => $parcel->tracking_number, 'ready_at' => $ready,
                'expires_at' => $ready->timezone('Asia/Manila')->addDays(7)->utc(), 'status' => 'ready']);
            $this->record($claim, $actor, 'ready', $source, DeliveryCheckpoint::state($parcel), ['barcode_scanned' => $barcode]);
            $this->notices->pickup($claim, 'ready');

            return $parcel;
        });
    }

    public function issue(Delivery $delivery, User $buyer): string
    {
        return DB::transaction(function () use ($delivery, $buyer) {
            [$parcel, $buyer] = $this->lock($delivery, $buyer);
            app(BuyerAccessService::class)->requireExistingOrders($buyer, $parcel->order);
            $claim = $this->claim($parcel);
            $this->assertReady($parcel, $claim);
            if ($claim->code_hash || $claim->code_issued_at) {
                throw new DomainException('The code was already shown once. It cannot be revealed again. Contact the hub if it was lost.');
            }
            $code = strtoupper(bin2hex(random_bytes(6)));
            $source = $claim->state();
            $hash = Hash::make($code);
            $this->assertReady($parcel, $claim);
            $claim->fill(['code_hash' => $hash, 'code_issued_at' => now()])->save();
            $this->record($claim, $buyer, 'code_issued', $source, $claim->state());

            return $code;
        });
    }

    public function release(Delivery $delivery, User $actor, LogisticsHub $hub, array $input): array
    {
        $inputs = app(WaybillScanInputService::class);
        $input = $inputs->normalize($input);
        $input = Validator::make($input, ['barcode' => $inputs->barcodeRules(), 'claim_code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'buyer_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'], 'recipient_name' => ['required', 'string', new ApplicationText('name', 2, 255)],
            'identity_confirmed' => ['required', 'accepted'], 'request_token' => ['required', 'uuid'], 'notes' => $inputs->notesRules(),
            'cash_received' => ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/'],
            'change_given' => ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/'],
            'cash_confirmed' => ['nullable', 'boolean']])->validate();

        return DB::transaction(function () use ($delivery, $actor, $hub, $input, $inputs) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $this->handler($parcel, $actor, $hub);
            $barcode = $inputs->matchedBarcode($input['barcode'], $parcel);
            $claim = $this->claim($parcel);
            $fingerprint = hash('sha256', json_encode([$actor->id, $hub->id, $barcode, hash('sha256', $input['claim_code']),
                (int) $input['buyer_id'], $input['recipient_name'], (bool) $input['identity_confirmed'], $input['cash_received'] ?? null,
                $input['change_given'] ?? null, (bool) ($input['cash_confirmed'] ?? false), $input['notes'] ?? null], JSON_THROW_ON_ERROR));
            $original = PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This verification request already recorded different evidence.');
                }

                return ['success' => $original->event_type === 'collected', 'delivery' => $parcel,
                    'message' => $original->event_type === 'collected' ? 'Original pickup receipt retained.' : 'The original code verification did not succeed.'];
            }
            $this->assertReady($parcel, $claim);
            $buyer = User::findOrFail($claim->buyer_id);
            app(BuyerAccessService::class)->requireExistingOrders($buyer, $parcel->order);
            if ((int) $input['buyer_id'] !== $buyer->id || mb_strtolower(trim($input['recipient_name'])) !== mb_strtolower(trim($buyer->name))) {
                throw new DomainException('Verify a valid photo ID matching the actual buyer account before handoff.');
            }
            if ($claim->locked_until?->isFuture()) {
                throw new DomainException('Code verification is locked for 15 minutes after five failed attempts.');
            }
            if (! $claim->code_hash || ! Hash::check($input['claim_code'], $claim->code_hash)) {
                $source = $claim->state();
                if ($claim->locked_until && ! $claim->locked_until->isFuture()) {
                    $claim->failed_verifications = 0;
                    $claim->locked_until = null;
                }
                $claim->failed_verifications++;
                if ($claim->failed_verifications >= 5) {
                    $claim->locked_until = now()->addMinutes(15);
                }
                $claim->save();
                $this->record($claim, $actor, $claim->failed_verifications >= 5 ? 'verification_locked' : 'invalid_code', $source, $claim->state(),
                    ['request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint]);

                return ['success' => false, 'delivery' => $parcel, 'message' => 'The claim code could not be verified.'];
            }
            $due = $this->cents((string) $parcel->order->total_amount);
            $tender = $this->cents($input['cash_received'] ?? '0');
            $change = $this->cents($input['change_given'] ?? '0');
            if ($parcel->order->payment_method !== 'cod' || $parcel->order->payment_status !== 'pending'
                || ! ($input['cash_confirmed'] ?? false) || $due <= 0 || $tender - $change !== $due) {
                throw new DomainException('Record the actual COD tender and correct change, then confirm cash was received before release.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $this->assertReady($parcel, $claim);
            $claim->fill(['status' => 'collected', 'consumed_at' => now()])->save();
            $parcel->status = 'customer_collected';
            $parcel->current_hub_id = null;
            $parcel->delivered_at = now();
            $parcel->save();
            $parcel->order->update(['status' => 'delivered']);
            $event = $this->record($claim, $actor, 'collected', $source, DeliveryCheckpoint::state($parcel),
                ['barcode_scanned' => $barcode, 'recipient_name' => $buyer->name, 'notes' => $input['notes'] ?? null,
                    'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint]);
            CodCustodyEntry::create(['order_id' => $parcel->order_id, 'delivery_id' => $parcel->id, 'logistics_company_id' => $parcel->logistics_company_id,
                'hub_id' => $hub->id, 'actor_id' => $actor->id, 'holder_user_id' => $actor->id, 'pickup_claim_event_id' => $event->id,
                'entry_type' => 'counter_collection', 'amount_cents' => $due, 'tender_cents' => $tender, 'change_cents' => $change]);
            DeliveryCheckpoint::record($parcel, 'customer_collected', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Matching buyer photo ID, one-time claim and exact COD collection confirmed. Evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                    'custody_after' => ['kind' => 'buyer', 'user_id' => $buyer->id, 'pickup_collection_reference' => $event->reference]]);
            $this->notices->pickup($claim, 'collected');

            return ['success' => true, 'delivery' => $parcel, 'message' => 'Buyer collection and COD custody recorded.'];
        });
    }

    public function processDue(): int
    {
        $count = 0;
        foreach (PickupClaim::where('status', 'ready')->orderBy('id')->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id) {
                $snapshot = PickupClaim::findOrFail($id);
                Order::whereKey(Delivery::whereKey($snapshot->delivery_id)->value('order_id'))->lockForUpdate()->firstOrFail();
                Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereKey($snapshot->buyer_id)->lockForUpdate()->firstOrFail();
                $claim = PickupClaim::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($claim->status !== 'ready') {
                    return 0;
                }
                if (now()->greaterThanOrEqualTo($claim->expires_at)) {
                    $source = $claim->state();
                    $claim->status = 'expired';
                    $claim->save();
                    $this->record($claim, null, 'holding_expired', $source, $claim->state());
                    $this->notices->pickup($claim, 'expired');

                    return 1;
                }
                $stage = now()->greaterThanOrEqualTo($claim->ready_at->timezone('Asia/Manila')->addDays(6)) ? 'day_six'
                    : (now()->greaterThanOrEqualTo($claim->ready_at->timezone('Asia/Manila')->addDays(3)) ? 'day_three' : null);
                if ($stage && ! PickupClaimEvent::where('pickup_claim_id', $id)->where('event_type', $stage)->exists()) {
                    $this->record($claim, null, $stage, $claim->state(), $claim->state());
                    $this->notices->pickup($claim, $stage);

                    return 1;
                }

                return 0;
            });
        }

        return $count;
    }

    public function beginReturn(Delivery $delivery, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus, string $claimReference): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $hub, $barcode, $expectedStatus, $claimReference) {
            [$parcel,$actor] = $this->lock($delivery, $actor);
            $this->handler($parcel, $actor, $hub);
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($barcode, $parcel);
            $claim = $this->claim($parcel);
            if ($claim->reference !== $claimReference) {
                throw new DomainException('Refresh the actual pickup claim before starting its return.');
            }
            $original = PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('event_type', 'return_started')->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || $original->barcode_scanned !== $barcode) {
                    throw new DomainException('Another handler already initiated this return.');
                }

                return $parcel;
            }
            $expiry = PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('event_type', 'holding_expired')->first();
            if ($expectedStatus !== 'ready_for_hub_pickup' || $claim->status !== 'expired' || ! $expiry || now()->lessThan($claim->expires_at)) {
                throw new DomainException('Record the actual seven-day expiry before an assigned handler starts this return.');
            }
            $this->assertReadyCustody($parcel, $claim);
            $source = DeliveryCheckpoint::state($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $parcel->status = 'return_to_sender';
            $parcel->save();
            $parcel->order->update(['status' => 'delivery_failed']);
            $event = $this->record($claim, $actor, 'return_started', $source, DeliveryCheckpoint::state($parcel), ['barcode_scanned' => $barcode]);
            $checkpoint = DeliveryCheckpoint::record($parcel, 'expired_pickup_return_started', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Expired pickup returned to the original reverse route. Evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody, 'custody_after' => $custody]);
            app(DeliveryReturnService::class)->beginPickup($parcel, $actor, $checkpoint, $claim, $event);

            return $parcel;
        });
    }

    public function hasCollectionEvidence(Delivery $parcel): bool
    {
        $claim = PickupClaim::where('delivery_id', $parcel->id)->where('status', 'collected')->whereNotNull('consumed_at')->first();
        $event = $claim ? PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('event_type', 'collected')->first() : null;
        $custody = DeliveryCheckpoint::lastCustody($parcel);

        return $event && $claim->buyer_id === $parcel->order->buyer_id && $claim->hub_id === $parcel->destination_bayan_hub_id
            && $parcel->current_hub_id === null && $event->barcode_scanned === $parcel->tracking_number && ($custody['kind'] ?? null) === 'buyer'
            && ($custody['user_id'] ?? null) === $parcel->order->buyer_id && ($custody['pickup_collection_reference'] ?? null) === $event->reference
            && $parcel->checkpoints()->where('checkpoint_type', 'customer_collected')->where('scanned_by_id', $event->actor_id)
                ->where('hub_id', $claim->hub_id)->where('barcode_scanned', $parcel->tracking_number)
                ->where('custody_after->pickup_collection_reference', $event->reference)->exists()
            && CodCustodyEntry::where('pickup_claim_event_id', $event->id)->where('entry_type', 'counter_collection')
                ->where('delivery_id', $parcel->id)->where('order_id', $parcel->order_id)->where('hub_id', $claim->hub_id)
                ->where('actor_id', $event->actor_id)->where('holder_user_id', $event->actor_id)
                ->where('amount_cents', $this->cents((string) $parcel->order->total_amount))->exists();
    }

    private function lock(Delivery $delivery, User $actor): array
    {
        $order = Order::whereKey(Delivery::whereKey($delivery->id)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);
        $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id, $order->buyer_id], [$parcel->destination_bayan_hub_id]);

        return [$parcel, User::findOrFail($actor->id)];
    }

    private function handler(Delivery $parcel, User $actor, LogisticsHub $hub): void
    {
        if (! $actor->isLogistics() || ! $actor->canAccessPortal() || ! $this->eligibility->canScan($actor, $hub)
            || $parcel->destination_bayan_hub_id !== $hub->id || $parcel->delivery_type !== 'hub_self_pickup'
            || ! LogisticsHub::eligible()->whereKey($hub->id)->where('tier', 'local_bayan_hub')->where('allows_self_pickup', true)->exists()) {
            throw new DomainException('Only the assigned eligible destination-counter handler may stage or release this parcel.');
        }
    }

    private function claim(Delivery $parcel): PickupClaim
    {
        $claim = PickupClaim::where('delivery_id', $parcel->id)->lockForUpdate()->first();
        if (! $claim || $claim->tracking_number_snapshot !== $parcel->tracking_number || $claim->buyer_id !== $parcel->order->buyer_id
            || $claim->hub_id !== $parcel->destination_bayan_hub_id || $parcel->delivery_type !== 'hub_self_pickup') {
            throw new DomainException('This parcel needs its original recorded pickup staging and claim.');
        }

        return $claim;
    }

    private function assertReady(Delivery $parcel, PickupClaim $claim): void
    {
        if ($claim->status !== 'ready' || now()->greaterThanOrEqualTo($claim->expires_at) || $claim->consumed_at) {
            throw new DomainException('This pickup claim is expired or already used.');
        }
        $this->assertReadyCustody($parcel, $claim);
    }

    private function assertReadyCustody(Delivery $parcel, PickupClaim $claim): void
    {
        $checkpoint = $parcel->checkpoints()->whereNull('source_checkpoint_id')->latest('id')->first();
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($parcel->status !== 'ready_for_hub_pickup' || $parcel->order->status !== 'sorted' || $parcel->current_hub_id !== $claim->hub_id
            || $checkpoint?->id !== $claim->ready_checkpoint_id || $checkpoint->checkpoint_type !== 'ready_for_hub_pickup' || ! $checkpoint->barcode_scanned
            || ($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $claim->hub_id) {
            throw new DomainException('The original parcel must remain in its actual staged destination-hub custody.');
        }
    }

    private function cents(string $amount): int
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/', $amount)) {
            throw new DomainException('Use a valid whole peso and centavo amount.');
        }
        [$whole,$decimal] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }

    private function record(PickupClaim $claim, ?User $actor, string $type, array $source, array $target, array $input = []): PickupClaimEvent
    {
        return PickupClaimEvent::create($input + ['pickup_claim_id' => $claim->id, 'actor_id' => $actor?->id,
            'provenance' => $actor ? ($actor->isBuyer() ? 'owning_buyer' : 'counter_handler') : 'holding_clock',
            'event_type' => $type, 'source_state' => $source, 'target_state' => $target]);
    }
}
