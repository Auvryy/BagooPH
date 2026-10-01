<?php

namespace App\Services\Commerce;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use RuntimeException;

class InventoryService
{
    public const MAX_CART_LINE_QUANTITY = 99;

    /**
     * @param  Collection<int, CartItem>  $productCartItems
     */
    public function maximumCartLineQuantity(
        Product $product,
        Collection $productCartItems,
        ?string $color,
        ?string $size,
        ?int $currentItemId = null
    ): int {
        $selection = $this->selection($product, $color, $size);
        $otherItems = $productCartItems->reject(
            fn (CartItem $item) => $currentItemId !== null && $item->id === $currentItemId
        );

        $remainingProductStock = $product->stock - $otherItems->sum('quantity');
        $remainingSelectionStock = $selection['stock'];

        if ($selection['size'] !== null) {
            $remainingSelectionStock -= $otherItems
                ->filter(fn (CartItem $item) => $item->size === $selection['size'])
                ->sum('quantity');
        }

        return max(0, min(
            self::MAX_CART_LINE_QUANTITY,
            $remainingProductStock,
            $remainingSelectionStock
        ));
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  Collection<int, Product>  $products
     */
    public function assertCheckoutAvailability(Collection $items, Collection $products): void
    {
        foreach ($items as $item) {
            if ($item->quantity < 1 || $item->quantity > self::MAX_CART_LINE_QUANTITY) {
                throw new RuntimeException('Shopping Bag quantities must be whole numbers between 1 and 99.');
            }

            $product = $products->get($item->product_id);
            if (! $product) {
                throw new RuntimeException('One or more selected products are unavailable.');
            }

            $this->selection($product, $item->color, $item->size);
        }

        foreach ($items->groupBy('product_id') as $productId => $productItems) {
            $product = $products->get($productId);
            $requestedProductQuantity = $productItems->sum('quantity');

            if ($requestedProductQuantity > $product->stock) {
                throw new RuntimeException(
                    "'{$product->name}' has only {$product->stock} units available for the selected Shopping Bag items."
                );
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
        if (is_array($variants) && $selection['size'] !== null) {
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

    public function restore(Product $product, int $quantity, ?string $size): void
    {
        $variants = $product->variants;
        if (is_array($variants) && $size !== null && $this->sizes($product) !== []) {
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

        return [
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
