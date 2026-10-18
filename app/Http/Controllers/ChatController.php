<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Shop;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Rules\ReadableChatImage;
use App\Services\BuyerAccessService;
use App\Services\Commerce\MarketplaceMessageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ChatController extends Controller
{
    public function getMessages(Request $request, int $receiverId): JsonResponse
    {
        $this->requireBuyerEligibility($request);
        abort_unless($request->user()->isBuyer() || $request->user()->isSeller(), 403);

        $receiver = app(MarketplaceMessageService::class)->reader($request->user(), $receiverId);
        $userId = $request->user()->id;

        $messages = Message::where(function ($q) use ($userId, $receiverId) {
            $q->where('sender_id', $userId)->where('receiver_id', $receiverId);
        })->orWhere(function ($q) use ($userId, $receiverId) {
            $q->where('sender_id', $receiverId)->where('receiver_id', $userId);
        })
            ->with(['sender:id,name,avatar', 'product:id,shop_id,name,slug,price,featured_image'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark incoming messages as read
        Message::where('sender_id', $receiverId)
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->whereIn('id', $messages->pluck('id'))
            ->update(['is_read' => true]);

        return response()->json([
            'messages' => $messages,
            'receiver' => $receiver->only(['id', 'name', 'avatar', 'role', 'shop']),
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
            'receiver_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'exists:users,id'],
            'shop_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'exists:shops,id'],
            'product_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'exists:products,id'],
            'order_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'min:1', 'exists:orders,id'],
            'message' => 'required_without:attachment|nullable|string|max:1000',
            'attachment' => ['bail', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120', new ReadableChatImage],
            'request_key' => 'nullable|uuid',
        ], ['message.required_without' => 'Type a message or choose a photo.', 'message.max' => 'Keep your message within 1,000 characters.',
            'attachment.max' => 'Each photo must be 5 MB or smaller.', 'attachment.image' => 'Choose a real JPG, PNG or WEBP image.',
            'attachment.mimes' => 'Choose a JPG, PNG or WEBP image.']);

        try {
            $result = app(MarketplaceMessageService::class)->send($request->user(), $validated, $request->file('attachment'));
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Marketplace message could not be saved.', ['sender_id' => $request->user()->id, 'exception' => $error::class]);

            return $request->wantsJson() ? response()->json(['success' => false, 'message' => 'Your message was not confirmed. Try again.'], 500)
                : back()->withErrors(['message' => 'Your message was not confirmed. Try again.']);
        }

        return $request->wantsJson()
            ? response()->json(['success' => true, ...$result])
            : back()->with('success', 'Message sent.');
    }

    public function attachment(Request $request, Message $message): StreamedResponse
    {
        $actor = app(MarketplaceMessageService::class)->actor($request->user());
        abort_unless(in_array($actor->id, [$message->sender_id, $message->receiver_id], true), 403);
        abort_unless($message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->response($message->attachment_path, 'message-image', [
            'Content-Type' => $message->attachment_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
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
                    'last_message' => $latest->message ?: ($latest->attachment_path ? 'Photo' : ''),
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
            ->with(['sender:id,name,avatar', 'receiver:id,name,avatar', 'product:id,shop_id,name,slug,price,featured_image'])
            ->latest()
            ->get()
            ->groupBy(function ($msg) use ($user) {
                return $msg->sender_id === $user->id ? $msg->receiver_id : $msg->sender_id;
            })
            ->map(function ($msgs, $otherUserId) {
                $otherUser = User::with('shop:id,user_id,name,slug,logo')->select(['id', 'name', 'avatar', 'role'])->find($otherUserId);
                $latest = $msgs->first();
                $unread = $msgs->where('receiver_id', auth()->id())->where('is_read', false)->count();

                return [
                    'user' => $otherUser,
                    'order_id' => $latest->order_id,
                    'last_message' => $latest->message ?: ($latest->attachment_path ? 'Photo' : ''),
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
        app(MarketplaceMessageService::class)->actor($user);
    }
}
