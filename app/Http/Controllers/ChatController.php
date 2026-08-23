<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function getMessages(Request $request, int $receiverId): JsonResponse
    {
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
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'shop_id' => 'nullable|exists:shops,id',
            'product_id' => 'nullable|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
            'message' => 'required|string|max:1000',
        ]);

        $msg = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $validated['receiver_id'],
            'shop_id' => $validated['shop_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'message' => trim($validated['message']),
            'is_read' => false,
        ]);

        $msg->load(['sender', 'product']);

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
}
