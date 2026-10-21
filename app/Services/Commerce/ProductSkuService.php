<?php

namespace App\Services\Commerce;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductSkuService
{
    /** The product save transaction and unique key protect all listing/variant writers. */
    public function sync(Product $product): void
    {
        $claims = [];
        $errors = [];
        $values = ['sku' => $product->sku];
        foreach ($product->variants['combinations'] ?? [] as $index => $row) {
            if (isset($row['sku']) && trim((string) $row['sku']) !== '') {
                $values['combinations.'.$index.'.sku'] = $row['sku'];
            }
        }
        foreach ($values as $field => $value) {
            $key = Str::upper(trim((string) $value));
            if ($key === '') {
                continue;
            }
            if (isset($claims[$key])) {
                $errors[$claims[$key]] = $errors[$field] = 'Use a different SKU for each listing and option combination.';
            }
            $claims[$key] = $field;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        ksort($claims, SORT_STRING);
        DB::table('product_sku_claims')->where('product_id', $product->id)->delete();
        foreach ($claims as $key => $field) {
            $key = (string) $key;
            DB::table('product_sku_claims')->insertOrIgnore(['sku_key' => $key, 'product_id' => $product->id]);
            if ((int) DB::table('product_sku_claims')->where('sku_key', $key)->value('product_id') !== $product->id) {
                throw ValidationException::withMessages([$field => 'This SKU is already used by another listing or option. Choose a different SKU.']);
            }
        }
    }

    public function selected(Product $product, ?string $color, ?string $size): string
    {
        $row = collect($product->variants['combinations'] ?? [])->first(fn ($row) => ($row['color'] ?? null) === $color && ($row['size'] ?? null) === $size);

        return $row['sku'] ?? $product->sku.($color ? '-'.$color : '').($size ? '-'.$size : '');
    }
}
