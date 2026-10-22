<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\User;
use App\Services\Wallet\WalletPaymentService;
use DomainException;
use Illuminate\Support\Facades\DB;

class HubScanTransferService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function nextHub(Delivery $parcel): ?LogisticsHub
    {
        $source = $parcel->current_hub_id;
        $next = match (true) {
            $parcel->status === 'arrived_at_origin_hub' && $source === $parcel->origin_bayan_hub_id => $parcel->origin_mother_hub_id,
            in_array($parcel->status, ['arrived_at_mother_hub', 'sorted_to_line_haul'], true) && $source === $parcel->origin_mother_hub_id => $parcel->destination_mother_hub_id !== $source ? $parcel->destination_mother_hub_id : $parcel->destination_bayan_hub_id,
            in_array($parcel->status, ['arrived_at_mother_hub', 'sorted_to_line_haul'], true) && $source === $parcel->destination_mother_hub_id => $parcel->destination_bayan_hub_id,
            default => null,
        };
        if (! $source || ! $next || ! $parcel->origin_mother_hub_id || ! $parcel->destination_mother_hub_id) {
            return null;
        }
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if (($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $source || isset($custody['manifest_scan_reference'])
            || LogisticsManifestParcel::where('delivery_id', $parcel->id)->where('included', true)
                ->whereHas('manifest', fn ($query) => $query->where('status', '!=', 'closed'))->exists()) {
            return null;
        }
        $fromOrigin = $parcel->status === 'arrived_at_origin_hub';
        $toMother = $fromOrigin || ($source === $parcel->origin_mother_hub_id && $source !== $parcel->destination_mother_hub_id);
        if (! LogisticsHub::eligible()->where('logistics_company_id', $parcel->logistics_company_id)
            ->where('tier', $fromOrigin ? 'local_bayan_hub' : 'regional_mother_hub')->whereKey($source)->exists()) {
            return null;
        }

        return LogisticsHub::eligible()->where('logistics_company_id', $parcel->logistics_company_id)
            ->where('tier', $toMother ? 'regional_mother_hub' : 'local_bayan_hub')->find($next);
    }

    public function action(LogisticsHub $hub): string
    {
        return $hub->isMotherHub() ? 'RECEIVE_NEXT_MOTHER_HUB' : 'RECEIVE_NEXT_BAYAN_HUB';
    }

    public function receive(Delivery $parcel, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus, ?string $notes = null): Delivery
    {
        return DB::transaction(function () use ($parcel, $actor, $hub, $barcode, $expectedStatus, $notes) {
            $order = Order::whereKey($parcel->order_id)->lockForUpdate()->firstOrFail();
            $parcel = Delivery::whereKey($parcel->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $parcel->setRelation('order', $order);
            $this->eligibility->lockNetwork((int) $parcel->logistics_company_id, [$actor->id], [$parcel->current_hub_id, $hub->id]);
            $actor = User::findOrFail($actor->id);
            $hub = LogisticsHub::eligible()->find($hub->id);
            if (! $hub || ! $actor->isLogistics() || ! $actor->canAccessPortal() || ! $this->eligibility->canScan($actor, $hub)) {
                throw new DomainException('An active handler at the receiving hub must confirm this parcel receipt.');
            }
            if ($order->status !== 'at_sorting_center' || $parcel->status !== $expectedStatus) {
                throw new DomainException('The parcel changed. Scan its current waybill before confirming receipt.');
            }
            app(WalletPaymentService::class)->assertFulfillable($order);
            app(HandoverHoldService::class)->assertMovable($parcel);
            $next = $this->nextHub($parcel);
            if (! $next || $next->id !== $hub->id) {
                throw new DomainException('Receive this parcel only at its next required hub. Existing manifest transfers must finish their recorded handover.');
            }
            $inputs = app(WaybillScanInputService::class);
            $barcode = $inputs->matchedBarcode($barcode, $parcel, allowOrderNumber: true);
            $notes = $inputs->normalize(['notes' => $notes])['notes'] ?? null;
            validator(['notes' => $notes], ['notes' => $inputs->notesRules()])->validate();
            $previous = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->firstOrFail();
            $source = DeliveryCheckpoint::state($parcel);
            if ($previous->target_state !== $source) {
                throw new DomainException('The parcel state does not match its last confirmed receipt. Review its recorded handover first.');
            }
            $route = app(DeliveryReturnService::class)->forwardRoute($parcel);
            $parcel->status = $hub->isMotherHub() ? 'arrived_at_mother_hub' : 'arrived_at_destination_hub';
            $parcel->current_hub_id = $hub->id;
            $parcel->save();
            DeliveryCheckpoint::record(
                delivery: $parcel, type: $parcel->status, actor: $actor, hub: $hub,
                barcodeScanned: $barcode, notes: $notes ?? 'Physical parcel receipt confirmed at the next required hub.',
                scanProvenance: 'hub_receipt',
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel),
                    'custody_before' => $previous->custody_after,
                    'custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id,
                        'hub_receipt' => ['from_hub_id' => $source['current_hub_id'], 'source_checkpoint_id' => $previous->id,
                            'source_reference' => $previous->record_reference, 'route' => $route]]],
            );

            return $parcel;
        });
    }

    public function supportsReceipt(DeliveryCheckpoint $checkpoint, array $route, int $from, int $to): bool
    {
        $receipt = $checkpoint->custody_after['hub_receipt'] ?? [];
        if ($checkpoint->scan_provenance !== 'hub_receipt' || $checkpoint->actor_role !== 'logistics'
            || ! $checkpoint->scanned_by_id || ! $checkpoint->barcode_scanned || $checkpoint->hub_id !== $to
            || ($receipt['from_hub_id'] ?? null) !== $from || ($receipt['route'] ?? null) !== $route
            || ($checkpoint->custody_before['kind'] ?? null) !== 'hub' || ($checkpoint->custody_before['hub_id'] ?? null) !== $from
            || ($checkpoint->custody_after['kind'] ?? null) !== 'hub' || ($checkpoint->custody_after['hub_id'] ?? null) !== $to) {
            return false;
        }
        $previous = DeliveryCheckpoint::where('delivery_id', $checkpoint->delivery_id)->where('id', '<', $checkpoint->id)
            ->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();

        return $previous && $previous->id === ($receipt['source_checkpoint_id'] ?? null)
            && $previous->record_reference === ($receipt['source_reference'] ?? null)
            && $previous->custody_after === $checkpoint->custody_before
            && ($checkpoint->source_state['current_hub_id'] ?? null) === $from
            && ($checkpoint->target_state['current_hub_id'] ?? null) === $to;
    }
}
