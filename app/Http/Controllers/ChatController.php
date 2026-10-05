<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function getMessages(Request $request, int $receiverId): JsonResponse
    {
        $this->requireBuyerEligibility($request);
        abort_unless($request->user()->isBuyer() || $request->user()->isSeller(), 403);

        $userId = $request->user()->id;

        $messages = Message::where(function ($q) use ($userId, $receiverId) {
            $q->where('sender_id', $userId)->where('receiver_id', $receiverId);
        })->orWhere(function ($q) use ($userId, $receiverId) {
            $q->where('sender_id', $receiverId)->where('receiver_id', $userId);
        })
            ->with(['sender', 'product'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark incoming messages as read
        Message::where('sender_id', $receiverId)
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $receiver = User::with('shop')->find($receiverId);

        return response()->json([
            'messages' => $messages,
            'receiver' => $receiver,
        ]);
    }

    public function sendMessage(Request $request): JsonResponse|RedirectResponse
    {
        $this->requireBuyerEligibility($request);
        abort_unless(
            $request->user()->isBuyer() || $request->user()->isSeller(),
            403,
            'Use the messaging workspace assigned to your account role.'
        );

        $validated = $request->validate([
            'receiver_id' => 'nullable|exists:users,id',
            'shop_id' => 'nullable|exists:shops,id',
            'product_id' => 'nullable|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
            'message' => 'required|string|max:1000',
        ]);

        // Auto-resolve merchant receiver and shop if missing
        if (empty($validated['receiver_id'])) {
            if (! empty($validated['product_id'])) {
                $prod = Product::with('shop')->find($validated['product_id']);
                if ($prod?->shop?->user_id) {
                    $validated['receiver_id'] = $prod->shop->user_id;
                    $validated['shop_id'] = $validated['shop_id'] ?? $prod->shop_id;
                }
            } elseif (! empty($validated['shop_id'])) {
                $shp = Shop::find($validated['shop_id']);
                if ($shp?->user_id) {
                    $validated['receiver_id'] = $shp->user_id;
                }
            }
        }

        if (empty($validated['receiver_id'])) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Merchant recipient could not be found.'], 422);
            }

            return back()->withErrors(['receiver_id' => 'Merchant recipient could not be found.']);
        }

        $receiver = User::findOrFail((int) $validated['receiver_id']);
        if ($receiver->isCourier()) {
            $this->authorizeCourierReply($request->user(), $receiver, $validated['order_id'] ?? null);
        } else {
            $this->authorizeMarketplaceMessage($request->user(), $receiver, $validated);
        }

        // Anti-spam safeguard: prevent duplicate rapid identical messages within 2 seconds
        $trimmedMessage = trim($validated['message']);
        $recentDuplicate = Message::where('sender_id', $request->user()->id)
            ->where('receiver_id', (int) $validated['receiver_id'])
            ->where('order_id', $validated['order_id'] ?? null)
            ->where('message', $trimmedMessage)
            ->where('created_at', '>=', now()->subSeconds(2))
            ->first();

        if ($recentDuplicate) {
            $recentDuplicate->load(['sender', 'product.shop']);
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $recentDuplicate,
                    'is_duplicate' => true,
                ]);
            }

            return back()->with('success', 'Message sent.');
        }

        $msg = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => (int) $validated['receiver_id'],
            'shop_id' => $validated['shop_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'message' => $trimmedMessage,
            'is_read' => false,
        ]);

        $msg->load(['sender', 'product.shop']);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        }

        return back()->with('success', 'Message sent.');
    }

    public function sellerInbox(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        // Group recent conversations
        $conversations = Message::where('receiver_id', $user->id)
            ->orWhere('sender_id', $user->id)
            ->with(['sender', 'receiver', 'product'])
            ->latest()
            ->get()
            ->groupBy(function ($msg) use ($user) {
                return $msg->sender_id === $user->id ? $msg->receiver_id : $msg->sender_id;
            })
            ->map(function ($msgs, $otherUserId) {
                $otherUser = User::find($otherUserId);
                $latest = $msgs->first();
                $unread = $msgs->where('receiver_id', auth()->id())->where('is_read', false)->count();

                return [
                    'user' => $otherUser,
                    'order_id' => $latest->order_id,
                    'last_message' => $latest->message,
                    'last_time' => $latest->created_at->diffForHumans(),
                    'unread_count' => $unread,
                    'messages' => $msgs->reverse()->values(),
                ];
            })
            ->values();

        return Inertia::render('Seller/Messages', [
            'conversations' => $conversations,
            'shop' => $shop,
        ]);
    }

    public function buyerInbox(Request $request): Response
    {
        $this->requireBuyerEligibility($request);
        $user = $request->user();
        abort_unless($user->isBuyer(), 403);

        // Group recent conversations for buyer
        $conversations = Message::where('receiver_id', $user->id)
            ->orWhere('sender_id', $user->id)
            ->with(['sender.shop', 'receiver.shop', 'product'])
            ->latest()
            ->get()
            ->groupBy(function ($msg) use ($user) {
                return $msg->sender_id === $user->id ? $msg->receiver_id : $msg->sender_id;
            })
            ->map(function ($msgs, $otherUserId) {
                $otherUser = User::with('shop')->find($otherUserId);
                $latest = $msgs->first();
                $unread = $msgs->where('receiver_id', auth()->id())->where('is_read', false)->count();

                return [
                    'user' => $otherUser,
                    'order_id' => $latest->order_id,
                    'last_message' => $latest->message,
                    'last_time' => $latest->created_at->diffForHumans(),
                    'unread_count' => $unread,
                    'messages' => $msgs->reverse()->values(),
                ];
            })
            ->values();

        return Inertia::render('Buyer/Messages', [
            'conversations' => $conversations,
        ]);
    }

    private function requireBuyerEligibility(Request $request): void
    {
        $user = app(BuyerAccessService::class)->current($request->user());
        $request->setUserResolver(fn () => $user);
        if ($user->isBuyer()) {
            app(BuyerAccessService::class)->requirePortal($user);
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

    private function authorizeMarketplaceMessage(User $sender, User $receiver, array $validated): void
    {
        $existingConversation = Message::query()
            ->where(function ($query) use ($sender, $receiver) {
                $query->where('sender_id', $sender->id)->where('receiver_id', $receiver->id);
            })
            ->orWhere(function ($query) use ($sender, $receiver) {
                $query->where('sender_id', $receiver->id)->where('receiver_id', $sender->id);
            })
            ->exists();

        $shop = ! empty($validated['shop_id']) ? Shop::find($validated['shop_id']) : null;
        $product = ! empty($validated['product_id'])
            ? Product::with('shop')->find($validated['product_id'])
            : null;

        $buyerMayContactSeller = $sender->isBuyer()
            && $receiver->isSeller()
            && ($existingConversation
                || $shop?->user_id === $receiver->id
                || $product?->shop?->user_id === $receiver->id);

        $sellerMayReplyToBuyer = $sender->isSeller()
            && $receiver->isBuyer()
            && $existingConversation;

        abort_unless(
            $buyerMayContactSeller || $sellerMayReplyToBuyer,
            403,
            'This marketplace conversation is not available to your account.'
        );
    }
}
