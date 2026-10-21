<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SaleReminder;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\CodMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductSaleService
{
    /** Caller holds the owning seller, shop and product locks. */
    public function save(Product $product, array $input, ?ProductSale $existing = null): ProductSale
    {
        if ($existing) {
            $existing = ProductSale::whereKey($existing->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            if ($existing->ended_at || $existing->ends_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['sale' => 'This sale has ended. Create a new schedule instead.']);
            }
        }
        $defaults = $existing ? ['kind' => $existing->kind, 'name' => $existing->name, 'price' => $existing->price,
            'discount_type' => $existing->discount_type ?? 'fixed', 'percentage' => $existing->percentage,
            'starts_at' => $existing->starts_at->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'ends_at' => $existing->ends_at->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'), 'quantity' => $existing->quantity] : [];
        $input = ['discount_type' => 'fixed', ...$defaults, ...$input];
        $percentage = $input['discount_type'] === 'percentage';
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['store', 'flash'])],
            'name' => ['required', 'string', 'max:100'],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'percentage' => [$percentage ? 'required' : 'exclude', 'bail', new AsciiPositiveInteger, 'integer', 'between:1,99'],
            'price' => [$percentage ? 'exclude' : 'required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:1,99999999.99'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'quantity' => ['nullable', 'required_if:kind,flash', new AsciiPositiveInteger, 'integer', 'between:1,1000000'],
            'product_id' => ['prohibited'], 'shop_id' => ['prohibited'], 'used_quantity' => ['prohibited'], 'ended_at' => ['prohibited'],
        ], ['percentage.*' => 'Enter a whole discount percentage from 1 to 99.'])->validate();
        $data['price'] = $percentage ? number_format(app(SalePriceService::class)->discountedCents(CodMoney::cents($product->price), (int) $data['percentage']) / 100, 2, '.', '')
            : number_format((float) $data['price'], 2, '.', '');
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a short name for this sale.']);
        }
        $starts = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Asia/Manila')->utc();
        $ends = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Asia/Manila')->utc();
        if ($ends->lessThanOrEqualTo(now()) || $starts->diffInDays($ends) > 31) {
            throw ValidationException::withMessages(['ends_at' => 'Choose a future end time within 31 days of the start.']);
        }
        if (! $percentage && CodMoney::cents($data['price']) >= CodMoney::cents($product->price)) {
            throw ValidationException::withMessages(['price' => 'The sale price must be below the product’s regular price.']);
        }
        if (! $percentage && min(app(SalePriceService::class)->regularPrices($product)) - max(0, CodMoney::cents($product->price) - CodMoney::cents($data['price'])) < 1) {
            throw ValidationException::withMessages(['price' => 'This price would make an option free or negative. Increase the base sale price or use a percentage.']);
        }
        if ($existing && $data['kind'] !== $existing->kind) {
            throw ValidationException::withMessages(['kind' => 'A sale keeps its original type. Create another schedule instead.']);
        }
        if ($existing && (! $starts->equalTo($existing->starts_at) || ! $ends->equalTo($existing->ends_at))
            && SaleReminder::where('product_sale_id', $existing->id)->whereNotNull('notified_at')->exists()) {
            throw ValidationException::withMessages(['sale' => 'A reminder was already sent for this slot. End it and create a new schedule to change its times.']);
        }
        if ($existing && $existing->starts_at->lessThanOrEqualTo(now())) {
            if (! $starts->equalTo($existing->starts_at) || ! $ends->equalTo($existing->ends_at)
                || ($existing->kind === 'flash' && ((int) $data['quantity'] !== $existing->quantity
                    || $data['discount_type'] !== ($existing->discount_type ?? 'fixed')
                    || ($percentage ? (int) $data['percentage'] !== $existing->percentage : CodMoney::cents($data['price']) !== CodMoney::cents($existing->price))))) {
                throw ValidationException::withMessages(['sale' => 'A running flash slot keeps its price, quantity and schedule. A running store sale can change its price; use End now to stop it.']);
            }
        } elseif ($starts->lessThan(now()->startOfMinute())) {
            throw ValidationException::withMessages(['starts_at' => 'Choose the current minute or a future start time.']);
        }
        if ($data['kind'] === 'flash' && (! $existing || $existing->starts_at->isFuture() || (int) $data['quantity'] !== $existing->quantity)) {
            $used = $existing?->used_quantity ?? 0;
            if ((int) $data['quantity'] < $used || (int) $data['quantity'] - $used > $product->stock) {
                throw ValidationException::withMessages(['quantity' => 'The remaining flash quota cannot exceed the product’s '.$product->stock.' available units or remove units already purchased.']);
            }
        }
        $overlap = ProductSale::where('product_id', $product->id)->where('kind', $data['kind'])->whereNull('ended_at')
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => 'This product already has a sale of this type during that time. Choose a separate slot.']);
        }
        $values = ['kind' => $data['kind'], 'name' => $data['name'], 'price' => $data['price'], 'starts_at' => $starts,
            'ends_at' => $ends, 'quantity' => $data['kind'] === 'flash' ? (int) $data['quantity'] : null,
            'discount_type' => $data['discount_type'], 'percentage' => $percentage ? (int) $data['percentage'] : null];
        $product->unsetRelation('sales');
        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return $product->sales()->create($values);
    }

    public function end(Product $product, ProductSale $sale): void
    {
        $sale = ProductSale::whereKey($sale->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
        if (! $sale->ended_at) {
            $sale->update(['ended_at' => now()]);
        }
    }
}
