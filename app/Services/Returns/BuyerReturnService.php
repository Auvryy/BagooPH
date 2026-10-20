<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnEvidence;
use App\Models\BuyerReturnItem;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuyerReturnService
{
    public const REASONS = ['damaged' => 'Item damaged', 'wrong_item' => 'Wrong item',
        'missing_parts' => 'Missing parts', 'not_as_described' => 'Not as described'];

    public function current(User $actor): User
    {
        $user = User::findOrFail($actor->id);
        abort_unless($user->closed_at === null && in_array($user->status, ['active', 'suspended', 'inactive', 'pending_approval'], true), 403);

        return $user;
    }

    public function scope(User $actor): Builder
    {
        $actor = $this->current($actor);
        if ($actor->isBuyer()) {
            app(BuyerAccessService::class)->requireOrderHistory($actor);

            return BuyerReturnCase::where('buyer_id', $actor->id);
        }
        if ($actor->isSeller() && $actor->isKycApproved()) {
            return BuyerReturnCase::where('seller_id', $actor->id);
        }
        if ($actor->isLogistics()) {
            return app(BuyerReturnCustodyService::class)->scoped($actor);
        }
        abort_unless($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at, 403);

        return BuyerReturnCase::query();
    }

    public function owned(User $actor, BuyerReturnCase $case): BuyerReturnCase
    {
        return $this->scope($actor)->whereKey($case->id)->firstOrFail();
    }

    private function ownedOrder(User $actor, Order $order, bool $lock = false): Order
    {
        $current = Order::whereKey($order->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $current->setRelation('delivery', Delivery::where('order_id', $current->id)->when($lock, fn ($query) => $query->lockForUpdate())->first());
        app(BuyerAccessService::class)->requireExistingOrders($actor, $current, lock: $lock);

        return $current;
    }

    public function availability(User $actor, Order $order): array
    {
        try {
            $order = $this->ownedOrder($actor, $order);
            $source = app(BuyerReturnEligibility::class)->source($order);
            $available = $this->availableItems($order);

            return ['can_request' => collect($available)->contains(fn ($item) => $item['available_quantity'] > 0),
                'until' => $source['request_until']->toIso8601String(), 'reason' => null];
        } catch (ValidationException $error) {
            return ['can_request' => false, 'until' => null, 'reason' => $error->errors()['return'][0] ?? 'A return is not available for this order.'];
        }
    }

    public function form(User $actor, Order $order): array
    {
        $order = $this->ownedOrder($actor, $order);
        $source = app(BuyerReturnEligibility::class)->source($order);
        $nonce = (string) Str::uuid();

        return ['order' => $order->only(['id', 'order_number']), 'items' => $this->availableItems($order),
            'requestUntil' => $source['request_until']->toIso8601String(), 'reasons' => self::REASONS,
            'requestToken' => 'v1.'.$nonce.'.'.$this->signature($actor, $order, $nonce)];
    }

    private function availableItems(Order $order): array
    {
        return $order->items()->with('product:id,name,featured_image')->orderBy('id')->get()->map(fn ($item) => [
            'id' => $item->id, 'name' => $item->product?->name ?? $item->sku_snapshot ?? 'Purchased item',
            'color' => $item->color, 'size' => $item->size, 'unit_price' => $item->unit_price, 'quantity' => $item->quantity,
            'available_quantity' => max(0, $item->quantity - DB::table('buyer_return_unit_claims')->where('order_item_id', $item->id)->count()),
        ])->all();
    }

    private function signature(User $actor, Order $order, string $nonce): string
    {
        return hash_hmac('sha256', implode(':', ['buyer-return-v1', $actor->id, $order->id, $nonce]), config('app.key'));
    }

    public function request(User $actor, Order $order, array $input, mixed $photos): BuyerReturnCase
    {
        $order = $this->ownedOrder($actor, $order);
        if (is_string($input['description'] ?? null)) {
            $input['description'] = trim($input['description']);
        }
        $data = Validator::make(array_replace($input, ['photos' => $photos]), [
            'request_token' => ['required', 'string', 'regex:/\Av1\.[a-f0-9-]{36}\.[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', Rule::in(array_keys(self::REASONS))],
            'description' => ['bail', 'required', new ApplicationText('notes', 10, 1000)],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['array:order_item_id,quantity'],
            'items.*.order_item_id' => ['required', new AsciiPositiveInteger, 'integer', 'distinct'],
            'items.*.quantity' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:99'],
            'photos' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'amount' => ['prohibited'], 'status' => ['prohibited'],
        ])->validate();
        [, $nonce, $signature] = explode('.', $data['request_token']);
        if (! hash_equals($this->signature($actor, $order, $nonce), $signature)) {
            throw ValidationException::withMessages(['request_token' => 'Open Request a return from your own purchase, then try again.']);
        }
        $data['items'] = collect($data['items'])->map(fn ($row) => ['order_item_id' => (int) $row['order_item_id'], 'quantity' => (int) $row['quantity']])
            ->sortBy('order_item_id')->values()->all();
        $files = app(BuyerReturnFiles::class);
        $infos = array_map(fn ($photo, $index) => $files->inspect($photo, 'photos.'.$index), $photos, array_keys($photos));
        $fingerprint = hash('sha256', json_encode([$data['reason'], $data['description'], $data['items'], array_column($infos, 'content_hash')], JSON_THROW_ON_ERROR));
        $token = hash('sha256', $data['request_token']);
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $order, $data, $photos, $infos, $files, $fingerprint, $token, &$paths) {
                $order = $this->ownedOrder($actor, $order, lock: true);
                $prior = BuyerReturnCase::where('creation_token_hash', $token)->first();
                if ($prior) {
                    abort_unless($prior->buyer_id === $actor->id && $prior->order_id === $order->id && hash_equals($prior->creation_fingerprint, $fingerprint), 409,
                        'This return request was already used with different details. Open the saved return or start a new request.');

                    return $prior;
                }
                $source = app(BuyerReturnEligibility::class)->source($order);
                $items = $order->items()->with('product:id,name')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $shops = $items->pluck('shop_id')->unique();
                $shop = $shops->count() === 1 ? Shop::whereKey($shops->first())->lockForUpdate()->first() : null;
                abort_unless($shop && $shop->user?->isSeller(), 409, 'The original seller for this purchase needs review.');
                $selection = [];
                foreach ($data['items'] as $row) {
                    $item = $items->get($row['order_item_id']);
                    if (! $item) {
                        throw ValidationException::withMessages(['items' => 'Choose only items from this purchase.']);
                    }
                    $claimed = DB::table('buyer_return_unit_claims')->where('order_item_id', $item->id)->pluck('unit_position')->all();
                    $positions = [];
                    for ($position = 0; $position < $item->quantity && count($positions) < $row['quantity']; $position++) {
                        if (! in_array($position, $claimed, true)) {
                            $positions[] = $position;
                        }
                    }
                    if (count($positions) !== $row['quantity']) {
                        throw ValidationException::withMessages(['items' => 'One or more selected units already belong to another return. Reload this purchase before trying again.']);
                    }
                    $selection[] = [$item, $positions];
                }
                $case = BuyerReturnCase::create(['reference' => 'BR-'.strtoupper(Str::random(20)), 'order_id' => $order->id,
                    'delivery_id' => $order->delivery->id, 'buyer_id' => $actor->id, 'seller_id' => $shop->user_id, 'shop_id' => $shop->id,
                    'delivery_checkpoint_id' => $source['checkpoint']->id, 'status' => 'requested', 'version' => 1,
                    'reason' => $data['reason'], 'description' => $data['description'], 'route_snapshot' => $source['route'],
                    'delivered_at' => $source['delivered_at'], 'request_until' => $source['request_until'], 'response_until' => now()->addDays(3),
                    'creation_token_hash' => $token, 'creation_fingerprint' => $fingerprint]);
                foreach ($selection as [$item, $positions]) {
                    BuyerReturnItem::create(['buyer_return_case_id' => $case->id, 'order_item_id' => $item->id, 'quantity' => count($positions),
                        'unit_positions' => $positions, 'purchase_snapshot' => $item->only(['product_id', 'product_sale_id', 'shop_id', 'quantity', 'unit_price', 'subtotal', 'color', 'size', 'sku_snapshot'])
                            + ['name' => $item->product?->name ?? $item->sku_snapshot ?? 'Purchased item']]);
                    foreach ($positions as $position) {
                        DB::table('buyer_return_unit_claims')->insert(['order_item_id' => $item->id, 'unit_position' => $position, 'buyer_return_case_id' => $case->id]);
                    }
                }
                foreach ($photos as $index => $photo) {
                    $paths[] = $path = $files->store($photo, $infos[$index]);
                    BuyerReturnEvidence::create(['buyer_return_case_id' => $case->id, 'path' => $path,
                        'content_hash' => $infos[$index]['content_hash'], 'mime' => $infos[$index]['mime'], 'bytes' => $infos[$index]['bytes'], 'created_at' => now()]);
                }
                $this->append($case, $actor, 'requested', null, $data['description'], $token, $fingerprint);

                return $case;
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }

    public function actions(User $actor, BuyerReturnCase $case): array
    {
        $actor = $this->current($actor);
        $actions = [];
        if ($actor->isBuyer() && $case->buyer_id === $actor->id && app(BuyerAccessService::class)->canAccessExistingOrders($actor)) {
            if (in_array($case->status, ['requested', 'rejected', 'escalated', 'approved'], true)) {
                $actions[] = 'withdraw';
            }
            if (($case->status === 'rejected' && $case->appeal_until && now()->lessThanOrEqualTo($case->appeal_until))
                || ($case->status === 'requested' && now()->greaterThan($case->response_until))) {
                $actions[] = 'escalate';
            }
        }
        if ($actor->isSeller() && $actor->id === $case->seller_id && $actor->canAccessPortal() && $actor->email_verified_at
            && $case->status === 'requested' && now()->lessThanOrEqualTo($case->response_until)
            && Shop::whereKey($case->shop_id)->where('user_id', $actor->id)->exists()
            && app(ShopEligibilityService::class)->isEligible(Shop::findOrFail($case->shop_id))) {
            array_push($actions, 'approve', 'reject');
        }
        if ($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at && $case->status === 'escalated') {
            array_push($actions, 'approve', 'reject');
        }
        if (($actor->id === $case->buyer_id || $actor->id === $case->seller_id || $actor->isAdmin() && $actor->canAccessPortal())
            && (($case->status === 'approved' && $case->dropoff_until && now()->greaterThan($case->dropoff_until))
                || ($case->status === 'rejected' && $case->appeal_until && now()->greaterThan($case->appeal_until)))) {
            $actions[] = 'close-period';
        }

        return $actions;
    }

    public function command(User $actor, BuyerReturnCase $case, string $action, array $input): BuyerReturnEvent
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_token' => ['required', 'uuid'],
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'amount' => ['prohibited'], 'status' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
        ])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $fingerprint = hash('sha256', json_encode([$case->id, $actor->id, $action, $data], JSON_THROW_ON_ERROR));
        $token = hash('sha256', $data['request_token']);

        return DB::transaction(function () use ($actor, $case, $action, $data, $fingerprint, $token) {
            Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->owned($actor, $case);
            $prior = BuyerReturnEvent::where('request_token_hash', $token)->first();
            if ($prior) {
                abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'This action was already used with different details. Reload the return before trying again.');

                return $prior;
            }
            abort_unless($case->version === $data['expected_version'], 409, 'This return changed. Reload its latest result before acting.');
            abort_unless(in_array($action, $this->actions($actor, $case), true), 403, 'This action is not available for this return in its current state.');
            if ($action === 'reject' && $actor->isSeller()) {
                Validator::make($data, ['reason' => [new ApplicationText('notes', 10, 300)]])->validate();
            }
            $source = $case->status;
            $case->status = match ($action) {
                'withdraw' => 'withdrawn', 'escalate' => 'escalated', 'approve' => 'approved',
                'reject' => $this->current($actor)->isAdmin() ? 'denied' : 'rejected',
                'close-period' => $source === 'rejected' ? 'denied' : 'expired',
            };
            if ($action === 'approve') {
                $case->dropoff_until = now()->addDays(7);
            }
            if ($action === 'reject' && $case->status === 'rejected') {
                $case->appeal_until = now()->addDays(3);
            }
            $case->version++;
            $case->save();
            if (in_array($case->status, ['withdrawn', 'expired'], true)) {
                DB::table('buyer_return_unit_claims')->where('buyer_return_case_id', $case->id)->delete();
            }

            $event = $this->append($case, $this->current($actor), $action, $source, $data['reason'], $token, $fingerprint);
            if ($action === 'approve') {
                app(BuyerReturnCustodyService::class)->prepare($case, $event);
            }

            return $event;
        }, 3);
    }

    public function append(BuyerReturnCase $case, User $actor, string $type, ?string $source, string $reason, string $token, string $fingerprint, array $context = []): BuyerReturnEvent
    {
        abort_unless(DB::transactionLevel() > 0, 403);
        $event = BuyerReturnEvent::create(['buyer_return_case_id' => $case->id, 'actor_id' => $actor->id, 'sequence' => $case->version,
            'event_type' => $type, 'source_status' => $source, 'target_status' => $case->status, 'reason' => $reason,
            'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
            'context' => array_replace(['response_until' => $case->response_until?->toIso8601String(), 'appeal_until' => $case->appeal_until?->toIso8601String(),
                'dropoff_until' => $case->dropoff_until?->toIso8601String()], $context)]);
        foreach (array_unique([$case->buyer_id, $case->seller_id]) as $recipient) {
            if ($recipient === $actor->id) {
                continue;
            }
            app(NotificationDeliveryService::class)->record($recipient, 'buyer-return', 'buyer-return:'.$event->id, [
                'title' => $type === 'requested' ? 'A buyer requested a return' : 'Your return has an update',
                'body' => 'Open return '.$case->reference.' for its actual decision and next step.',
                'order_id' => $case->order_id, 'return_id' => $case->id, 'target' => 'buyer-return']);
        }
        if ($type === 'escalate') {
            foreach (User::where('role', 'admin')->whereNotNull('email_verified_at')->get()->filter(fn ($admin) => $admin->canAccessPortal()) as $admin) {
                app(NotificationDeliveryService::class)->record($admin->id, 'buyer-return', 'buyer-return:'.$event->id, [
                    'title' => 'A buyer return needs your review', 'body' => 'Review the original request and seller feedback for '.$case->reference.'.',
                    'order_id' => $case->order_id, 'return_id' => $case->id, 'target' => 'buyer-return']);
            }
        }

        return $event;
    }

    public function present(User $actor, BuyerReturnCase $case): array
    {
        $case = $this->owned($actor, $case)->load(['items', 'evidence', 'events', 'order:id,order_number']);

        return $case->only(['id', 'reference', 'status', 'version', 'reason', 'description', 'delivered_at', 'request_until',
            'response_until', 'appeal_until', 'dropoff_until', 'created_at']) + [
                'order' => $case->order->only(['id', 'order_number']), 'actions' => $this->actions($actor, $case),
                'custody' => app(BuyerReturnCustodyService::class)->presentation($actor, $case),
                'money' => app(BuyerReturnMoneyService::class)->presentation($actor, $case),
                'items' => $case->items->map(fn ($item) => ['id' => $item->id, 'quantity' => $item->quantity, 'purchase' => $item->purchase_snapshot])->all(),
                'evidence' => $case->evidence->map(fn ($photo) => ['id' => $photo->id,
                    'url' => '/buyer-returns/'.$case->id.'/evidence/'.$photo->id, 'mime' => $photo->mime, 'bytes' => $photo->bytes, 'purpose' => $photo->purpose])->all(),
                'history' => $case->events->filter(fn ($event) => $actor->isAdmin() || ($actor->isLogistics()
                    ? ! str_starts_with($event->event_type, 'money-')
                    : ! in_array($event->event_type, $actor->isBuyer()
                        ? ['money-offer-funding', 'money-receive-funding', 'money-offer-recovery', 'money-receive-recovery']
                        : ['money-offer-funding', 'money-receive-funding'], true)))
                    ->map(fn ($event) => $event->only(['reference', 'event_type', 'actor_role', 'reason', 'source_status', 'target_status', 'created_at']))->values()->all(),
            ];
    }
}
