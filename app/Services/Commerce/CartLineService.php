<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CartLineService
{
    public function ownedCart(User $actor, ?string $sessionId = null): Cart
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $cart = Cart::where('user_id', $buyer->id)->first();
        if ($cart) {
            return $cart;
        }

        // Serialize first-cart creation without holding a user lock while waiting on an existing Bag.
        return DB::transaction(function () use ($actor, $sessionId) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);

            return Cart::firstOrCreate(['user_id' => $buyer->id], ['session_id' => $sessionId]);
        });
    }

    /** The caller holds the Bag and eligible product locks inside its transaction. */
    public function addLocked(Cart $cart, Product $product, int $quantity, ?string $color, ?string $size): CartItem
    {
        Validator::make(['quantity' => $quantity], ['quantity' => ['bail', new AsciiPositiveInteger, 'integer', 'min:1', 'max:99']])->validate();
        $color = trim((string) $color);
        $size = trim((string) $size);
        $color = $color === '' ? null : $color;
        $size = $size === '' ? null : $size;
        if ($product->stock <= 0) {
            throw ValidationException::withMessages(['quantity' => 'This product is currently out of stock.']);
        }
        $items = $cart->items()->where('product_id', $product->id)->lockForUpdate()->get();
        $item = $items->first(fn (CartItem $line) => $line->color === $color && $line->size === $size);
        $newQuantity = ($item?->quantity ?? 0) + $quantity;
        try {
            $maximum = app(InventoryService::class)->maximumCartLineQuantity($product, $items, $color, $size, $item?->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }
        if ($newQuantity > $maximum) {
            $existing = $item?->quantity ?? 0;
            $remaining = max(0, $maximum - $existing);
            throw ValidationException::withMessages(['quantity' => $remaining > 0
                ? "You already have {$existing} in your Shopping Bag. You can add only {$remaining} more."
                : "Your Shopping Bag already contains the maximum available quantity of {$maximum}."]);
        }
        if ($item) {
            $item->update(['quantity' => $newQuantity, 'unit_price' => $product->price]);

            return $item;
        }

        return $cart->items()->create([
            'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $product->price,
            'color' => $color, 'size' => $size,
            'sku_snapshot' => $product->sku.($color ? "-{$color}" : '').($size ? "-{$size}" : ''),
        ]);
    }
}
