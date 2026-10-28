<?php

namespace App\Services\Logistics;

use App\Exceptions\CheckoutException;
use App\Models\LogisticsCompany;
use Illuminate\Support\Collection;

class LogisticsRateService
{
    public const VOLUMETRIC_DIVISOR = 3500;

    /** Catalogue dimensions describe each packed unit; quantities aggregate the parcel volume. */
    public function quote(Collection $items, ?LogisticsCompany $company = null): array
    {
        $actual = 0;
        $volume = 0;
        $unknown = [];
        foreach ($items as $item) {
            $product = $item->product;
            $quantity = (int) $item->quantity;
            $actual += (int) round((float) $product->weight_kg * 1000) * $quantity;
            $dimensions = [$product->length_cm, $product->width_cm, $product->height_cm];
            if (! $product->weight_kg || collect($dimensions)->contains(fn ($value) => ! is_numeric($value) || (float) $value <= 0)) {
                $unknown[] = (int) $product->id;
            }
            $hundredths = array_map(fn ($value) => max(0, (int) round((float) $value * 100)), $dimensions);
            $volume += (int) ceil(array_product($hundredths) / 3500000) * $quantity;
        }
        $billable = max($actual, $volume);
        $included = max(1, (int) ($company?->included_weight_grams ?? 1000));
        $extraKilograms = (int) ceil(max(0, $billable - $included) / 1000);
        $base = (int) ($company?->base_shipping_cents ?? 6000);
        $additional = (int) ($company?->additional_kg_cents ?? 0);
        if ($additional > 0 && $unknown !== []) {
            throw new CheckoutException('The seller must record the selected products’ packed dimensions and actual weight before this weight-based shipping rate can be used.');
        }

        return [
            'version' => 2, 'company_id' => $company?->id, 'company_name' => $company?->name,
            'tariff_version' => (int) ($company?->tariff_version ?? 1),
            'actual_weight_grams' => $actual, 'volumetric_weight_grams' => $volume,
            'billable_weight_grams' => $billable, 'volumetric_divisor' => self::VOLUMETRIC_DIVISOR,
            'included_weight_grams' => $included, 'base_fee_cents' => $base,
            'additional_kg_cents' => $additional, 'additional_kilograms' => $extraKilograms,
            'shipping_cents' => $base + $extraKilograms * $additional,
            'earnings_model' => $company?->shipping_earnings_model ?? 'dedicated_fleet',
            'missing_measurement_product_ids' => array_values(array_unique($unknown)),
        ];
    }

    /** Exact integer-cent allocation; the company receives the rounding remainder. */
    public function shippingSplit(int $cents, string $model): array
    {
        $pickup = $model === 'dedicated_fleet' ? intdiv($cents, 6) : 0;
        $delivery = $model === 'dedicated_fleet' ? intdiv($cents * 5, 12) : intdiv($cents, 2);

        return ['pickup' => $pickup, 'delivery' => $delivery, 'company' => $cents - $pickup - $delivery];
    }
}
