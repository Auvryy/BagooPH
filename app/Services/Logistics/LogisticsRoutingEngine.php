<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Shop;
use Exception;
use Illuminate\Support\Str;

class LogisticsRoutingEngine
{
    /**
     * Non-contiguous island provinces/regions excluded from 100% road freight scope.
     */
    public const EXCLUDED_ISLAND_TERRITORIES = [
        'batanes',
        'palawan',
        'marinduque',
        'romblon',
        'masbate',
        'catanduanes',
        'cebu',
        'bohol',
        'siquijor',
        'guimaras',
        'biliran',
        'leyte',
        'samar',
        'basilan',
        'sulu',
        'tawi-tawi',
        'camiguin',
        'dinagat islands',
    ];

    /**
     * Validate whether an address is within contiguous land road freight boundaries.
     */
    public function isContiguousRoadServiceable(string $province, string $city): bool
    {
        $normalizedProvince = strtolower(trim($province));
        foreach (self::EXCLUDED_ISLAND_TERRITORIES as $excluded) {
            if (str_contains($normalizedProvince, $excluded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the Origin Bayan Hub for a seller shop.
     */
    public function resolveOriginBayanHub(Shop $shop, ?LogisticsCompany $company = null): ?LogisticsHub
    {
        $query = LogisticsHub::where('tier', 'local_bayan_hub')
            ->where('is_active', true);

        if ($company) {
            $query->where('logistics_company_id', $company->id);
        }

        $city = $this->normalizePlace((string) $shop->city);
        $cityMatch = $city === '' ? null : (clone $query)->get()->first(function (LogisticsHub $hub) use ($city) {
            $hubCity = $this->normalizePlace((string) $hub->city_municipality);

            return $hubCity !== '' && (str_contains($city, $hubCity) || str_contains($hubCity, $city));
        });
        if ($cityMatch) {
            return $cityMatch;
        }

        return null;
    }

    /**
     * Resolve the Destination Bayan Hub for a buyer address.
     */
    public function resolveDestinationBayanHub(string $province, string $city, ?string $barangay = null, ?LogisticsCompany $company = null): ?LogisticsHub
    {
        $query = LogisticsHub::where('tier', 'local_bayan_hub')
            ->where('is_active', true);

        if ($company) {
            $query->where('logistics_company_id', $company->id);
        }

        // 1. Check if covered by a hub's specific coverage_barangays
        if ($barangay) {
            $allHubs = (clone $query)->get();
            foreach ($allHubs as $hub) {
                if ($hub->coversBarangay($barangay)) {
                    return $hub;
                }
            }
        }

        // 2. Match city/municipality
        $normalizedCity = $this->normalizePlace($city);
        $cityMatch = $normalizedCity === '' ? null : (clone $query)->get()->first(function (LogisticsHub $hub) use ($normalizedCity) {
            $hubCity = $this->normalizePlace((string) $hub->city_municipality);

            return $hubCity !== '' && (str_contains($normalizedCity, $hubCity) || str_contains($hubCity, $normalizedCity));
        });
        if ($cityMatch) {
            return $cityMatch;
        }

        return null;
    }

    /**
     * Resolve Regional Mother Hub corresponding to a Local Bayan Hub.
     */
    public function resolveMotherHubForBayanHub(LogisticsHub $bayanHub): ?LogisticsHub
    {
        $province = $this->normalizePlace((string) $bayanHub->province);

        return LogisticsHub::where('logistics_company_id', $bayanHub->logistics_company_id)
            ->where('tier', 'regional_mother_hub')
            ->where('is_active', true)
            ->get()
            ->first(fn (LogisticsHub $hub) => $this->normalizePlace((string) $hub->province) === $province);
    }

    /**
     * Generate facility-to-facility hops and populate delivery routing legs.
     */
    public function planDeliveryRoute(Delivery $delivery, Order $order, Shop $shop): Delivery
    {
        $company = $delivery->company ?? $this->resolveCompanyForRoute($order, $shop);

        $delivery->logistics_company_id = $company->id;
        $delivery->logistics_partner = $company->name;

        // Origin Legs
        $originBayan = $this->resolveOriginBayanHub($shop, $company);
        if (! $originBayan) {
            throw new Exception('No origin Bayan Hub serves the seller location.');
        }
        $delivery->origin_bayan_hub_id = $originBayan->id;
        $delivery->current_hub_id = null;

        $originMother = $this->resolveMotherHubForBayanHub($originBayan);
        if (! $originMother) {
            throw new Exception('No active Mother Hub serves the seller origin hub.');
        }
        $delivery->origin_mother_hub_id = $originMother->id;

        // Destination Legs
        if ($order->delivery_type === 'hub_self_pickup' && $order->pickup_hub_id) {
            $destBayan = LogisticsHub::whereKey($order->pickup_hub_id)
                ->where('logistics_company_id', $company->id)
                ->where('tier', 'local_bayan_hub')
                ->where('is_active', true)
                ->first();
        } else {
            $destBayan = $this->resolveDestinationBayanHub(
                $order->shipping_province ?? '',
                $order->shipping_city ?? '',
                $order->destination_barangay,
                $company
            );
        }

        if (! $destBayan) {
            throw new Exception('No destination Bayan Hub serves the buyer address.');
        }

        $delivery->destination_bayan_hub_id = $destBayan->id;
        $destMother = $this->resolveMotherHubForBayanHub($destBayan);
        if (! $destMother) {
            throw new Exception('No active Mother Hub serves the buyer destination hub.');
        }
        $delivery->destination_mother_hub_id = $destMother->id;

        // Set destination sorting bin code
        $targetBarangay = $order->destination_barangay ?? 'GENERAL';
        $delivery->destination_bin = $order->delivery_type === 'hub_self_pickup'
            ? 'STAGE: SELF-PICKUP-SHELF'
            : 'BIN: BRGY-'.strtoupper(str_replace(' ', '-', $targetBarangay));

        $delivery->save();

        return $delivery;
    }

    private function resolveCompanyForRoute(Order $order, Shop $shop): LogisticsCompany
    {
        $companies = LogisticsCompany::where('is_active', true)->where('status', 'active')->get();

        foreach ($companies as $company) {
            $origin = $this->resolveOriginBayanHub($shop, $company);
            $destination = $order->delivery_type === 'hub_self_pickup' && $order->pickup_hub_id
                ? LogisticsHub::whereKey($order->pickup_hub_id)
                    ->where('logistics_company_id', $company->id)
                    ->where('tier', 'local_bayan_hub')
                    ->where('is_active', true)
                    ->first()
                : $this->resolveDestinationBayanHub(
                    $order->shipping_province ?? '',
                    $order->shipping_city ?? '',
                    $order->destination_barangay,
                    $company
                );

            if ($origin && $destination && $this->resolveMotherHubForBayanHub($origin) && $this->resolveMotherHubForBayanHub($destination)) {
                return $company;
            }
        }

        throw new Exception('No logistics company can provide the complete seller-to-buyer hub route.');
    }

    private function normalizePlace(string $value): string
    {
        return (string) Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /**
     * Generate dynamic scan prompts for mobile PWA handlers.
     */
    public function getDynamicScanPrompt(Delivery $delivery, ?LogisticsHub $currentHub = null): array
    {
        $hub = $currentHub ?? $delivery->currentHub;
        $status = strtolower($delivery->status);

        // 1. Destination Bayan Hub inbound custody must be recorded before sorting.
        if ($hub && $hub->id === $delivery->destination_bayan_hub_id && $status === OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB) {
            return [
                'action' => 'RECEIVE_AT_DESTINATION_HUB',
                'prompt' => "RECEIVE AT DESTINATION HUB: [{$hub->code}]",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                'color' => 'green',
            ];
        }

        // 2. A parcel already received at the destination is staged or awaits a separate sort action.
        if ($hub && $hub->id === $delivery->destination_bayan_hub_id && $status === OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB) {
            if ($delivery->isSelfPickup()) {
                return [
                    'action' => 'STAGE_FOR_PICKUP',
                    'prompt' => 'STAGE AT COUNTER: SHELF-PICKUP-BAY-A (NOTIFY BUYER)',
                    'next_status' => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    'color' => 'green',
                ];
            }

            $binCode = $delivery->destination_bin ?? 'BIN: GENERAL-DELIVERY';

            return [
                'action' => 'AWAIT_BARANGAY_SORT',
                'prompt' => "SORT REQUIRED: {$binCode}",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                'color' => 'emerald',
            ];
        }

        // 3. Origin Bayan Hub receives custody from the pickup rider.
        if ($hub && $hub->id === $delivery->origin_bayan_hub_id && in_array($status, ['assigned', 'assigned_pickup', 'picked_up'], true)) {
            return [
                'action' => 'RECEIVE_FROM_PICKUP_RIDER',
                'prompt' => "RECEIVE AT ORIGIN HUB: [{$hub->code}]",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                'color' => 'blue',
            ];
        }

        // 4. A second scan at the origin loads the parcel onto a feeder manifest.
        if ($hub && $hub->id === $delivery->origin_bayan_hub_id && $status === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB) {
            $motherHubCode = $delivery->originMotherHub?->code ?? 'MOTHER-HUB';

            return [
                'action' => 'DISPATCH_TO_FEEDER',
                'prompt' => "LOAD TO SHUTTLE: L300-FEEDER -> [{$motherHubCode}]",
                'next_status' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                'color' => 'blue',
            ];
        }

        // 5. Mother Hub inbound and outbound scans are separate custody events.
        if ($hub && ($hub->id === $delivery->origin_mother_hub_id || $hub->id === $delivery->destination_mother_hub_id || $hub->isMotherHub())) {
            if ($status === OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB) {
                return [
                    'action' => 'RECEIVE_AT_MOTHER_HUB',
                    'prompt' => "RECEIVE AT MOTHER HUB: [{$hub->code}]",
                    'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                    'color' => 'indigo',
                ];
            }

            if ($status === OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB) {
                return [
                    'action' => 'SORT_TO_LINE_HAUL',
                    'prompt' => 'SORT TO DESTINATION LINE-HAUL CAGE',
                    'next_status' => OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    'color' => 'indigo',
                ];
            }

            if ($status !== OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL) {
                return [
                    'action' => 'INSPECT_WAYBILL',
                    'prompt' => "PARCEL: {$delivery->tracking_number} | STATUS: ".strtoupper($status),
                    'next_status' => $status,
                    'color' => 'gray',
                ];
            }

            $destHubCode = $delivery->destinationBayanHub?->code ?? 'DEST-BAYAN-HUB';

            return [
                'action' => 'DISPATCH_LINE_HAUL',
                'prompt' => "LOAD TO DESTINATION MANIFEST -> [{$destHubCode}]",
                'next_status' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
                'color' => 'indigo',
            ];
        }

        return [
            'action' => 'INSPECT_WAYBILL',
            'prompt' => "PARCEL: {$delivery->tracking_number} | STATUS: ".strtoupper($status),
            'next_status' => $status,
            'color' => 'gray',
        ];
    }
}
