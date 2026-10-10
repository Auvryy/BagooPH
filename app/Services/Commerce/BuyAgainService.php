<?php

namespace App\Services\Commerce;

use App\Models\BuyAgainSubmission;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\ShopEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BuyAgainService
{
    private function ownedCompleted(User $actor, Order $order, bool $lock = false): Order
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $query = Order::whereKey($order->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($current->buyer_id === $buyer->id, 403);
        abort_unless($current->status === 'completed', 409, 'Buy again is available after you confirm receipt and complete this order.');

        return $current;
    }

    public function preview(User $actor, Order $order): array
    {
        $order = $this->ownedCompleted($actor, $order);
        $items = $order->items()->orderBy('id')->get();
        $products = app(BuyerDiscoveryService::class)->catalogue()->whereIn('products.id', $items->pluck('product_id'))->get()->keyBy('id');
        $cart = Cart::where('user_id', $actor->id)->first();
        $bagItems = $cart?->items()->whereIn('product_id', $items->pluck('product_id'))->get() ?? collect();
        $rows = $items->map(function ($original) use ($products, $bagItems) {
            $product = $products->get($original->product_id);
            if ($product && $product->shop_id !== $original->shop_id) {
                $product = null;
            }
            $maximum = 0;
            $reason = 'This listing is currently unavailable.';
            if ($product) {
                $productItems = $bagItems->where('product_id', $product->id);
                $matching = $productItems->first(fn ($line) => $line->color === $original->color && $line->size === $original->size);
                try {
                    $limit = app(InventoryService::class)->maximumCartLineQuantity($product, $productItems, $original->color, $original->size, $matching?->id);
                    $maximum = max(0, $limit - ($matching?->quantity ?? 0));
                    $reason = $product->stock <= 0 ? 'This product is currently out of stock.' : 'Your Bag already contains the available quantity for this item.';
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }
            }

            return [
                'order_item_id' => $original->id, 'original_quantity' => $original->quantity,
                'previous_unit_price' => $original->unit_price, 'current_unit_price' => $product?->price,
                'color' => $original->color, 'size' => $original->size,
                'name' => $product?->name ?? $original->sku_snapshot ?? 'Purchased item',
                'product_url' => $product ? route('buyer.products.show', $product->slug, absolute: false) : null,
                'image' => $product?->featured_image,
                'maximum_quantity' => $maximum, 'can_select' => $maximum > 0,
                'unavailable_reason' => $maximum > 0 ? null : $reason,
            ];
        })->all();
        $nonce = Str::random(64);

        return ['order' => $order->only(['id', 'order_number']), 'items' => $rows,
            'requestToken' => 'v1.'.$nonce.'.'.$this->signature($actor, $order, $nonce)];
    }

    public function add(User $actor, Order $order, array $input): array
    {
        $order = $this->ownedCompleted($actor, $order);
        $data = Validator::make($input, [
            'request_token' => ['bail', 'required', 'string', 'size:132', 'regex:/\Av1\.[A-Za-z0-9]{64}\.[a-f0-9]{64}\z/'],
            'items' => ['required', 'array', 'list', 'min:1'],
            'items.*.order_item_id' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'distinct'],
            'items.*.quantity' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:99'],
            'items.*.expected_unit_price' => ['bail', 'required', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0.01,99999999.99'],
        ])->validate();
        $tokenHash = hash('sha256', $data['request_token']);
        $retained = BuyAgainSubmission::where('token_hash', $tokenHash)->where('buyer_id', $actor->id)->where('order_id', $order->id)->exists();
        [, $nonce, $signature] = explode('.', $data['request_token']);
        if (! $retained && ! hash_equals($this->signature($actor, $order, $nonce), $signature)) {
            throw ValidationException::withMessages(['request_token' => 'Open Buy again from your completed purchase before adding items.']);
        }
        $selection = collect($data['items'])->map(fn ($row) => [
            'order_item_id' => (int) $row['order_item_id'], 'quantity' => (int) $row['quantity'],
            'expected_unit_price' => number_format((float) $row['expected_unit_price'], 2, '.', ''),
        ])->sortBy('order_item_id')->values()->all();
        $requestHash = hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
        $cart = app(CartLineService::class)->ownedCart($actor);

        return DB::transaction(function () use ($actor, $order, $cart, $tokenHash, $requestHash, $selection) {
            $cart = Cart::whereKey($cart->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $order = $this->ownedCompleted($actor, $order, lock: true);
            $previous = BuyAgainSubmission::where('token_hash', $tokenHash)->first();
            if ($previous) {
                app(BuyerAccessService::class)->requirePortal($actor, lock: true);
                abort_unless($previous->buyer_id === $actor->id && $previous->order_id === $order->id && $previous->cart_id === $cart->id, 403);
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'This Buy again request was already used with different items. Open a new preview.');

                return ['result' => $previous->result, 'replayed' => true];
            }
            $originals = $order->items()->whereIn('id', array_column($selection, 'order_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($originals->count() !== count($selection)) {
                throw ValidationException::withMessages(['items' => 'Choose only items from this completed purchase.']);
            }
            try {
                $products = app(ShopEligibilityService::class)->lockSaleProducts($originals->pluck('product_id')->all(), [$actor->id]);
            } catch (RuntimeException $exception) {
                app(BuyerAccessService::class)->requirePortal($actor);
                throw ValidationException::withMessages(['items' => 'One or more selected listings are unavailable. Nothing was added. Open the preview to review your selection.']);
            }
            app(BuyerAccessService::class)->requirePortal($actor);
            $added = [];
            foreach ($selection as $selected) {
                $original = $originals->get($selected['order_item_id']);
                $product = $products->get($original->product_id);
                if (! $product || $product->shop_id !== $original->shop_id) {
                    throw ValidationException::withMessages(['items' => 'One or more purchased items are no longer offered by the original shop. Nothing was added.']);
                }
                if ($selected['expected_unit_price'] !== $product->price) {
                    throw ValidationException::withMessages(['items' => 'A price changed after your preview. Nothing was added. Open Buy again to review the current prices.']);
                }
                try {
                    $line = app(CartLineService::class)->addLocked($cart, $product, $selected['quantity'], $original->color, $original->size);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['items' => collect($exception->errors())->flatten()->implode(' ').' Nothing was added.']);
                }
                $added[] = ['order_item_id' => $original->id, 'cart_item_id' => $line->id, 'product_id' => $product->id,
                    'added_quantity' => $selected['quantity'], 'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price, 'color' => $line->color, 'size' => $line->size];
            }
            $quantities = $cart->items()->whereIn('id', array_column($added, 'cart_item_id'))->pluck('quantity', 'id');
            $added = array_map(fn ($row) => [...$row, 'quantity' => $quantities->get($row['cart_item_id'])], $added);
            $result = ['order_id' => $order->id, 'cart_id' => $cart->id, 'items' => $added];
            BuyAgainSubmission::create(['buyer_id' => $actor->id, 'order_id' => $order->id, 'cart_id' => $cart->id,
                'token_hash' => $tokenHash, 'request_hash' => $requestHash, 'result' => $result, 'created_at' => now()]);

            return ['result' => $result, 'replayed' => false];
        });
    }

    private function signature(User $actor, Order $order, string $nonce): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages(['request_token' => 'Buy again is temporarily unavailable. Please try later.']);
        }

        return hash_hmac('sha256', 'buyer-buy-again:v1:'.$actor->id.':'.$order->id.':'.$nonce, $key);
    }
}
