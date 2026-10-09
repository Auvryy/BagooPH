<?php

namespace App\Services\Finance;

use App\Models\CodAccount;
use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementEvent;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\AccountRestrictionService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\ShopEligibilityService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class SellerSettlementService
{
    public function current(User $actor, bool $write = false): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->closed_at === null && $actor->email_verified_at !== null
            && (($actor->isAdmin() && $actor->canAccessPortal())
                || (! $write && $actor->isSeller() && $actor->isKycApproved())), 403);

        return $actor;
    }

    public function scoped(User $actor): Builder
    {
        $actor = $this->current($actor);
        $query = Order::query()->where(fn ($source) => $source->where('payment_method', 'cod')->orWhereHas('sellerSettlement'));
        if ($actor->isSeller()) {
            $query->where(fn ($scope) => $scope->whereHas('sellerSettlement', fn ($record) => $record->where('seller_id', $actor->id))
                ->orWhere(fn ($pending) => $pending->whereDoesntHave('sellerSettlement')
                    ->whereHas('items.shop', fn ($shop) => $shop->where('user_id', $actor->id))));
        }

        return $query;
    }

    public static function split(int $productCents): array
    {
        $commission = intdiv($productCents + 5, 10);

        return ['product_cents' => $productCents, 'commission_cents' => $commission, 'seller_cents' => $productCents - $commission];
    }

    public static function pesos(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function eligibility(Order $order): array
    {
        $cash = $order->codAccount;
        $parcel = $order->delivery;
        $checkpoint = $parcel?->checkpoints()->where('checkpoint_type', 'buyer_completed')->first();
        $event = $cash?->events()->latest('sequence')->first();
        $state = $event?->target_state;
        $shopIds = $order->items->pluck('shop_id')->unique();
        $shop = $shopIds->count() === 1 ? Shop::find($shopIds->first()) : null;
        $owner = $shop ? User::find($shop->user_id) : null;
        $basis = self::split($cash?->product_subtotal_cents ?? CodMoney::cents($order->subtotal));
        $basis += ['shipping_cents' => $cash?->shipping_cents ?? CodMoney::cents($order->shipping_fee),
            'discount_cents' => $cash?->discount_cents ?? CodMoney::cents($order->voucher_discount)];
        $blockers = [];
        if ($order->status !== 'completed' || ! $checkpoint || $checkpoint->scanned_by_id !== $order->buyer_id
            || $checkpoint->actor_role !== 'buyer' || ($checkpoint->source_state['order_status'] ?? null) !== 'delivered'
            || ($checkpoint->target_state['order_status'] ?? null) !== 'completed'
            || ! in_array($parcel?->status, ['delivered', 'customer_collected'], true)) {
            $blockers[] = 'The buyer has not confirmed receipt through the documented order flow.';
        }
        if (! $cash || ! $cash->reconciled_at || $event?->event_type !== 'platform_reconciled'
            || ($state['reconciled_reference'] ?? null) !== $event?->reference
            || ($state['reconciled_cents'] ?? null) !== $cash->expected_cents
            || ($state['collected_cents'] ?? null) !== $cash->expected_cents
            || ($state['pending'] ?? null) || collect($state['discrepancies'] ?? [])->contains(fn ($difference) => ! $difference['resolved_by'])
            || array_sum($state['excess'] ?? []) > 0
            || ($cash && (app(CodCashService::class)->atStage($state ?? [], 'platform') !== $cash->expected_cents
                || app(CodCashService::class)->atStage($state ?? [], 'rider') > 0
                || app(CodCashService::class)->atStage($state ?? [], 'hub') > 0))) {
            $blockers[] = 'COD requires platform reconciliation with no unresolved cash or extra money.';
        }
        if ($order->payment_method !== 'cod' || $order->payment_status !== 'paid' || ! $cash
            || $cash->delivery_id !== $parcel?->id || $cash->product_subtotal_cents !== CodMoney::cents($order->subtotal)
            || $cash->shipping_cents !== CodMoney::cents($order->shipping_fee)
            || $cash->discount_cents !== CodMoney::cents($order->voucher_discount)
            || $cash->expected_cents !== CodMoney::cents($order->total_amount)
            || $order->items->sum(fn ($item) => CodMoney::cents($item->subtotal)) !== $basis['product_cents']
            || $basis['product_cents'] <= 0) {
            $blockers[] = 'The original order and cash amounts need evidence review.';
        }
        if (! $owner?->isSeller() || ! $owner->canAccessPortal() || $owner->email_verified_at === null
            || ! $shop || ! app(ShopEligibilityService::class)->isEligible($shop)) {
            $blockers[] = 'The original seller and shop must be approved and active before release.';
        }
        $ledger = $order->commissionLedger;
        if (CommissionLedger::where('order_id', $order->id)->count() > 1 || ($ledger && (! in_array($ledger->status, ['pending'], true) || $ledger->seller_id !== $owner?->id
            || CodMoney::cents($ledger->gross_amount) !== $basis['product_cents']
            || CodMoney::cents($ledger->seller_amount) !== $basis['seller_cents']
            || CodMoney::cents($ledger->platform_commission) !== $basis['commission_cents']
            || CodMoney::cents($ledger->delivery_fee) !== $basis['shipping_cents']))) {
            $blockers[] = 'An older proceeds entry needs review; its label cannot prove or authorize another payment.';
        }

        return $basis + ['eligible' => $blockers === [], 'blockers' => $blockers, 'seller_id' => $owner?->id,
            'shop_id' => $shop?->id, 'buyer_checkpoint_id' => $checkpoint?->id,
            'cod_account_id' => $cash?->id, 'reconciliation_event_id' => $event?->id];
    }

    public function verifiedPayment(?SellerSettlement $record): ?SellerSettlementEvent
    {
        return $record?->events()->where('event_type', 'payment_recorded')->where('actor_role', 'admin')
            ->where('amount_cents', $record->seller_cents)->whereNotNull('payment_reference')
            ->whereNotNull('proof_path')->whereNotNull('proof_hash')->first();
    }

    public function present(Order $order, bool $history = false): array
    {
        $record = $order->sellerSettlement;
        $payment = $this->verifiedPayment($record);
        $eligibility = $payment ? ['blockers' => [], 'buyer_checkpoint_id' => $record->buyer_checkpoint_id]
            : $this->eligibility($order);
        $basis = $record ? $record->only(['product_cents', 'commission_cents', 'seller_cents', 'shipping_cents', 'discount_cents']) : $eligibility;
        $status = $payment ? 'settled' : ($record ? 'authorized' : ($eligibility['eligible'] ? 'eligible' : 'pending'));
        $payload = array_intersect_key($basis, array_flip(['product_cents', 'commission_cents', 'seller_cents', 'shipping_cents', 'discount_cents']))
            + ['order_id' => $order->id, 'order_number' => $record?->snapshot['order_number'] ?? $order->order_number,
                'reference' => $record?->reference, 'seller_id' => $record?->seller_id ?? $eligibility['seller_id'],
                'seller_name' => $record?->snapshot['seller_name'] ?? $order->items->first()?->shop?->user?->name,
                'shop_name' => $record?->snapshot['shop_name'] ?? $order->items->first()?->shop?->name,
                'status' => $status, 'version' => $record ? (int) $record->events()->max('sequence') : 0,
                'order_status' => $payment ? ($record->snapshot['order_status'] ?? 'completed') : $order->status,
                'buyer_completed' => $payment !== null || ($eligibility['buyer_checkpoint_id'] !== null && $order->status === 'completed'),
                'cash_reconciled' => $payment !== null || $order->codAccount?->reconciled_at !== null,
                'blockers' => $payment ? [] : $eligibility['blockers'], 'currency' => 'PHP',
                'legacy_ledger' => $record ? ($record->snapshot['legacy_ledger'] ?? null)
                    : $order->commissionLedger?->only(['id', 'status', 'gross_amount', 'seller_amount', 'platform_commission', 'delivery_fee']),
                'buyer_reference' => $record?->snapshot['buyer_reference'] ?? $order->delivery?->checkpoints()->where('checkpoint_type', 'buyer_completed')->value('record_reference'),
                'cash_id' => $record?->cod_account_id ?? $order->codAccount?->id,
                'cash_reference' => $record?->snapshot['cash_reference'] ?? $order->codAccount?->reference,
                'reconciliation_reference' => $record?->snapshot['reconciliation_reference']
                    ?? $order->codAccount?->events()->where('event_type', 'platform_reconciled')->value('reference')];
        if ($history) {
            $payload['history'] = $record?->events()->orderBy('sequence')->get()->map(fn ($event) => $event->only([
                'id', 'reference', 'sequence', 'event_type', 'amount_cents', 'actor_id', 'actor_role', 'payment_reference', 'reason', 'context', 'created_at'])
                + ['actor_name' => $event->context['actor_name'] ?? User::findOrFail($event->actor_id)->name,
                    'source_reference' => $event->source_event_id ? SellerSettlementEvent::findOrFail($event->source_event_id)->reference : null,
                    'proof_url' => $event->proof_path ? '/seller-settlements/'.$order->id.'/proof/'.$event->id : null])->all() ?? [];
        }

        return $payload;
    }

    public function command(User $actor, int $orderId, string $action, array $input, mixed $proof = null): SellerSettlementEvent
    {
        $actor = $this->current($actor, write: true);
        abort_unless(in_array($action, ['authorize', 'record-payment', 'adjust-reference'], true), 404);
        $rules = ['request_token' => 'required|uuid', 'expected_version' => 'required|integer|min:0',
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'payment_reference' => $action === 'authorize' ? 'prohibited' : 'required|string|max:120|regex:/\A[A-Za-z0-9][A-Za-z0-9 ._:\/\-]*\z/',
            'payment_confirmed' => $action === 'authorize' ? 'prohibited' : 'required|accepted',
            'source_event_id' => $action === 'adjust-reference' ? 'required|integer|min:1' : 'prohibited',
            'proof' => $action === 'authorize' ? 'prohibited' : 'required|file|mimes:jpg,jpeg,png,pdf|max:5120'];
        foreach (['amount', 'seller_amount', 'seller_id', 'recipient_id', 'commission', 'commission_rate', 'rate', 'status',
            'paid_at', 'created_at', 'subtotal', 'shipping_fee', 'payment_status', 'order_id', 'snapshot', 'shop_id',
            'product_cents', 'seller_cents', 'commission_cents', 'shipping_cents', 'discount_cents', 'total_amount',
            'buyer_checkpoint_id', 'cod_account_id', 'reconciliation_event_id', 'actor_id', 'actor_role', 'reference'] as $field) {
            $rules[$field] = 'prohibited';
        }
        $data = Validator::make(array_replace($input, ['proof' => $proof]), $rules)->validate();
        unset($data['proof']);
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        if (isset($data['source_event_id'])) {
            $data['source_event_id'] = (int) $data['source_event_id'];
        }
        if (isset($data['payment_confirmed'])) {
            $data['payment_confirmed'] = true;
        }
        $proofHash = $proof ? hash_file('sha256', $proof->getRealPath()) : null;
        $fingerprint = hash('sha256', json_encode([$action, $orderId, $data, $proofHash], JSON_THROW_ON_ERROR));
        $storedPath = null;
        try {
            return DB::transaction(function () use ($actor, $orderId, $action, $data, $proof, $proofHash, $fingerprint, &$storedPath) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
                Delivery::where('order_id', $orderId)->lockForUpdate()->first();
                CommissionLedger::where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
                $ownerIds = Shop::whereIn('id', $order->items()->pluck('shop_id'))->pluck('user_id');
                User::whereIn('id', $ownerIds->push($actor->id)->push($order->buyer_id))->orderBy('id')->lockForUpdate()->get();
                $actor = $this->current($actor, write: true);
                Shop::whereIn('id', $order->items()->pluck('shop_id'))->orderBy('id')->lockForUpdate()->get();
                app(ShopEligibilityService::class)->lockCategories();
                CodAccount::where('order_id', $orderId)->lockForUpdate()->first();
                $record = SellerSettlement::where('order_id', $orderId)->lockForUpdate()->first();
                if ($prior = $record?->events()->where('request_token', $data['request_token'])->first()) {
                    if ($prior->actor_id !== $actor->id || ! hash_equals($prior->request_fingerprint, $fingerprint)) {
                        throw new DomainException('That request was already used for a different decision.');
                    }

                    return $prior;
                }
                $version = $record ? (int) $record->events()->max('sequence') : 0;
                if ($version !== (int) $data['expected_version']) {
                    throw new DomainException('The settlement record changed. Reload its current evidence.');
                }
                if ($action === 'authorize' && $record) {
                    throw new DomainException('Release is already authorized for this order.');
                }
                if ($action !== 'authorize' && ! $record) {
                    throw new DomainException('Authorize the eligible release before recording payment.');
                }
                $payment = $this->verifiedPayment($record);
                if ($action === 'record-payment' && $record->events()->where('event_type', 'payment_recorded')->exists()) {
                    throw new DomainException('Payment is already recorded for this order.');
                }
                if ($action !== 'adjust-reference') {
                    $eligibility = $this->eligibility($order->fresh());
                    if (! $eligibility['eligible']) {
                        throw new DomainException(implode(' ', $eligibility['blockers']));
                    }
                    if ($record && collect(['seller_id', 'shop_id', 'buyer_checkpoint_id', 'cod_account_id', 'reconciliation_event_id',
                        'product_cents', 'seller_cents', 'commission_cents', 'shipping_cents', 'discount_cents'])
                        ->contains(fn ($field) => $eligibility[$field] !== $record->$field)) {
                        throw new DomainException('The authorized source or recipient no longer matches this order.');
                    }
                }
                $source = null;
                if ($action === 'adjust-reference') {
                    $source = $record->events()->whereKey($data['source_event_id'])->first();
                    if (! $payment || ! $source || ! in_array($source->event_type, ['payment_recorded', 'payment_reference_corrected'], true)) {
                        throw new DomainException('A correction must refer to recorded payment evidence for this settlement.');
                    }
                }
                if (! $record) {
                    $shop = Shop::findOrFail($eligibility['shop_id']);
                    $cash = $order->codAccount;
                    $buyerCheckpoint = DeliveryCheckpoint::findOrFail($eligibility['buyer_checkpoint_id']);
                    $record = SellerSettlement::create(array_intersect_key($eligibility, array_flip(['seller_id', 'shop_id', 'buyer_checkpoint_id',
                        'cod_account_id', 'reconciliation_event_id', 'product_cents', 'seller_cents', 'commission_cents', 'shipping_cents', 'discount_cents']))
                        + ['reference' => 'SET-'.strtoupper((string) Str::uuid()), 'order_id' => $orderId,
                            'legacy_ledger_id' => $order->commissionLedger?->id,
                            'snapshot' => ['currency' => 'PHP', 'order_number' => $order->order_number,
                                'order_status' => $order->status, 'order_created_at' => $order->created_at->toIso8601String(),
                                'seller_name' => $shop->user->name, 'shop_name' => $shop->name,
                                'buyer_id' => $order->buyer_id, 'buyer_reference' => $buyerCheckpoint->record_reference,
                                'cash_reference' => $cash->reference,
                                'reconciliation_reference' => $cash->events()->whereKey($eligibility['reconciliation_event_id'])->value('reference'),
                                'items' => $order->items->map(fn ($item) => $item->only(['id', 'shop_id', 'quantity', 'unit_price', 'subtotal', 'sku_snapshot']))->all(),
                                'legacy_ledger' => $order->commissionLedger?->only(['id', 'seller_id', 'status', 'gross_amount', 'seller_amount', 'platform_commission', 'delivery_fee'])]]);
                }
                if ($proof) {
                    $storedPath = $proof->store('settlement-proofs', 'local');
                    if (! $storedPath) {
                        throw new DomainException('Payment evidence could not be saved.');
                    }
                }
                $type = match ($action) {
                    'authorize' => 'release_authorized', 'record-payment' => 'payment_recorded', 'adjust-reference' => 'payment_reference_corrected',
                };
                $event = $record->events()->create(['reference' => 'PAY-'.strtoupper((string) Str::uuid()), 'sequence' => $version + 1,
                    'event_type' => $type, 'actor_id' => $actor->id, 'actor_role' => $actor->role,
                    'amount_cents' => $action === 'adjust-reference' ? 0 : $record->seller_cents,
                    'payment_reference' => $data['payment_reference'] ?? null, 'reason' => $data['reason'],
                    'proof_path' => $storedPath, 'proof_hash' => $proofHash, 'source_event_id' => $source?->id,
                    'context' => ['from' => $payment ? 'settled' : ($version ? 'authorized' : 'eligible'),
                        'to' => $type === 'release_authorized' ? 'authorized' : 'settled',
                        'previous_reference' => $source?->payment_reference, 'recipient_id' => $record->seller_id, 'actor_name' => $actor->name],
                    'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint]);
                if ($action === 'record-payment' && ! $order->commissionLedger) {
                    CommissionLedger::create(['order_id' => $orderId, 'seller_id' => $record->seller_id,
                        'gross_amount' => self::pesos($record->product_cents), 'seller_amount' => self::pesos($record->seller_cents),
                        'platform_commission' => self::pesos($record->commission_cents), 'delivery_fee' => self::pesos($record->shipping_cents),
                        'status' => 'settled']);
                }
                foreach (array_unique([$actor->id, $record->seller_id]) as $recipient) {
                    app(NotificationDeliveryService::class)->record($recipient, 'settlement-event', 'settlement:'.$event->reference,
                        ['title' => match ($type) {
                            'release_authorized' => 'Seller release was authorized', 'payment_recorded' => 'Seller payment was recorded',
                            default => 'Seller payment evidence was corrected',
                        }, 'body' => $type === 'release_authorized' ? 'Payment is still awaiting a recorded receipt.' : 'Open the original payment record to review its evidence.',
                            'target' => 'seller-settlement', 'order_id' => $orderId]);
                }

                return $event;
            });
        } catch (Throwable $exception) {
            if ($storedPath && ! SellerSettlementEvent::where('proof_path', $storedPath)->exists()) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }
}
