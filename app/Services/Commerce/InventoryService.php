<?php

namespace App\Services\Commerce;

use App\Models\CartItem;
use App\Models\Product;
use App\Services\Finance\CodMoney;
use Illuminate\Support\Collection;
use RuntimeException;

class InventoryService
{
    public const MAX_CART_LINE_QUANTITY = 99;

    public function unitPrice(Product $product, ?string $color, ?string $size): string
    {
        return app(SalePriceService::class)->unitPrice($product, $this->regularUnitPrice($product, $color, $size));
    }

    public function regularUnitPrice(Product $product, ?string $color, ?string $size): string
    {
        $selection = $this->selection($product, $color, $size);
        if ($selection['price'] !== null) {
            return number_format(CodMoney::cents($selection['price']) / 100, 2, '.', '');
        }
        $extra = $selection['extra_price'];
        if (! is_numeric($extra) || ! is_finite((float) $extra) || (float) $extra < 0 || (float) $extra > 99999999.99) {
            throw new RuntimeException("The price for '{$product->name}' needs to be corrected by its shop.");
        }
        $cents = CodMoney::cents($product->price) + CodMoney::cents(number_format((float) $extra, 2, '.', ''));

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param  Collection<int, CartItem>  $productCartItems
     */
    public function maximumCartLineQuantity(
        Product $product,
        Collection $productCartItems,
        ?string $color,
        ?string $size,
        ?int $currentItemId = null,
        ?int $buyerId = null
    ): int {
        app(PurchasePolicyService::class)->assertShopAccepting($product);
        $selection = $this->selection($product, $color, $size);
        $otherItems = $productCartItems->reject(
            fn (CartItem $item) => $currentItemId !== null && $item->id === $currentItemId
        );

        $remainingProductStock = $product->stock - $otherItems->sum('quantity');
        $remainingSelectionStock = $selection['stock'];

        if (isset($product->variants['combinations'])) {
            $remainingSelectionStock -= $otherItems->filter(fn (CartItem $item) => $item->color === $selection['color'] && $item->size === $selection['size'])->sum('quantity');
        } elseif ($selection['size'] !== null) {
            $remainingSelectionStock -= $otherItems
                ->filter(fn (CartItem $item) => $item->size === $selection['size'])
                ->sum('quantity');
        }

        return max(0, min(
            self::MAX_CART_LINE_QUANTITY,
            $remainingProductStock,
            (app(PurchasePolicyService::class)->remaining($product, $buyerId) ?? PHP_INT_MAX) - $otherItems->sum('quantity'),
            $remainingSelectionStock
        ));
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  Collection<int, Product>  $products
     */
    public function assertCheckoutAvailability(Collection $items, Collection $products, ?int $buyerId = null): void
    {
        foreach ($items as $item) {
            if ($item->quantity < 1 || $item->quantity > self::MAX_CART_LINE_QUANTITY) {
                throw new RuntimeException('Shopping Bag quantities must be whole numbers between 1 and 99.');
            }

            $product = $products->get($item->product_id);
            if (! $product) {
                throw new RuntimeException('One or more selected products are unavailable.');
            }

            $this->unitPrice($product, $item->color, $item->size);
        }

        foreach ($items->groupBy('product_id') as $productId => $productItems) {
            $product = $products->get($productId);
            $requestedProductQuantity = $productItems->sum('quantity');
            app(PurchasePolicyService::class)->assertShopAccepting($product);
            app(PurchasePolicyService::class)->assertQuantity($product, $buyerId, $requestedProductQuantity);

            if ($requestedProductQuantity > $product->stock) {
                throw new RuntimeException(
                    "'{$product->name}' has only {$product->stock} units available for the selected Shopping Bag items."
                );
            }

            if (isset($product->variants['combinations'])) {
                foreach ($productItems->groupBy(fn ($item) => json_encode([$item->color, $item->size])) as $rows) {
                    $first = $rows->first();
                    $stock = $this->selection($product, $first->color, $first->size)['stock'];
                    if ($rows->sum('quantity') > $stock) {
                        throw new RuntimeException("The selected options for '{$product->name}' have only {$stock} units available.");
                    }
                }

                continue;
            }
            $sizes = $this->sizes($product);
            if ($sizes === []) {
                continue;
            }

            foreach ($productItems->groupBy('size') as $size => $sizeItems) {
                $selectedSize = collect($sizes)->firstWhere('name', $size);
                $available = (int) ($selectedSize['stock'] ?? 0);
                $requested = $sizeItems->sum('quantity');

                if ($requested > $available) {
                    throw new RuntimeException(
                        "'{$product->name}' in {$size} has only {$available} units available."
                    );
                }
            }
        }
    }

    public function decrement(Product $product, int $quantity, ?string $color, ?string $size): void
    {
        $selection = $this->selection($product, $color, $size);
        if ($quantity < 1 || $quantity > $selection['stock'] || $quantity > $product->stock) {
            throw new RuntimeException("'{$product->name}' does not have enough stock.");
        }

        $variants = $product->variants;
        if (isset($variants['combinations'])) {
            foreach ($variants['combinations'] as &$row) {
                if ($row['color'] === $selection['color'] && $row['size'] === $selection['size']) {
                    $row['stock'] -= $quantity;
                    break;
                }
            }
            unset($row);
            $product->variants = $variants;
        } elseif (is_array($variants) && $selection['size'] !== null) {
            foreach ($variants['sizes'] as &$variantSize) {
                if (($variantSize['name'] ?? null) === $selection['size']) {
                    $variantSize['stock'] = (int) $variantSize['stock'] - $quantity;
                    break;
                }
            }
            unset($variantSize);
            $product->variants = $variants;
        }

        $product->stock -= $quantity;
        $product->sales_count += $quantity;
        $product->save();
    }

    public function restore(Product $product, int $quantity, ?string $size, ?string $color = null): void
    {
        $variants = $product->variants;
        if (isset($variants['combinations'])) {
            $found = false;
            foreach ($variants['combinations'] as &$row) {
                if ($row['color'] === $color && $row['size'] === $size) {
                    $row['stock'] += $quantity;
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (! $found) {
                throw new RuntimeException('The original product options must be restored before this order can be cancelled.');
            }
            $product->variants = $variants;
        } elseif (is_array($variants) && $size !== null && $this->sizes($product) !== []) {
            foreach ($variants['sizes'] as &$variantSize) {
                if (($variantSize['name'] ?? null) === $size) {
                    $variantSize['stock'] = (int) ($variantSize['stock'] ?? 0) + $quantity;
                    break;
                }
            }
            unset($variantSize);
            $product->variants = $variants;
        }

        $product->stock += $quantity;
        $product->sales_count = max(0, $product->sales_count - $quantity);
        $product->save();
    }

    /**
     * @return array{color: ?string, size: ?string, stock: int}
     */
    private function selection(Product $product, ?string $color, ?string $size): array
    {
        $color = $this->normalizeOption($color);
        $size = $this->normalizeOption($size);
        $colors = $this->colors($product);
        $sizes = $this->sizes($product);

        if ($colors !== []) {
            if ($color === null) {
                throw new RuntimeException("Choose a color or edition for '{$product->name}'.");
            }

            $selectedColor = collect($colors)->firstWhere('name', $color);
            if (! $selectedColor || ! ($selectedColor['in_stock'] ?? true)) {
                throw new RuntimeException("The selected color or edition for '{$product->name}' is unavailable.");
            }
        } elseif ($color !== null) {
            throw new RuntimeException("'{$product->name}' does not offer the selected color or edition.");
        }

        $available = $product->stock;
        if ($sizes !== []) {
            if ($size === null) {
                throw new RuntimeException("Choose a size or specification for '{$product->name}'.");
            }

            $selectedSize = collect($sizes)->firstWhere('name', $size);
            if (! $selectedSize) {
                throw new RuntimeException("The selected size or specification for '{$product->name}' is unavailable.");
            }

            $available = min($available, (int) ($selectedSize['stock'] ?? 0));
        } elseif ($size !== null) {
            throw new RuntimeException("'{$product->name}' does not offer the selected size or specification.");
        }

        $extra = $selectedSize['extra_price'] ?? 0;
        $price = null;
        if (isset($product->variants['combinations'])) {
            $row = collect($product->variants['combinations'])->first(fn ($row) => ($row['color'] ?? null) === $color && ($row['size'] ?? null) === $size);
            if (! $row) {
                throw new RuntimeException("The selected options for '{$product->name}' are unavailable.");
            }
            $available = min($product->stock, (int) $row['stock']);
            $extra = $row['extra_price'] ?? 0;
            if (($product->variants['pricing_mode'] ?? null) === 'independent') {
                $price = $row['price'] ?? null;
                if (! is_string($price) && ! is_numeric($price) || ! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string) $price)
                    || CodMoney::cents($price) < 100 || CodMoney::cents($price) > 100000000) {
                    throw new RuntimeException("The price for '{$product->name}' needs to be corrected by its shop.");
                }
            }
        }

        return [
            'extra_price' => $extra,
            'price' => $price,
            'color' => $color,
            'size' => $size,
            'stock' => max(0, $available),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function colors(Product $product): array
    {
        $colors = $product->variants['colors'] ?? [];

        return is_array($colors) ? array_values($colors) : [];
    }

    /** @return list<array<string, mixed>> */
    private function sizes(Product $product): array
    {
        $sizes = $product->variants['sizes'] ?? [];

        return is_array($sizes) ? array_values($sizes) : [];
    }

    private function normalizeOption(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
