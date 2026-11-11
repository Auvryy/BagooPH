<?php

namespace App\Services\Logistics;

use App\Exceptions\CheckoutException;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Services\Finance\CodMoney;
use DomainException;
use Illuminate\Support\Collection;

class LogisticsRateService
{
    public const VOLUMETRIC_DIVISOR = 3500;

    public const DEFAULT_BASE_CENTS = 5000;

    public const DEFAULT_INCLUDED_GRAMS = 1000;

    public const DEFAULT_ADDITIONAL_KG_CENTS = 2000;

    /** Catalogue dimensions describe each packed unit; quantities aggregate the parcel volume. */
    public function quote(Collection $items, ?LogisticsCompany $company = null): array
    {
        $actual = 0;
        $volume = 0;
        $unknown = [];
        $measurements = [];
        foreach ($items as $item) {
            $product = $item->product;
            $quantity = (int) $item->quantity;
            $actual += (int) round((float) $product->weight_kg * 1000) * $quantity;
            $dimensions = [$product->length_cm, $product->width_cm, $product->height_cm];
            $measurements[] = [
                'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity,
                'unit_weight_kg' => $product->weight_kg,
                'length_cm' => $product->length_cm, 'width_cm' => $product->width_cm, 'height_cm' => $product->height_cm,
            ];
            if (! is_numeric($product->weight_kg) || (float) $product->weight_kg <= 0
                || collect($dimensions)->contains(fn ($value) => ! is_numeric($value) || (float) $value <= 0)) {
                $unknown[] = (int) $product->id;
            }
            $hundredths = array_map(fn ($value) => max(0, (int) round((float) $value * 100)), $dimensions);
            $volume += (int) ceil(array_product($hundredths) / 3500000) * $quantity;
        }
        $billable = max($actual, $volume);
        $included = (int) ($company?->included_weight_grams ?? self::DEFAULT_INCLUDED_GRAMS);
        $base = (int) ($company?->base_shipping_cents ?? self::DEFAULT_BASE_CENTS);
        $additional = (int) ($company?->additional_kg_cents ?? self::DEFAULT_ADDITIONAL_KG_CENTS);
        $model = $company?->shipping_earnings_model ?? 'dedicated_fleet';
        if ($included < 1 || $base < 0 || $additional < 0 || ! in_array($model, ['standard', 'dedicated_fleet'], true)) {
            throw new CheckoutException('The selected company’s shipping tariff needs review before checkout.');
        }
        $extraKilograms = (int) ceil(max(0, $billable - $included) / 1000);
        if ($company && $additional > 0 && $unknown !== []) {
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
            'earnings_model' => $model,
            'missing_measurement_product_ids' => array_values(array_unique($unknown)),
            'measurements' => $measurements,
        ];
    }

    /** Exact integer-cent allocation; the company receives the rounding remainder. */
    public function shippingSplit(int $cents, string $model): array
    {
        if ($cents < 0 || ! in_array($model, ['standard', 'dedicated_fleet'], true)) {
            throw new DomainException('The original shipping amount and earnings model need review.');
        }
        $pickup = $model === 'dedicated_fleet' ? intdiv($cents, 6) : 0;
        $delivery = $model === 'dedicated_fleet'
            ? intdiv($cents, 12) * 5 + intdiv(($cents % 12) * 5, 12) : intdiv($cents, 2);

        return ['pickup' => $pickup, 'delivery' => $delivery, 'company' => $cents - $pickup - $delivery];
    }

    /** New purchases retain exact entitlements; earlier quotes keep their original named model. */
    public function purchasedShippingSplit(Order $order): array
    {
        $quote = $order->shipping_quote ?? [];
        $cents = CodMoney::cents($order->shipping_fee);
        if (! array_key_exists('earnings_split_cents', $quote)) {
            return $this->shippingSplit($cents, $quote['earnings_model'] ?? 'dedicated_fleet');
        }
        $split = $quote['earnings_split_cents'];
        if (($quote['earnings_terms_version'] ?? null) !== 1 || ! is_array($split)
            || count($split) !== 3 || array_diff(['pickup', 'delivery', 'company'], array_keys($split))
            || collect($split)->contains(fn ($part) => ! is_int($part) || $part < 0 || $part > $cents)
            || array_sum($split) !== $cents || ($quote['payable_shipping_cents'] ?? null) !== $cents) {
            throw new DomainException('The original shipping earnings allocation needs review.');
        }

        return $split;
    }
}
