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
        if ($items) {
            $destinationReady = ! empty($address['shipping_province'])
                && ! empty($address['shipping_city'])
                && preg_match('/\A[0-9]{4}\z/', (string) ($address['shipping_postal_code'] ?? '')) === 1;
            $doorstep = ($address['delivery_type'] ?? 'doorstep') === 'doorstep';

            if ($doorstep && ! $destinationReady) {
                $companies = collect();
            } elseif ($destinationReady) {
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
        }

        return $companies->map(fn ($company) => [
            'id' => $company->id, 'name' => $company->name, 'logo' => $this->logoUrl($company),
            'base_shipping_cents' => $company->base_shipping_cents,
            'included_weight_grams' => $company->included_weight_grams,
            'additional_kg_cents' => $company->additional_kg_cents,
        ])->values()->all();
    }

    /** Add a company-scoped route to each shop's current quote without creating a parcel. */
    public function withRoutes(array $quote, Collection $items, ?LogisticsCompany $company, array $address): array
    {
        if (! $company || empty($address['shipping_province']) || empty($address['shipping_city'])
            || (($address['delivery_type'] ?? 'doorstep') === 'hub_self_pickup' && empty($address['pickup_hub_id']))) {
            return $quote;
        }
        $shops = $items->pluck('product.shop')->keyBy('id');
        try {
            foreach ($quote['shops'] as &$shopQuote) {
                $plan = app(LogisticsRoutingEngine::class)->previewRoute($company, $shops->get($shopQuote['shop_id']), $address);
                $shopQuote['shipping_quote'] = [...$shopQuote['shipping_quote'], ...$plan];
            }
        } catch (DomainException $exception) {
            throw new CheckoutException($exception->getMessage(), previous: $exception);
        }

        return $quote;
    }

    private function logoUrl(LogisticsCompany $company): ?string
    {
        $logo = $company->logo;
        if (! is_string($logo) || preg_match('/(?:kyc_documents|delivery-proofs|settlement-receipts|verification-document|\.\.|[\p{C}])/iu', $logo)
            || ! preg_match('~\A(?:/(?:images|storage)/[A-Za-z0-9_./-]+|https?://[^/?#\s@]+/[A-Za-z0-9_./-]+)\z~', $logo)) {
            return null;
        }

        return $logo;
    }
}
