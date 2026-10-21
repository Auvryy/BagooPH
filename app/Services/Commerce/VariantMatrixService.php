<?php

namespace App\Services\Commerce;

use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VariantMatrixService
{
    public function validate(array $input, array $colors, array $sizes): ?array
    {
        if (! array_key_exists('combinations', $input) || $input['combinations'] === null) {
            return null;
        }
        $independent = ($input['pricing_mode'] ?? 'surcharge') === 'independent';
        Validator::make($input, [
            'pricing_mode' => ['sometimes', Rule::in(['surcharge', 'independent'])],
            'combinations' => ['required', 'array', 'list', 'min:1', 'max:400'],
            'combinations.*.color' => ['nullable', 'string', 'max:100'],
            'combinations.*.size' => ['nullable', 'string', 'max:100'],
            'combinations.*.stock' => ['bail', 'required', 'regex:/\A[0-9]+\z/', 'integer', 'between:0,99999'],
            'combinations.*.extra_price' => [$independent ? 'exclude' : 'required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0,99999999.99'],
            'combinations.*.price' => [$independent ? 'required' : 'exclude', 'bail', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:1,1000000'],
            'combinations.*.sku' => [$independent ? 'required' : 'exclude', 'bail', 'string', 'max:50', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', 'distinct:ignore_case'],
        ], [
            'combinations.*.price.required' => 'Enter the full selling price for this option.',
            'combinations.*.price.between' => 'Enter a selling price from ₱1.00 to ₱1,000,000.00.',
            'combinations.*.stock.required' => 'Enter the available stock for this option.',
            'combinations.*.stock.regex' => 'Stock must be a whole number from 0 to 99,999.',
            'combinations.*.sku.required' => 'Enter a unique SKU for this option.',
            'combinations.*.sku.distinct' => 'Use a different SKU for each option.',
            'combinations.*.sku.regex' => 'Use letters, numbers, periods, hyphens or underscores in the SKU.',
        ])->validate();
        $expected = [];
        foreach ($colors === [] ? [null] : array_column($colors, 'name') as $color) {
            foreach ($sizes === [] ? [null] : array_column($sizes, 'name') as $size) {
                $expected[json_encode([$color, $size])] = true;
            }
        }
        if (($colors === [] && $sizes === []) || count($expected) > 400) {
            throw ValidationException::withMessages(['variants' => 'Use between 1 and 400 combinations with at least one option group.']);
        }
        $rows = [];
        foreach ($input['combinations'] as $index => $row) {
            $color = trim((string) ($row['color'] ?? '')) ?: null;
            $size = trim((string) ($row['size'] ?? '')) ?: null;
            $key = json_encode([$color, $size]);
            if (! isset($expected[$key])) {
                throw ValidationException::withMessages(['combinations.'.$index.'.color' => 'Each option combination must appear exactly once. Remove duplicate or unknown options.']);
            }
            unset($expected[$key]);
            $rows[] = ['color' => $color, 'size' => $size, 'stock' => (int) $row['stock'],
                ...($independent ? ['price' => number_format((float) $row['price'], 2, '.', ''), 'sku' => Str::upper(trim($row['sku']))]
                    : ['extra_price' => number_format((float) $row['extra_price'], 2, '.', '')])];
        }
        if ($expected !== [] || array_sum(array_column($rows, 'stock')) > 99999) {
            throw ValidationException::withMessages(['variants' => 'Provide stock for every combination. The combined stock cannot exceed 99,999 units.']);
        }

        return $rows;
    }

    public function assertRestorable(Product $product, ?array $variants): void
    {
        $open = $product->orderItems()->whereHas('order', fn ($order) => $order->whereNotIn('status', ['cancelled', 'completed', 'returned']))->get();
        if ($open->isEmpty()) {
            return;
        }
        $wasMatrix = isset($product->variants['combinations']);
        $isMatrix = isset($variants['combinations']);
        if ($wasMatrix !== $isMatrix) {
            throw ValidationException::withMessages(['variants' => 'Finish or cancel this product’s open orders before changing how its stock is tracked.']);
        }
        foreach ($open as $item) {
            if ($isMatrix) {
                $exists = collect($variants['combinations'])->contains(fn ($row) => $row['color'] === $item->color && $row['size'] === $item->size);
            } else {
                $exists = (! $item->color || collect($variants['colors'] ?? [])->contains('name', $item->color))
                    && (! $item->size || collect($variants['sizes'] ?? [])->contains('name', $item->size));
            }
            if (! $exists) {
                throw ValidationException::withMessages(['variants' => 'Keep options used by open orders until those orders finish or are cancelled.']);
            }
        }
    }
}
