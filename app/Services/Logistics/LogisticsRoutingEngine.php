<?php

namespace App\Services\Logistics;

use App\Models\Address;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Shop;
use Exception;

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

        // 1. Try matching municipality/city
        $cityMatch = (clone $query)->where('city_municipality', 'ilike', '%' . trim($shop->city ?? '') . '%')->first();
        if ($cityMatch) {
            return $cityMatch;
        }

        // 2. Fallback to any active Bayan Hub in the same company
        return $query->first();
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
        $cityMatch = (clone $query)->where('city_municipality', 'ilike', '%' . trim($city) . '%')->first();
        if ($cityMatch) {
            return $cityMatch;
        }

        // 3. Match province
        $provinceMatch = (clone $query)->where('province', 'ilike', '%' . trim($province) . '%')->first();
        if ($provinceMatch) {
            return $provinceMatch;
        }

        return $query->first();
    }

    /**
     * Resolve Regional Mother Hub corresponding to a Local Bayan Hub.
     */
    public function resolveMotherHubForBayanHub(LogisticsHub $bayanHub): ?LogisticsHub
    {
        return LogisticsHub::where('logistics_company_id', $bayanHub->logistics_company_id)
            ->where('tier', 'regional_mother_hub')
            ->where('is_active', true)
            ->where(function ($q) use ($bayanHub) {
                $q->where('province', 'ilike', '%' . trim($bayanHub->province) . '%')
                  ->orWhere('city_municipality', 'ilike', '%' . trim($bayanHub->city_municipality) . '%');
            })
            ->first() ?? LogisticsHub::where('logistics_company_id', $bayanHub->logistics_company_id)
                ->where('tier', 'regional_mother_hub')
                ->where('is_active', true)
                ->first();
    }

    /**
     * Generate facility-to-facility hops and populate delivery routing legs.
     */
    public function planDeliveryRoute(Delivery $delivery, Order $order, Shop $shop): Delivery
    {
        $company = $delivery->company ?? LogisticsCompany::where('is_active', true)->first();
        if (!$company) {
            throw new Exception('No active logistics partner available for delivery routing.');
        }

        $delivery->logistics_company_id = $company->id;
        $delivery->logistics_partner = $company->name;

        // Origin Legs
        $originBayan = $this->resolveOriginBayanHub($shop, $company);
        $delivery->origin_bayan_hub_id = $originBayan?->id;
        $delivery->current_hub_id = $originBayan?->id;

        $originMother = $originBayan ? $this->resolveMotherHubForBayanHub($originBayan) : null;
        $delivery->origin_mother_hub_id = $originMother?->id;

        // Destination Legs
        if ($order->delivery_type === 'hub_self_pickup' && $order->pickup_hub_id) {
            $destBayan = LogisticsHub::find($order->pickup_hub_id);
        } else {
            $destBayan = $this->resolveDestinationBayanHub(
                $order->shipping_city ?? 'Laguna',
                $order->shipping_city ?? 'Santa Cruz',
                $order->destination_barangay,
                $company
            );
        }

        $delivery->destination_bayan_hub_id = $destBayan?->id;
        $destMother = $destBayan ? $this->resolveMotherHubForBayanHub($destBayan) : null;
        $delivery->destination_mother_hub_id = $destMother?->id;

        // Set destination sorting bin code
        $targetBarangay = $order->destination_barangay ?? 'GENERAL';
        $delivery->destination_bin = $order->delivery_type === 'hub_self_pickup'
            ? 'STAGE: SELF-PICKUP-SHELF'
            : 'BIN: BRGY-' . strtoupper(str_replace(' ', '-', $targetBarangay));

        $delivery->save();

        return $delivery;
    }

    /**
     * Generate dynamic scan prompts for mobile PWA handlers.
     */
    public function getDynamicScanPrompt(Delivery $delivery, ?LogisticsHub $currentHub = null): array
    {
        $hub = $currentHub ?? $delivery->currentHub;
        $status = strtolower($delivery->status);

        // 1. At Origin Bayan Hub
        if ($hub && $hub->id === $delivery->origin_bayan_hub_id) {
            $motherHubCode = $delivery->originMotherHub?->code ?? 'MOTHER-HUB';
            return [
                'action' => 'DISPATCH_TO_FEEDER',
                'prompt' => "LOAD TO SHUTTLE: L300-FEEDER -> [{$motherHubCode}]",
                'next_status' => 'in_transit_to_mother_hub',
                'color' => 'blue',
            ];
        }

        // 2. At Regional Mother Hub
        if ($hub && ($hub->id === $delivery->origin_mother_hub_id || $hub->isMotherHub())) {
            $destHubCode = $delivery->destinationBayanHub?->code ?? 'DEST-BAYAN-HUB';
            return [
                'action' => 'SORT_TO_LINE_HAUL',
                'prompt' => "SORT TO TRUCK BAY: HIGHWAY-LINE-HAUL -> [{$destHubCode}]",
                'next_status' => 'in_transit_to_destination_hub',
                'color' => 'indigo',
            ];
        }

        // 3. At Destination Bayan Hub
        if ($hub && $hub->id === $delivery->destination_bayan_hub_id) {
            if ($delivery->isSelfPickup()) {
                return [
                    'action' => 'STAGE_FOR_PICKUP',
                    'prompt' => 'STAGE AT COUNTER: SHELF-PICKUP-BAY-A (NOTIFY BUYER)',
                    'next_status' => 'ready_for_hub_pickup',
                    'color' => 'green',
                ];
            }

            $binCode = $delivery->destination_bin ?? 'BIN: GENERAL-DELIVERY';
            $riderName = $delivery->assignedRider?->name ?? 'ASSIGN RIDER';
            return [
                'action' => 'BIN_TO_BARANGAY',
                'prompt' => "{$binCode} | RIDER: {$riderName}",
                'next_status' => 'out_for_delivery',
                'color' => 'emerald',
            ];
        }

        return [
            'action' => 'INSPECT_WAYBILL',
            'prompt' => "PARCEL: {$delivery->tracking_number} | STATUS: " . strtoupper($status),
            'next_status' => $status,
            'color' => 'gray',
        ];
    }
}
