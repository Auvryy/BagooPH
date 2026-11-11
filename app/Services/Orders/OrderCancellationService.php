<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\OrderCancellationEvent;
use App\Models\OrderCancellationRequest;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\ShopEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderCancellationService
{
    private ?bool $schemaAvailable = null;

    public function available(): bool
    {
        return $this->schemaAvailable ??= Schema::hasTable('order_cancellation_requests') && Schema::hasTable('order_cancellation_events');
    }

    public function livePending(Order $order): ?OrderCancellationRequest
    {
        return $this->available() ? OrderCancellationRequest::where('active_order_id', $order->id)->where('status', 'pending')
            ->where('respond_by', '>', now())->first() : null;
    }

    public function submit(User $actor, Order $original, array $input): OrderCancellationRequest
    {
        abort_unless($this->available(), 503, 'Cancellation requests are being updated. Try again later.');
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'],
            'reason' => ['required', 'string', Rule::in(OrderLifecycleService::BUYER_CANCELLATION_REASONS)],
            'notes' => [Rule::requiredIf(($input['reason'] ?? null) === 'Other reason'), 'nullable', 'string', new ApplicationText('notes', 1, 500)],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'status' => ['prohibited'], 'respond_by' => ['prohibited'],
        ])->validate();
        $token = hash('sha256', $data['request_token']);
        $notes = $data['notes'] ?? null;
        $hash = hash('sha256', json_encode([$original->id, $actor->id, $data['reason'], $notes], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $notes, $token, $hash) {
            $order = $this->lockOrder($original->id);
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            abort_unless($order->buyer_id === $buyer->id, 403);
            if ($prior = OrderCancellationRequest::where('request_token_hash', $token)->first()) {
                abort_unless($prior->order_id === $order->id && $prior->buyer_id === $buyer->id && hash_equals($prior->request_fingerprint, $hash), 409,
                    'This cancellation request already recorded different details. Reload the order.');

                return $prior;
            }
            abort_if(OrderCancellationEvent::where('request_token_hash', $token)->exists(), 409,
                'This request token already belongs to a saved cancellation action.');
            $this->expireForOrder($order);
            abort_unless(! $this->livePending($order), 409, 'A cancellation request is already awaiting the seller’s response.');
            abort_unless(app(OrderLifecycleService::class)->canBuyerRequestCancellation($order, $buyer), 409,
                'This order cannot accept a cancellation request now. Refresh its current preparation and pickup status.');
            $sellers = $order->items->pluck('shop.user_id')->unique()->filter();
            abort_unless($sellers->count() === 1, 409, 'The original shop ownership needs review.');
            $request = OrderCancellationRequest::create(['order_id' => $order->id, 'active_order_id' => $order->id,
                'buyer_id' => $buyer->id, 'seller_id' => $sellers->first(), 'status' => 'pending', 'version' => 1,
                'reason' => $data['reason'], 'notes' => $notes, 'source_state' => $this->orderState($order),
                'request_token_hash' => $token, 'request_fingerprint' => $hash, 'respond_by' => now()->addHours(24)]);
            $this->event($request, $order, 'requested', $buyer, $data['reason'].($notes ? ': '.$notes : ''),
                ['request_status' => null, 'order' => $this->orderState($order)], $token, $hash);

            return $request;
        }, 3);
    }

    public function decide(User $actor, Order $original, OrderCancellationRequest $snapshot, string $decision, array $input): OrderCancellationRequest
    {
        abort_unless($this->available(), 503, 'Cancellation review is being updated. Try again later.');
        $data = Validator::make(array_replace($input, ['decision' => $decision]), ['decision' => ['required', Rule::in(['approve', 'reject'])],
            'request_token' => ['required', 'uuid'], 'expected_version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'],
            'decision_reason' => ['required', 'string', new ApplicationText('notes', 10, 1000)],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'status' => ['prohibited'], 'amount' => ['prohibited'],
        ])->validate();
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$original->id, $snapshot->id, $actor->id, $decision,
            (int) $data['expected_version'], $data['decision_reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $snapshot, $decision, $data, $token, $hash) {
            $order = $this->lockOrder($original->id);
            $seller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $shop = $order->items->first()?->shop;
            abort_unless($seller->isSeller() && $seller->canAccessPortal() && $shop?->user_id === $seller->id
                && $order->items->every(fn ($item) => $item->shop_id === $shop->id), 403);
            abort_unless(app(ShopEligibilityService::class)->isEligible($shop), 403);
            $request = OrderCancellationRequest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->order_id === $order->id && $request->seller_id === $seller->id && $request->buyer_id === $order->buyer_id, 404);
            if ($prior = OrderCancellationEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->cancellation_request_id === $request->id && $prior->actor_id === $seller->id
                    && hash_equals($prior->request_fingerprint, $hash), 409, 'This review request already saved different details.');

                return $request;
            }
            $latest = $request->events()->reorder('version', 'desc')->first();
            abort_unless($latest && $latest->version === $request->version && ($latest->target_state['request_status'] ?? null) === $request->status, 409, 'The retained cancellation decision needs review.');
            abort_unless($request->status === 'pending' && $request->active_order_id === $order->id
                && $request->version === (int) $data['expected_version'], 409, 'This cancellation request changed. Reload its saved decision.');
            abort_unless(now()->lessThan($request->respond_by), 409, 'The 24-hour response window has expired. The order is unchanged.');
            abort_unless($request->source_state === $this->orderState($order), 409, 'The order or its custody changed. Review its current status before responding.');
            $before = ['request_status' => $request->status, 'order' => $this->orderState($order)];
            $request->fill(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'version' => $request->version + 1,
                'active_order_id' => null, 'closed_at' => now()])->save();
            if ($decision === 'approve') {
                app(OrderLifecycleService::class)->cancelBySeller($order, $shop, $seller,
                    ['reason' => 'Other reason', 'notes' => 'Approved request '.$request->reference.': '.$request->reason.($request->notes ? ': '.$request->notes : '')]);
                $order = $this->lockOrder($order->id);
            }
            $this->event($request, $order, $request->status, $seller, $data['decision_reason'], $before, $token, $hash);

            return $request;
        }, 3);
    }

    public function expireForOrder(Order $order): bool
    {
        if (! $this->available()) {
            return false;
        }
        abort_unless(DB::transactionLevel() > 0, 409);
        $request = OrderCancellationRequest::where('active_order_id', $order->id)->where('status', 'pending')->lockForUpdate()->first();
        if ($request && now()->greaterThanOrEqualTo($request->respond_by)) {
            $this->close($request, $order, 'expired', null, 'The 24-hour response window expired. The order and its payment are unchanged.');

            return true;
        }

        return false;
    }

    public function closeForOrder(Order $order, User $actor): void
    {
        if (! $this->available()) {
            return;
        }
        abort_unless(DB::transactionLevel() > 0, 409);
        $request = OrderCancellationRequest::where('active_order_id', $order->id)->where('status', 'pending')->lockForUpdate()->first();
        if ($request) {
            $this->close($request, $order, 'closed', $actor, 'The order was cancelled through its retained cancellation action.');
        }
    }

    private function close(OrderCancellationRequest $request, Order $order, string $status, ?User $actor, string $reason): void
    {
        $latest = $request->events()->reorder('version', 'desc')->first();
        abort_unless($latest && $latest->version === $request->version && ($latest->target_state['request_status'] ?? null) === $request->status, 409, 'The retained cancellation decision needs review.');
        $before = ['request_status' => $request->status, 'order' => $this->orderState($order)];
        $request->fill(['status' => $status, 'active_order_id' => null, 'closed_at' => now(), 'version' => $request->version + 1])->save();
        $this->event($request, $order, $status, $actor, $reason, $before);
    }

    private function event(OrderCancellationRequest $request, Order $order, string $type, ?User $actor, string $reason, array $before, ?string $token = null, ?string $hash = null): void
    {
        $event = $request->events()->create(['version' => $request->version, 'event_type' => $type, 'actor_id' => $actor?->id,
            'reason' => $reason, 'source_state' => $before, 'target_state' => ['request_status' => $request->status, 'order' => $this->orderState($order)],
            'request_token_hash' => $token, 'request_fingerprint' => $hash]);
        app(LifecycleNoticeService::class)->cancellationRequest($request, $event);
    }

    private function lockOrder(int $id): Order
    {
        $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
        $order->setRelation('delivery', Delivery::where('order_id', $id)->lockForUpdate()->first());

        return $order->load('items.shop');
    }

    private function orderState(Order $order): array
    {
        return $order->delivery ? [...DeliveryCheckpoint::state($order->delivery->setRelation('order', $order)),
            'custody_checkpoint_id' => $order->delivery->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->value('id')]
            : ['order_status' => $order->status, 'delivery_status' => null];
    }

    public function present(Order $order, ?OrderCancellationRequest $request = null): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $request ??= OrderCancellationRequest::with('events')->where('order_id', $order->id)->latest('id')->first();
        if (! $request) {
            return null;
        }
        abort_unless($request->order_id === $order->id, 404);
        $request->loadMissing('events');
        $expired = $request->status === 'pending' && now()->greaterThanOrEqualTo($request->respond_by);
        $event = $request->events->last();
        $consistent = $event && $event->version === $request->version && ($event->target_state['request_status'] ?? null) === $request->status;

        return ['id' => $request->id, 'reference' => $request->reference, 'version' => $request->version,
            'status' => ! $consistent ? 'review_required' : ($expired ? 'expired' : $request->status),
            'reason' => $request->reason, 'notes' => $request->notes, 'respond_by' => $request->respond_by->toISOString(),
            'created_at' => $request->created_at->toISOString(), 'decision_reason' => $request->status === 'pending' ? null : $event?->reason,
            'can_review' => $consistent && ! $expired && $request->status === 'pending' && $request->source_state === $this->orderState($order)];
    }

    public function expireDue(int $limit = 100): int
    {
        if (! $this->available()) {
            return 0;
        }
        $ids = OrderCancellationRequest::where('status', 'pending')->where('respond_by', '<=', now())->orderBy('id')->limit($limit)->pluck('order_id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += (int) DB::transaction(fn () => $this->expireForOrder($this->lockOrder($id)), 3);
        }

        return $expired;
    }
}
