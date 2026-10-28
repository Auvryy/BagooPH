<?php

namespace App\Services\Logistics;

use App\Exceptions\CheckoutException;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use DomainException;
use Illuminate\Support\Collection;

class LogisticsCheckoutService
{
    public function select(Collection $items, array $address, mixed $selectedId = null, bool $requireRoute = true): ?LogisticsCompany
    {
        $companies = LogisticsCompany::eligible()->orderBy('name')->get();
        if ($selectedId !== null && $selectedId !== '') {
            $companies = $companies->where('id', (int) $selectedId);
            if ($companies->isEmpty()) {
                throw new CheckoutException('Choose an active, approved logistics company.');
            }
        }
        $complete = ! empty($address['shipping_province']) && ! empty($address['shipping_city']);
        if ($complete) {
            $companies = $companies->filter(function (LogisticsCompany $company) use ($items, $address) {
                try {
                    return $items->pluck('product.shop')->unique('id')->every(fn ($shop) => app(LogisticsRoutingEngine::class)->serves($company, $shop, $address));
                } catch (DomainException) {
                    return false;
                }
            });
        }
        if (($address['delivery_type'] ?? 'doorstep') === 'hub_self_pickup' && ! empty($address['pickup_hub_id'])) {
            $hub = LogisticsHub::readyForSelfPickup()->find($address['pickup_hub_id']);
            $companies = $hub ? $companies->where('id', $hub->logistics_company_id) : collect();
        }
        if ($selectedId !== null && $selectedId !== '' && $companies->isEmpty()) {
            throw new CheckoutException('The selected company does not serve this address or collection hub. Choose another company or address.');
        }
        if ($requireRoute && (! $complete || $companies->isEmpty())) {
            throw new CheckoutException('This logistics company has no complete route for the seller and your delivery address. Choose another company or address.');
        }
        if (($selectedId === null || $selectedId === '') && $companies->count() > 1 && $requireRoute) {
            throw new CheckoutException('Choose your preferred logistics company before confirming the order.');
        }

        return $companies->first();
    }

    public function choices(?Collection $items = null, array $address = []): array
    {
        $companies = LogisticsCompany::eligible()->orderBy('name')->get();
        if ($items && ! empty($address['shipping_province']) && ! empty($address['shipping_city']) && strlen($address['shipping_postal_code'] ?? '') === 4) {
            $companies = $companies->filter(function ($company) use ($items, $address) {
                try {
                    return $items->pluck('product.shop')->unique('id')->every(fn ($shop) => app(LogisticsRoutingEngine::class)->serves($company, $shop, $address));
                } catch (DomainException) {
                    return false;
                }
            });
        }

        return $companies->map(fn ($company) => [
            'id' => $company->id, 'name' => $company->name,
            'base_shipping_cents' => $company->base_shipping_cents,
            'included_weight_grams' => $company->included_weight_grams,
            'additional_kg_cents' => $company->additional_kg_cents,
        ])->values()->all();
    }
}
