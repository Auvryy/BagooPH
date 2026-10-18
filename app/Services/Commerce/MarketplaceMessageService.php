<?php

namespace App\Services\Commerce;

use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MarketplaceMessageService
{
    public function actor(User $actor, bool $lock = false): User
    {
        $user = app(BuyerAccessService::class)->current($actor, $lock);
        abort_unless(($user->isBuyer() || $user->isSeller()) && $user->canAccessPortal(), 403, 'An active approved buyer or seller account is required.');

        return $user;
    }

    public function reader(User $actor, int $otherId): User
    {
        $actor = $this->actor($actor);
        $other = User::with('shop:id,user_id,name,slug,logo')->findOrFail($otherId);
        $pair = ($actor->isBuyer() && ($other->isSeller() || $other->isCourier()))
            || ($actor->isSeller() && ($other->isBuyer() || $other->isCourier()));
        $existing = $this->conversation($actor->id, $otherId)->exists();
        abort_unless($pair && ($existing || ($actor->isBuyer() && $other->isSeller() && Shop::eligible()->where('user_id', $otherId)->exists())), 403, 'This conversation is not available to your account.');

        return $other;
    }

    private function conversation(int $one, int $two)
    {
        return Message::where(fn ($query) => $query->where('sender_id', $one)->where('receiver_id', $two))
            ->orWhere(fn ($query) => $query->where('sender_id', $two)->where('receiver_id', $one));
    }

    public function send(User $actor, array $data, ?UploadedFile $image = null): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $data, $image, &$path) {
                $sender = $this->actor($actor, lock: true);
                if (isset($data['request_key'])) {
                    $data['request_key'] = strtolower($data['request_key']);
                }
                if (empty($data['receiver_id'])) {
                    $product = ! empty($data['product_id']) ? Product::with('shop')->find($data['product_id']) : null;
                    $shop = $product?->shop ?? (! empty($data['shop_id']) ? Shop::find($data['shop_id']) : null);
                    $data['receiver_id'] = $shop?->user_id;
                    $data['shop_id'] ??= $shop?->id;
                }
                if (empty($data['receiver_id'])) {
                    throw ValidationException::withMessages(['receiver_id' => 'Choose the shop you want to message.']);
                }
                $receiver = User::findOrFail($data['receiver_id']);
                $text = trim($data['message'] ?? '');
                $hash = $image ? hash_file('sha256', $image->getRealPath()) : null;
                if (! empty($data['request_key']) && ($saved = Message::where('sender_id', $sender->id)->where('request_key', $data['request_key'])->first())) {
                    abort_unless($saved->receiver_id === $receiver->id && $saved->message === $text && $saved->attachment_hash === $hash
                        && (int) $saved->shop_id === (int) ($data['shop_id'] ?? 0)
                        && (int) $saved->product_id === (int) ($data['product_id'] ?? 0)
                        && (int) $saved->order_id === (int) ($data['order_id'] ?? 0), 409, 'This send request was already used. Start a new message.');

                    return ['message' => $saved->load(['sender:id,name,avatar', 'product:id,shop_id,name,slug,price,featured_image', 'product.shop:id,user_id,name,slug,logo']), 'is_duplicate' => true];
                }
                if ($receiver->isCourier()) {
                    $this->authorizeCourierReply($sender, $receiver, $data['order_id'] ?? null);
                    if ($image) {
                        throw ValidationException::withMessages(['attachment' => 'Photos are currently supported in buyer and seller conversations.']);
                    }
                } else {
                    $this->authorizeMarketplaceMessage($sender, $receiver, $data);
                }
                $existing = empty($data['request_key']) ? Message::where('sender_id', $sender->id)->where('receiver_id', $receiver->id)
                    ->where('order_id', $data['order_id'] ?? null)->where('product_id', $data['product_id'] ?? null)
                    ->where('message', $text)->where('attachment_hash', $hash)
                    ->where('created_at', '>=', now()->subSeconds(2))->first() : null;
                if ($existing) {
                    return ['message' => $existing->load(['sender:id,name,avatar', 'product:id,shop_id,name,slug,price,featured_image', 'product.shop:id,user_id,name,slug,logo']), 'is_duplicate' => true];
                }
                $limitKey = 'marketplace-message:'.$sender->id;
                abort_if(RateLimiter::tooManyAttempts($limitKey, 60), 429, 'Too many messages. Please wait a minute and try again.');
                RateLimiter::hit($limitKey, 60);
                if ($image) {
                    $path = $image->store('chat-images', 'local');
                    if (! $path) {
                        throw new RuntimeException('Message image storage failed.');
                    }
                }
                $message = Message::create(['sender_id' => $sender->id, 'receiver_id' => $receiver->id,
                    'shop_id' => $data['shop_id'] ?? null, 'product_id' => $data['product_id'] ?? null,
                    'order_id' => $data['order_id'] ?? null, 'message' => $text, 'is_read' => false, 'request_key' => $data['request_key'] ?? null,
                    'attachment_path' => $path, 'attachment_hash' => $hash,
                    'attachment_mime' => $image?->getMimeType(), 'attachment_size' => $image?->getSize()]);

                return ['message' => $message->load(['sender:id,name,avatar', 'product:id,shop_id,name,slug,price,featured_image', 'product.shop:id,user_id,name,slug,logo']), 'is_duplicate' => false];
            });
        } catch (Throwable $error) {
            // A response failure after commit must not delete a saved message's image.
            if ($path && ! Message::where('attachment_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function authorizeMarketplaceMessage(User $sender, User $receiver, array $data): void
    {
        $buyer = $sender->isBuyer() ? $sender : $receiver;
        $seller = $sender->isSeller() ? $sender : $receiver;
        abort_unless($buyer->isBuyer() && $seller->isSeller(), 403, 'Choose a buyer or seller conversation.');
        $existing = $this->conversation($sender->id, $receiver->id)->exists();
        $shop = ! empty($data['shop_id']) ? Shop::findOrFail($data['shop_id']) : null;
        $product = ! empty($data['product_id']) ? Product::with('shop')->findOrFail($data['product_id']) : null;
        abort_if(($shop && $shop->user_id !== $seller->id) || ($product && $product->shop?->user_id !== $seller->id)
            || ($shop && $product && $product->shop_id !== $shop->id), 403, 'The selected product and shop do not belong to this conversation.');
        $ownedOrder = false;
        if (! empty($data['order_id'])) {
            $order = Order::with('items.shop')->findOrFail($data['order_id']);
            $ownedOrder = $order->buyer_id === $buyer->id && $order->items->isNotEmpty()
                && $order->items->every(fn ($item) => $item->shop?->user_id === $seller->id);
            abort_unless($ownedOrder && (! $product || $order->items->contains('product_id', $product->id))
                && (! $shop || $order->items->contains('shop_id', $shop->id)), 403, 'This order does not belong to this conversation.');
        }
        if ($product && ! $ownedOrder) {
            abort_unless(Product::availableForSale()->whereKey($product->id)->exists(), 403, 'This product is currently unavailable for a new inquiry.');
        }
        if ($sender->isSeller()) {
            abort_unless($existing, 403, 'A seller can reply to an existing buyer conversation.');
        } else {
            abort_unless($existing || $ownedOrder || ($product && Product::availableForSale()->whereKey($product->id)->exists())
                || ($shop && Shop::eligible()->whereKey($shop->id)->exists()), 403, 'This shop is currently unavailable for a new inquiry.');
        }
    }

    private function authorizeCourierReply(User $sender, User $courier, mixed $orderId): void
    {
        abort_if(! $orderId, 403, 'A courier message must be linked to an assigned delivery.');

        $order = Order::with(['delivery', 'items.product.shop'])->findOrFail($orderId);
        $delivery = $order->delivery;
        abort_if(! $delivery, 403, 'This order has no delivery assignment.');

        $buyerMayReply = $sender->isBuyer()
            && $order->buyer_id === $sender->id
            && $delivery->assigned_rider_id === $courier->id
            && in_array($delivery->status, ['assigned_to_rider', 'out_for_delivery'], true);

        $sellerOwnsOrder = $sender->isSeller()
            && $order->items->isNotEmpty()
            && $order->items->every(fn ($item) => $item->product?->shop?->user_id === $sender->id);
        $sellerMayReply = $sellerOwnsOrder
            && $delivery->courier_id === $courier->id
            && in_array($delivery->status, ['assigned', 'assigned_pickup', 'picked_up'], true);

        abort_unless(
            $buyerMayReply || $sellerMayReply,
            403,
            'This courier is not assigned to your active order.'
        );
    }
}
