<?php

namespace App\Services\Finance;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\ShipmentLossReconciliation;
use App\Models\User;
use App\Models\WalletRefundCreditRequest;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\ShopEligibilityService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLossReserve;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ShipmentLossReconciliationService
{
    public const PATHS = [
        'unpaid_undelivered',
        'post_delivery_partial_return',
        'already_settled_salvage',
        'already_settled_seller_return',
    ];

    public const PATH_LABELS = [
        'unpaid_undelivered' => 'Resume unpaid forward delivery from quarantine',
        'post_delivery_partial_return' => 'Resume buyer return transit from quarantine',
        'already_settled_salvage' => 'Release settled salvage custody',
        'already_settled_seller_return' => 'Return settled merchandise to seller',
    ];

    public function available(): bool
    {
        return Schema::hasTable('shipment_loss_reconciliations');
    }

    public function active(ShipmentFinancialPlan $plan): ?ShipmentLossReconciliation
    {
        return $this->available() ? ShipmentLossReconciliation::where('active_plan_id', $plan->id)->first() : null;
    }

    public function latest(ShipmentFinancialPlan $plan): ?ShipmentLossReconciliation
    {
        return $this->available() ? ShipmentLossReconciliation::where('shipment_financial_plan_id', $plan->id)->latest('id')->first() : null;
    }

    public function activeForParcel(Delivery|BuyerReturnParcel $parcel): ?ShipmentLossReconciliation
    {
        if (! $this->available()) {
            return null;
        }
        $query = ShipmentLossReconciliation::query()->where('status', 'released')
            ->whereColumn('active_plan_id', 'shipment_financial_plan_id')
            ->whereHas('plan', function ($plan) use ($parcel): void {
                $plan->where('kind', 'transit_loss');
                if ($parcel instanceof BuyerReturnParcel) {
                    $plan->where('buyer_return_case_id', $parcel->buyer_return_case_id);
                } else {
                    $plan->where('delivery_id', $parcel->id)->whereNull('buyer_return_case_id');
                }
            });

        return $query->first();
    }

    public function blocksOrderSettlement(Order $order): ?ShipmentFinancialPlan
    {
        if (! Schema::hasTable('shipment_financial_plans')) {
            return null;
        }
        $plans = ShipmentFinancialPlan::where('order_id', $order->id)
            ->where('kind', 'transit_loss')
            ->where(fn ($query) => $query->whereNotNull('active_order_id')->orWhereNotNull('active_return_case_id'))
            ->orderByDesc('id')
            ->get();

        foreach ($plans as $plan) {
            $reconciliation = $this->active($plan);
            if (! $reconciliation || $reconciliation->status !== 'released') {
                return $plan;
            }
            if (in_array($reconciliation->outcome_path, ['already_settled_salvage', 'already_settled_seller_return'], true)) {
                return $plan;
            }
        }

        return null;
    }

    public function activeForOrder(Order $order): ?ShipmentLossReconciliation
    {
        if (! $this->available()) {
            return null;
        }

        return ShipmentLossReconciliation::query()
            ->where('status', 'released')
            ->whereColumn('active_plan_id', 'shipment_financial_plan_id')
            ->whereHas('plan', fn ($plan) => $plan->where('order_id', $order->id)->where('kind', 'transit_loss'))
            ->latest('id')
            ->first();
    }

    public function sourceFacts(ShipmentFinancialPlan $original): array
    {
        $plan = ShipmentFinancialPlan::findOrFail($original->id);
        $source = app(ShipmentLossSource::class);
        $source->assertRetained($plan);
        $finding = $source->assertQuarantined($plan);
        $wallet = ($plan->snapshot['payment_method'] ?? null) === 'wallet';
        $state = $wallet ? app(WalletLossReserve::class)->summary($plan) : app(ShipmentLossCash::class)->state($plan);
        // State version reflects total event count (including reconciliation lifecycle events); sourceFacts tracks source events separately.
        unset($state['version']);
        $order = Order::with('items.shop.user', 'delivery', 'walletPayment', 'codAccount')->findOrFail($plan->order_id);
        $company = LogisticsCompany::findOrFail($plan->logistics_company_id);
        $owner = User::findOrFail($company->user_id);
        $hub = LogisticsHub::findOrFail($finding->hub_id);
        $shop = $order->items->first()?->shop;
        $parcel = $plan->buyer_return_case_id
            ? BuyerReturnParcel::where('buyer_return_case_id', $plan->buyer_return_case_id)->firstOrFail()
            : $order->delivery;
        $case = $plan->buyer_return_case_id ? BuyerReturnCase::findOrFail($plan->buyer_return_case_id) : null;
        $creditRequests = Schema::hasTable('wallet_refund_credit_requests')
            ? WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)
                ->orderBy('id')->get(['id', 'status', 'amount_cents', 'version'])->toArray()
            : [];
        $approvedGrantsRemaining = 0;
        if ($wallet && Schema::hasTable('wallet_refund_credit_requests')) {
            $grantBalances = app(WalletFundingCredits::class)->grantBalances($owner);
            $approvedGrantsRemaining = (int) WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)
                ->where('actor_id', $owner->id)->where('status', 'approved')->pluck('id')
                ->sum(fn ($id) => $grantBalances[$id] ?? 0);
        }

        $sourceEvents = $plan->events()->whereNotIn('event_type', [
            'loss-reconciliation-approved',
            'loss-reconciliation-invalidated',
            'loss-quarantine-released',
        ]);

        return [
            'plan' => [
                'id' => $plan->id,
                'reference' => $plan->reference,
                'active_order_id' => $plan->active_order_id,
                'active_return_case_id' => $plan->active_return_case_id,
                'version' => (int) (clone $sourceEvents)->max('sequence'),
                'latest_event_id' => (int) (clone $sourceEvents)->max('id'),
                'snapshot' => $plan->snapshot,
            ],
            'finding' => [
                'id' => $finding->id,
                'reference' => $finding->reference,
                'hub_id' => $finding->hub_id,
                'manifest_id' => $finding->manifest_id,
                'financial_version' => $finding->financial_version,
            ],
            'financial_state' => $state,
            'credit_requests' => $creditRequests,
            'unused_credits_cents' => $approvedGrantsRemaining,
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'buyer_closed' => $order->buyer?->closed_at !== null,
                'shop_eligible' => $shop ? app(ShopEligibilityService::class)->isEligible($shop) : false,
                'has_buyer_returns' => $order->buyerReturns()->exists(),
            ],
            'facility' => [
                'company_eligible' => LogisticsCompany::eligible()->whereKey($company->id)->exists(),
                'hub_eligible' => LogisticsHub::eligible()->whereKey($hub->id)->where('logistics_company_id', $company->id)->exists(),
                'owner_active' => $owner->closed_at === null && $owner->email_verified_at !== null && $owner->isKycApproved(),
            ],
            'custody' => $case
                ? ['type' => 'return', 'case_status' => $case->status, 'parcel' => $parcel->state()]
                : ['type' => 'forward', 'delivery_status' => $parcel->status, 'current_hub_id' => $parcel->current_hub_id,
                    'tracking_number' => $parcel->tracking_number, 'custody' => DeliveryCheckpoint::lastCustody($parcel)],
        ];
    }

    public function sourceFingerprint(ShipmentFinancialPlan $plan): string
    {
        return app(AccountRestrictionService::class)->token($this->sourceFacts($plan));
    }

    public function assessment(ShipmentFinancialPlan $original): array
    {
        $plan = ShipmentFinancialPlan::findOrFail($original->id);
        $finding = app(ShipmentLossSource::class)->finding($plan);
        if (! $finding) {
            return [
                'eligible_paths' => [],
                'blocked_reasons' => array_fill_keys(self::PATHS, 'Record an actual found-parcel quarantine intake first.'),
                'source_fingerprint' => null,
                'stale' => false,
                'reconciliation' => null,
            ];
        }
        $active = $this->active($plan);
        if ($active && $active->status === 'released') {
            return [
                'eligible_paths' => [],
                'blocked_reasons' => array_fill_keys(self::PATHS, 'Physical quarantine was already reconciled and released.'),
                'source_fingerprint' => $active->source_fingerprint,
                'stale' => false,
                'reconciliation' => $this->presentRecord($active, false),
            ];
        }

        try {
            $facts = $this->sourceFacts($plan);
        } catch (Throwable $error) {
            return [
                'eligible_paths' => [],
                'blocked_reasons' => array_fill_keys(self::PATHS, $error->getMessage()),
                'source_fingerprint' => null,
                'stale' => $active !== null,
                'reconciliation' => $active ? $this->presentRecord($active, true) : null,
            ];
        }

        $fingerprint = app(AccountRestrictionService::class)->token($facts);
        $stale = $active && $active->status === 'approved' && ! hash_equals($active->source_fingerprint, $fingerprint);

        $wallet = ($plan->snapshot['payment_method'] ?? null) === 'wallet';
        $state = $facts['financial_state'];
        $snapshot = $plan->snapshot;
        $isReturn = $plan->buyer_return_case_id !== null;

        $commonBlocker = null;
        if (! $facts['facility']['company_eligible'] || ! $facts['facility']['hub_eligible']) {
            $commonBlocker = 'The holding facility and logistics company must be currently active and eligible.';
        } elseif ($state['advance_outstanding_cents'] > 0) {
            $commonBlocker = 'Retire every outstanding platform advance before approving reconciliation.';
        } elseif ($wallet && collect($facts['credit_requests'] ?? [])->contains(fn ($row) => ($row['status'] ?? null) === 'pending')) {
            $commonBlocker = 'Review every pending wallet funding request before approving found-parcel reconciliation.';
        } elseif (! $wallet && (($state['pending_company_cents'] ?? 0) > 0 || ($state['pending_advance_cents'] ?? 0) > 0
            || ($state['pending_buyer_return_cents'] ?? 0) > 0 || ($state['pending_seller_return_cents'] ?? 0) > 0)) {
            $commonBlocker = 'Complete or resolve every pending cash transfer before approving found-parcel reconciliation.';
        } elseif (! $wallet && ($state['buyer']['status'] === 'disputed' || $state['seller']['status'] === 'disputed' || $state['has_company_return_dispute'])) {
            $commonBlocker = 'Resolve every open cash nonreceipt dispute before approving reconciliation.';
        } elseif (! $wallet && $state['has_unconfirmed_company_return']) {
            $commonBlocker = 'Await company confirmation of all recorded company cash returns before approving reconciliation.';
        }

        if ($wallet) {
            $paid = (bool) $state['resolved'];
            $buyerPaid = $paid && $snapshot['buyer_refund_cents'] > 0;
            $sellerPaid = $paid && $snapshot['seller_protection_cents'] > 0;
            $buyerReturned = $buyerPaid && $state['buyer_compensation_returned_cents'] === $snapshot['buyer_refund_cents'];
            $sellerReturned = $sellerPaid && $state['seller_compensation_returned_cents'] === $snapshot['seller_protection_cents'];
            $unreturnedSurplus = $state['balance_cents'] > 0;
            $unpaidReservesRemaining = $state['original_reserved_cents'] > 0 || $state['company_received_cents'] > 0
                || $state['balance_cents'] > 0 || $facts['unused_credits_cents'] > 0;
            $payoutUnconfirmed = false;
        } else {
            $paid = (bool) $state['paid_any'];
            $buyerPaid = $state['buyer']['payment_id'] !== null;
            $sellerPaid = $state['seller']['payment_id'] !== null;
            $buyerReturned = $buyerPaid && $state['buyer_compensation_returned_cents'] === $snapshot['buyer_refund_cents'];
            $sellerReturned = $sellerPaid && $state['seller_compensation_returned_cents'] === $snapshot['seller_protection_cents'];
            $payoutUnconfirmed = ($buyerPaid && $state['buyer']['status'] !== 'confirmed')
                || ($sellerPaid && $state['seller']['status'] !== 'confirmed');
            $retainedFloor = $state['resolved'] ? $snapshot['protected_earned_shipping_cents'] : 0;
            $unreturnedSurplus = $state['balance_cents'] !== $retainedFloor;
            $unpaidReservesRemaining = $state['company_cash_net_cents'] > 0 || $state['balance_cents'] > 0;
        }

        $eligible = [];
        $blocked = [];

        if ($commonBlocker) {
            return [
                'eligible_paths' => [],
                'blocked_reasons' => array_fill_keys(self::PATHS, $commonBlocker),
                'source_fingerprint' => $fingerprint,
                'stale' => $stale,
                'reconciliation' => $active ? $this->presentRecord($active, $stale) : ($this->latest($plan) ? $this->presentRecord($this->latest($plan), false) : null),
            ];
        }

        // 1. unpaid_undelivered
        if ($isReturn) {
            $blocked['unpaid_undelivered'] = 'Buyer return parcels cannot resume forward buyer delivery.';
        } elseif ($paid) {
            $blocked['unpaid_undelivered'] = 'Compensation was already settled; an already-paid shipment cannot resume unpaid delivery to the buyer.';
        } elseif ($unpaidReservesRemaining) {
            $blocked['unpaid_undelivered'] = 'Restore all unused escrow, company funding, cash balances, and unused credits to zero before resuming delivery.';
        } elseif (in_array($facts['order']['status'], ['completed', 'cancelled', 'returned', 'delivered'], true) || $facts['order']['has_buyer_returns']) {
            $blocked['unpaid_undelivered'] = 'The original order is already in a terminal or return state.';
        } else {
            $routeMsg = $this->validateRouteAvailability($plan, $finding, 'unpaid_undelivered');
            if ($routeMsg !== null) {
                $blocked['unpaid_undelivered'] = $routeMsg;
            } else {
                $eligible[] = 'unpaid_undelivered';
            }
        }

        // 2. post_delivery_partial_return
        if (! $isReturn) {
            $blocked['post_delivery_partial_return'] = 'Only post-delivery buyer return parcels use buyer-return transit resumption.';
        } elseif ($facts['custody']['case_status'] !== 'in_transit') {
            $blocked['post_delivery_partial_return'] = 'The return case must remain in transit before resuming return transport.';
        } elseif (! $paid && $unpaidReservesRemaining) {
            $blocked['post_delivery_partial_return'] = 'Restore all unused return-loss funding, cash balances, and unused credits to zero before resuming return transit.';
        } elseif ($paid && $payoutUnconfirmed) {
            $blocked['post_delivery_partial_return'] = 'Confirm receipt of all paid beneficiary cash before completing compensation return reconciliation.';
        } elseif ($paid && $buyerPaid && ! $buyerReturned) {
            $blocked['post_delivery_partial_return'] = 'An already-paid buyer return refund was kept by the buyer. Either obtain a voluntary buyer return or reconcile as salvage.';
        } elseif ($paid && $unreturnedSurplus) {
            $blocked['post_delivery_partial_return'] = 'Return all surplus returned compensation to its company/platform funding sources before resuming return transit.';
        } else {
            $routeMsg = $this->validateRouteAvailability($plan, $finding, 'post_delivery_partial_return');
            if ($routeMsg !== null) {
                $blocked['post_delivery_partial_return'] = $routeMsg;
            } else {
                $eligible[] = 'post_delivery_partial_return';
            }
        }

        // 3. already_settled_seller_return
        if ($isReturn) {
            $blocked['already_settled_seller_return'] = 'Settled buyer returns use buyer-return resumption (after voluntary return) or salvage custody.';
        } elseif (! $paid) {
            $blocked['already_settled_seller_return'] = 'No compensation was paid yet. Use unpaid delivery resumption or record settlement first.';
        } elseif ($payoutUnconfirmed) {
            $blocked['already_settled_seller_return'] = 'Confirm receipt of all paid beneficiary cash transfers first.';
        } elseif ($sellerPaid && ! $sellerReturned) {
            $blocked['already_settled_seller_return'] = 'Seller kept seller-protection compensation; seller cannot also reclaim the physical item unless protection is voluntarily returned.';
        } elseif ($unreturnedSurplus) {
            $blocked['already_settled_seller_return'] = 'Distribute all surplus returned compensation back to company/escrow accounts (keeping only earned shipping where applicable).';
        } elseif (! $facts['order']['shop_eligible']) {
            $blocked['already_settled_seller_return'] = 'The owning seller shop must remain active and eligible to receive returned stock.';
        } else {
            $routeMsg = $this->validateRouteAvailability($plan, $finding, 'already_settled_seller_return');
            if ($routeMsg !== null) {
                $blocked['already_settled_seller_return'] = $routeMsg;
            } else {
                $eligible[] = 'already_settled_seller_return';
            }
        }

        // 4. already_settled_salvage
        if (! $paid) {
            $blocked['already_settled_salvage'] = 'Salvage custody applies only after loss compensation or resolution has already been settled.';
        } elseif ($payoutUnconfirmed) {
            $blocked['already_settled_salvage'] = 'Confirm receipt of all recorded beneficiary cash transfers before releasing salvage.';
        } elseif ($unreturnedSurplus) {
            $blocked['already_settled_salvage'] = 'Distribute all surplus returned compensation to company/escrow before releasing salvage custody.';
        } else {
            $eligible[] = 'already_settled_salvage';
        }

        $record = $active ?: $this->latest($plan);

        return [
            'eligible_paths' => $eligible,
            'blocked_reasons' => $blocked,
            'source_fingerprint' => $fingerprint,
            'stale' => $stale,
            'reconciliation' => $record ? $this->presentRecord($record, $stale) : null,
        ];
    }

    private function validateRouteAvailability(ShipmentFinancialPlan $plan, ShipmentLossFinding $finding, string $path): ?string
    {
        try {
            $this->routePlan($plan, $finding, $path);

            return null;
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }

    public function routePlan(ShipmentFinancialPlan $plan, ShipmentLossFinding $finding, string $path): array
    {
        if ($path === 'already_settled_salvage') {
            return ['forward_route_hub_ids' => null, 'return_route_hub_ids' => null, 'return_route_position' => null];
        }
        $foundHub = LogisticsHub::eligible()->whereKey($finding->hub_id)->where('logistics_company_id', $plan->logistics_company_id)->first();
        abort_unless($foundHub !== null, 409, 'The quarantine facility is no longer active within this logistics company.');

        if ($path === 'post_delivery_partial_return') {
            $parcel = BuyerReturnParcel::where('buyer_return_case_id', $plan->buyer_return_case_id)->firstOrFail();
            $finalHubId = $parcel->hub_ids[count($parcel->hub_ids) - 1] ?? 0;
            $finalHub = LogisticsHub::eligible()->whereKey($finalHubId)->where('logistics_company_id', $plan->logistics_company_id)->first();
            abort_unless($finalHub !== null, 409, 'The return destination hub is no longer active within this logistics company.');

            return ['forward_route_hub_ids' => null, 'return_route_hub_ids' => $this->corridor($foundHub, $finalHub), 'return_route_position' => 0];
        }

        $delivery = Delivery::findOrFail($plan->delivery_id);
        if ($path === 'already_settled_seller_return') {
            $originHub = LogisticsHub::eligible()->whereKey($delivery->origin_bayan_hub_id)->where('logistics_company_id', $plan->logistics_company_id)->first();
            abort_unless($originHub !== null, 409, 'The original seller hub is no longer active within this logistics company.');

            return ['forward_route_hub_ids' => null, 'return_route_hub_ids' => $this->corridor($foundHub, $originHub), 'return_route_position' => 0];
        }

        $destHub = LogisticsHub::eligible()->whereKey($delivery->destination_bayan_hub_id)->where('logistics_company_id', $plan->logistics_company_id)->first();
        abort_unless($destHub !== null, 409, 'The delivery destination hub is no longer active within this logistics company.');

        return ['forward_route_hub_ids' => $this->corridor($foundHub, $destHub), 'return_route_hub_ids' => null, 'return_route_position' => 0];
    }

    public function corridor(LogisticsHub $from, LogisticsHub $to): array
    {
        if ($from->id === $to->id) {
            return [$from->id];
        }
        $fromMotherId = $from->isMotherHub() ? $from->id : $from->parent_hub_id;
        $toMotherId = $to->isMotherHub() ? $to->id : $to->parent_hub_id;
        $fromMother = $fromMotherId ? LogisticsHub::eligible()->where('logistics_company_id', $from->logistics_company_id)->where('tier', 'regional_mother_hub')->find($fromMotherId) : null;
        $toMother = $toMotherId ? LogisticsHub::eligible()->where('logistics_company_id', $from->logistics_company_id)->where('tier', 'regional_mother_hub')->find($toMotherId) : null;

        $hubs = [$from->id];
        if (! $from->isMotherHub() && $fromMother) {
            $hubs[] = $fromMother->id;
        }
        if ($toMother && ! in_array($toMother->id, $hubs, true)) {
            $hubs[] = $toMother->id;
        }
        if (! in_array($to->id, $hubs, true)) {
            $hubs[] = $to->id;
        }

        abort_unless(count($hubs) >= 2 && $hubs[count($hubs) - 1] === $to->id, 409, 'No valid active Mother Hub corridor connects this facility to its destination.');
        $resolved = LogisticsHub::eligible()->whereIn('id', $hubs)->get()->keyBy('id');
        for ($i = 0; $i < count($hubs) - 1; $i++) {
            $a = $resolved->get($hubs[$i]);
            $b = $resolved->get($hubs[$i + 1]);
            abort_unless($a && $b && ($a->isMotherHub() || $b->isMotherHub()), 409,
                'A configured facility leg lacks a required regional Mother Hub connection.');
        }

        return $hubs;
    }

    public function approve(User $actor, ShipmentFinancialPlan $original, array $input, UploadedFile $proof): ShipmentLossReconciliation
    {
        abort_unless($this->available(), 409, 'Apply the additive reconciliation migration before approving quarantine release.');
        $allowed = ['expected_version', 'request_token', 'outcome_path', 'reason', 'reconciliation_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make([...$input, 'proof' => $proof], $rules + [
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'],
            'outcome_path' => ['required', 'in:'.implode(',', self::PATHS)],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'reconciliation_confirmed' => ['required', 'accepted'],
            'proof' => ['required', 'file', 'max:5120'],
        ])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        $info = app(ShipmentFinanceFiles::class)->inspect($proof);
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, 'reconcile-found', $data['expected_version'],
            $data['outcome_path'], $data['reason'], $info['proof_hash']], JSON_THROW_ON_ERROR));
        $paths = [];

        try {
            $record = DB::transaction(function () use ($actor, $original, $data, $proof, $info, $token, $fingerprint, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $admin = app(WalletCreditService::class)->admin($actor);
                Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', array_unique([$admin->id, $original->buyer_id, $original->seller_id]))->orderBy('id')->lockForUpdate()->get();
                $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
                LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
                LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
                if ($plan->buyer_return_case_id) {
                    $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                    $case->parcel()->lockForUpdate()->firstOrFail();
                }

                if ($prior = ShipmentLossReconciliation::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $admin->id
                        && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                        'Retain the exact original found-parcel reconciliation request and evidence.');

                    return $prior;
                }
                app(ShipmentRefusalReview::class)->unused($token);

                $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->lockForUpdate()->first();
                abort_unless($finding !== null, 409, 'Record an actual found-parcel quarantine intake first.');
                abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'] ?? [], true) || $admin->id === $finding->actor_id, 403,
                    'An independent current admin distinct from all shipment and finding actors must approve reconciliation.');
                abort_if($this->active($plan) !== null, 409,
                    'An active found-parcel reconciliation already exists. Invalidate any un-released stale approval before re-approving.');

                $version = (int) $plan->events()->max('sequence');
                abort_unless($version === $data['expected_version'], 409, 'Review the current original financial history before approving reconciliation.');

                $assessment = $this->assessment($plan);
                if (! in_array($data['outcome_path'], $assessment['eligible_paths'], true)) {
                    abort(409, $assessment['blocked_reasons'][$data['outcome_path']] ?? 'This reconciliation path is not eligible.');
                }

                $route = $this->routePlan($plan, $finding, $data['outcome_path']);
                $reference = (string) Str::uuid();
                $path = $proof->storeAs('shipment-loss-reconciliations', $reference.'.'.$info['extension'], 'local');
                abort_unless($path && Storage::disk('local')->exists($path), 503, 'The private reconciliation evidence could not be saved.');
                $paths[] = $path;

                $reconciliation = ShipmentLossReconciliation::create([
                    'reference' => $reference,
                    'shipment_financial_plan_id' => $plan->id,
                    'active_plan_id' => $plan->id,
                    'shipment_loss_finding_id' => $finding->id,
                    'outcome_path' => $data['outcome_path'],
                    'status' => 'approved',
                    'actor_id' => $admin->id,
                    'financial_version' => $version,
                    'source_fingerprint' => $assessment['source_fingerprint'],
                    'plan_snapshot' => $plan->snapshot,
                    'finding_snapshot' => ['id' => $finding->id, 'reference' => $finding->reference, 'hub_id' => $finding->hub_id,
                        'manifest_id' => $finding->manifest_id, 'barcode_scanned' => $finding->barcode_scanned],
                    'forward_route_hub_ids' => $route['forward_route_hub_ids'],
                    'return_route_hub_ids' => $route['return_route_hub_ids'],
                    'return_route_position' => $route['return_route_position'],
                    'reason' => $data['reason'],
                    'proof_path' => $path,
                    ...array_diff_key($info, ['extension' => true]),
                    'request_token_hash' => $token,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);

                ShipmentFinancialEvent::create([
                    'shipment_financial_plan_id' => $plan->id,
                    'actor_id' => $admin->id,
                    'event_type' => 'loss-reconciliation-approved',
                    'sequence' => $version + 1,
                    'amount_cents' => 0,
                    'reason' => $data['reason'],
                    'request_token_hash' => $token,
                    'request_fingerprint' => $fingerprint,
                    'context' => [
                        'reconciliation_reference' => $reconciliation->reference,
                        'outcome_path' => $reconciliation->outcome_path,
                        'source_fingerprint' => $reconciliation->source_fingerprint,
                        'finding_reference' => $finding->reference,
                        'no_money_moved' => true,
                        'no_parcel_release' => true,
                    ],
                    'created_at' => now(),
                ]);

                return $reconciliation;
            }, 3);
            $this->discardUnused($paths);

            return $record;
        } catch (Throwable $error) {
            $this->discardUnused($paths);
            throw $error;
        }
    }

    public function invalidate(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentLossReconciliation
    {
        abort_unless($this->available(), 409, 'Apply the additive reconciliation migration first.');
        $allowed = ['expected_version', 'request_token', 'reason', 'invalidation_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, $rules + [
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'invalidation_confirmed' => ['required', 'accepted'],
        ])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, 'invalidate-reconciliation',
            $data['expected_version'], $data['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $admin = app(WalletCreditService::class)->admin($actor);
            Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($prior = ShipmentLossReconciliation::where('invalidation_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->invalidated_by_id === $admin->id
                    && hash_equals($prior->invalidation_fingerprint, $fingerprint), 409,
                    'Retain the exact original invalidation request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'] ?? [], true), 403,
                'An independent current admin must invalidate a stale reconciliation.');
            $record = ShipmentLossReconciliation::where('active_plan_id', $plan->id)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'approved', 409,
                'Only an un-released approved reconciliation can be invalidated.');
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === $data['expected_version'], 409, 'Review the current original financial history before invalidating.');

            $record->update([
                'status' => 'invalidated',
                'active_plan_id' => null,
                'invalidated_at' => now(),
                'invalidated_by_id' => $admin->id,
                'invalidation_reason' => $data['reason'],
                'invalidation_token_hash' => $token,
                'invalidation_fingerprint' => $fingerprint,
            ]);

            ShipmentFinancialEvent::create([
                'shipment_financial_plan_id' => $plan->id,
                'actor_id' => $admin->id,
                'event_type' => 'loss-reconciliation-invalidated',
                'sequence' => $version + 1,
                'amount_cents' => 0,
                'reason' => $data['reason'],
                'request_token_hash' => $token,
                'request_fingerprint' => $fingerprint,
                'context' => [
                    'reconciliation_reference' => $record->reference,
                    'outcome_path' => $record->outcome_path,
                    'no_money_moved' => true,
                    'no_parcel_release' => true,
                ],
                'created_at' => now(),
            ]);

            return $record->fresh();
        }, 3);
    }

    public function presentRecord(ShipmentLossReconciliation $record, bool $stale): array
    {
        return [
            'id' => $record->id,
            'reference' => $record->reference,
            'outcome_path' => $record->outcome_path,
            'outcome_label' => self::PATH_LABELS[$record->outcome_path] ?? $record->outcome_path,
            'status' => $record->status,
            'stale' => $stale,
            'reason' => $record->reason,
            'proof_url' => '/shipment-finances/'.$record->shipment_financial_plan_id.'/reconciliation/'.$record->id.'/proof',
            'invalidation_reason' => $record->invalidation_reason,
            'invalidated_at' => $record->invalidated_at?->toIso8601String(),
            'released_at' => $record->released_at?->toIso8601String(),
            'created_at' => $record->created_at->toIso8601String(),
        ];
    }

    private function discardUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! ShipmentLossReconciliation::where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
