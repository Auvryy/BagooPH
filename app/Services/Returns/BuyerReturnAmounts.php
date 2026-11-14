<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\Order;
use App\Models\ShipmentFinancialPlan;
use App\Models\VoucherOrderAllocation;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\Vouchers\VoucherFundingService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuyerReturnAmounts
{
    /** Prefix allocation retains every original cent independently of return order. */
    private function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        abort_unless($amount >= 0 && ($total > 0 || $amount === 0), 409, 'The original merchandise amounts need review.');
        $prefix = 0;
        $previous = 0;
        $result = [];
        foreach ($weights as $weight) {
            $prefix += $weight;
            $next = $total === 0 ? 0 : BigInteger::of($amount)->multipliedBy($prefix)->quotient($total)->toInt();
            $result[] = $next - $previous;
            $previous = $next;
        }

        return $result;
    }

    public function original(Order $order): array
    {
        return $this->purchased($order, 1);
    }

    public function successorOriginal(Order $order, ?ShipmentFinancialPlan $settledLoss = null): array
    {
        return $this->purchased($order, 2, $settledLoss);
    }

    private function purchased(Order $order, int $policy, ?ShipmentFinancialPlan $settledLoss = null): array
    {
        abort_unless(($order->shipping_quote['refund_policy_version'] ?? 1) === $policy, 409,
            'The purchased refund policy needs its supported review and funding workflow. Do not substitute older refund terms.');
        $funding = app(VoucherFundingService::class)->amounts($order, $settledLoss);
        abort_if($funding['promotion_blockers'] !== [], 409, implode(' ', $funding['promotion_blockers']));
        $units = [];
        $products = [];
        foreach ($order->items()->orderBy('id')->get() as $item) {
            $products[$item->id] = $item->product_id;
            abort_unless($item->quantity > 0 && $item->quantity <= 99, 409, 'The original purchased quantity needs review.');
            $gross = CodMoney::cents($item->subtotal);
            $parts = $this->allocate($gross, array_fill(0, $item->quantity, 1));
            foreach ($parts as $index => $cents) {
                $units[] = ['order_item_id' => $item->id, 'unit_position' => $index, 'gross_cents' => $cents];
            }
        }
        $gross = array_column($units, 'gross_cents');
        abort_unless(array_sum($gross) === CodMoney::cents($order->subtotal) && array_sum($gross) > 0, 409,
            'The original order items and merchandise total need review.');
        $commission = $this->allocate(SellerSettlementService::split(array_sum($gross))['commission_cents'], $gross);
        $seller = array_map(fn ($g, $c) => $g - $c, $gross, $commission);
        $allocations = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->get();
        $shopDiscount = $allocations->filter(fn ($a) => $a->redemption->terms['funding_party'] === 'shop')->sum('product_discount_cents');
        $platformDiscount = $allocations->filter(fn ($a) => $a->redemption->terms['funding_party'] === 'platform')->sum('product_discount_cents');
        abort_unless($shopDiscount <= array_sum($seller) && $shopDiscount + $platformDiscount <= array_sum($gross), 409,
            'Original product discounts exceed their evidenced merchandise basis.');
        $scoped = $allocations->filter(fn ($a) => ($a->redemption->terms['product_scope'] ?? 'all') === 'selected');
        $shopWeights = $seller;
        if ($scoped->isNotEmpty()) {
            abort_unless($scoped->count() === 1 && $scoped->first()->redemption->terms['funding_party'] === 'shop', 409,
                'The original voucher product selection needs review.');
            $ids = $scoped->first()->redemption->terms['product_ids'] ?? [];
            foreach ($units as $index => $unit) {
                if (! in_array($products[$unit['order_item_id']], $ids, true)) {
                    $shopWeights[$index] = 0;
                }
            }
            abort_unless($shopDiscount <= array_sum($shopWeights), 409, 'The original selected-product discount exceeds its seller share.');
        }
        $shop = $this->allocate($shopDiscount, $shopWeights);
        $platform = $this->allocate($platformDiscount, array_map(fn ($g, $s) => $g - $s, $gross, $shop));
        foreach ($units as $index => &$unit) {
            $unit += ['commission_cents' => $commission[$index], 'shop_discount_cents' => $shop[$index],
                'platform_discount_cents' => $platform[$index], 'buyer_cents' => $gross[$index] - $shop[$index] - $platform[$index],
                'seller_cents' => $seller[$index] - $shop[$index], 'platform_cents' => $commission[$index] - $platform[$index]];
        }

        return ['units' => $units, 'promotion_snapshot' => $funding['promotion_snapshot'],
            'original_seller_cents' => array_sum($seller) - $shopDiscount,
            'shipping_cents' => CodMoney::cents($order->shipping_fee),
            'shipping_discount_cents' => $allocations->sum('shipping_discount_cents')];
    }

    /** Original paid shipping allocations do not grant a refund or change purchased policy. */
    public function shipping(Order $order): array
    {
        $source = ($order->shipping_quote['refund_policy_version'] ?? 1) === 2
            ? $this->successorOriginal($order)
            : $this->original($order);

        return $this->shippingSource($order, $source);
    }

    private function shippingSource(Order $order, array $source): array
    {
        $paid = $source['shipping_cents'] - $source['shipping_discount_cents'];
        $net = array_column($source['units'], 'buyer_cents');
        abort_unless($paid >= 0 && $source['shipping_discount_cents'] >= 0
            && array_sum($net) + $paid === CodMoney::cents($order->total_amount), 409,
            'The original buyer-paid shipping and merchandise must match the purchased total.');
        $weights = array_sum($net) > 0 ? $net : array_column($source['units'], 'gross_cents');
        $allocations = $this->allocate($paid, $weights);
        $units = [];
        foreach ($source['units'] as $index => $unit) {
            $units[] = $unit + ['buyer_paid_shipping_cents' => $allocations[$index]];
        }

        return ['units' => $units, 'shipping_cents' => $source['shipping_cents'], 'shipping_discount_cents' => $source['shipping_discount_cents'],
            'buyer_paid_shipping_cents' => $paid, 'allocation_basis' => array_sum($net) > 0 ? 'net_merchandise' : 'gross_merchandise'];
    }

    public function shippingForCase(BuyerReturnCase $case): array
    {
        if (($case->order->shipping_quote['refund_policy_version'] ?? 1) === 2) {
            return $this->successorForCase($case);
        }

        return $this->claimedShipping($this->forCase($case), $this->shipping($case->order));
    }

    private function claimedShipping(array $basis, array $source): array
    {
        $wanted = array_fill_keys(array_map(fn ($unit) => $unit['order_item_id'].':'.$unit['unit_position'], $basis['unit_snapshot']), true);
        $units = array_values(array_filter($source['units'], fn ($unit) => isset($wanted[$unit['order_item_id'].':'.$unit['unit_position']])));
        abort_unless(count($units) === count($wanted), 409, 'Retain the original claimed shipping units before reviewing liability.');

        return ['buyer_paid_shipping_cents' => array_sum(array_column($units, 'buyer_paid_shipping_cents')), 'unit_snapshot' => $units,
            'source_snapshot' => array_diff_key($source, ['units' => true])];
    }

    public function forCase(BuyerReturnCase $case): array
    {
        return $this->caseBasis($case, $this->original($case->order));
    }

    /** Preparation only: existing policy-one payment consumers still reject policy two. */
    public function successorForCase(BuyerReturnCase $case): array
    {
        $source = $this->purchased($case->order, 2);
        $basis = $this->caseBasis($case, $source);
        $shipping = $this->claimedShipping($basis, $this->shippingSource($case->order, $source));

        return $basis + ['buyer_paid_shipping_cents' => $shipping['buyer_paid_shipping_cents'],
            'shipping_unit_snapshot' => $shipping['unit_snapshot'], 'shipping_source_snapshot' => $shipping['source_snapshot']];
    }

    private function caseBasis(BuyerReturnCase $case, array $source): array
    {
        $wanted = [];
        foreach ($case->items as $line) {
            $item = $case->order->items()->findOrFail($line->order_item_id);
            foreach (['shop_id', 'product_id', 'product_sale_id', 'quantity', 'unit_price', 'subtotal', 'color', 'size', 'sku_snapshot'] as $field) {
                abort_unless($line->purchase_snapshot[$field] === $item->$field, 409, 'An original purchased item needs evidence review.');
            }
            abort_unless(count($line->unit_positions) === $line->quantity && count(array_unique($line->unit_positions)) === $line->quantity, 409);
            foreach ($line->unit_positions as $position) {
                abort_unless(DB::table('buyer_return_unit_claims')->where('order_item_id', $line->order_item_id)
                    ->where('unit_position', $position)->where('buyer_return_case_id', $case->id)->exists(), 409,
                    'The original unit claim is unavailable for this return.');
                $wanted[$line->order_item_id.':'.$position] = true;
            }
        }
        $units = array_values(array_filter($source['units'], fn ($u) => isset($wanted[$u['order_item_id'].':'.$u['unit_position']])));
        abort_unless(count($units) === count($wanted) && count($units) > 0, 409, 'The original returned units need review.');
        $totals = [];
        foreach (['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents', 'buyer_cents', 'seller_cents', 'platform_cents'] as $field) {
            $totals[$field] = array_sum(array_column($units, $field));
        }

        return $totals + ['unit_snapshot' => $units, 'source_snapshot' => array_diff_key($source, ['units' => true])];
    }

    public function sellerBasis(Order $order, int $original): array
    {
        $basis = $this->adjustmentFinancials($order);
        abort_unless($original >= $basis['seller_cents'], 409, 'Approved seller reversals exceed the original proceeds.');

        return ['return_seller_cents' => $basis['seller_cents'],
            'payable_seller_cents' => $original - $basis['seller_cents'],
            'return_adjustment_ids' => $basis['adjustment_ids']];
    }

    public function adjustmentFinancials(Order $order): array
    {
        $adjustments = BuyerReturnAdjustment::where('order_id', $order->id)->orderBy('id')->get();
        $plans = Schema::hasTable('buyer_return_repayment_plans') ? BuyerReturnRepaymentPlan::whereIn('buyer_return_adjustment_id', $adjustments->modelKeys())
            ->with('approval')->get()->keyBy('buyer_return_adjustment_id') : collect();
        $totals = ['seller_cents' => 0, 'commission_cents' => 0, 'platform_discount_cents' => 0, 'adjustment_ids' => $adjustments->modelKeys()];
        foreach ($adjustments as $adjustment) {
            $policy = $adjustment->source_snapshot['refund_policy_version'] ?? 1;
            abort_unless(in_array($policy, [1, 2], true), 409, 'An approved refund basis needs its original supported policy.');
            if ($policy === 1) {
                foreach (['seller_cents', 'commission_cents', 'platform_discount_cents'] as $field) {
                    $totals[$field] += $adjustment->$field;
                }

                continue;
            }
            $plan = $plans->get($adjustment->id);
            $approval = $plan?->approval;
            abort_unless($plan && $plan->policy_version === 2 && $plan->order_id === $order->id && $plan->buyer_return_case_id === $adjustment->buyer_return_case_id
                && ($plan->snapshot['policy_version'] ?? null) === 2 && ($plan->snapshot['order_id'] ?? null) === $order->id
                && ($plan->snapshot['buyer_return_case_id'] ?? null) === $adjustment->buyer_return_case_id
                && ($adjustment->source_snapshot['repayment_plan_reference'] ?? null) === $plan->reference
                && $approval?->event_type === 'approve-repayment' && $approval->actor_role === 'admin' && $approval->actor_id === $plan->actor_id
                && $approval->order_id === $order->id && $approval->buyer_return_case_id === $plan->buyer_return_case_id
                && ($approval->context['repayment_plan_reference'] ?? null) === $plan->reference
                && ($approval->context['adjustment_id'] ?? null) === $adjustment->id && $approval->amount_cents === 0
                && $approval->sequence === ($plan->snapshot['case_version'] ?? 0) + 1, 409,
                'Retain the approved successor plan before changing original seller proceeds.');
            foreach (['seller_cents' => 'seller_reversal_cents', 'commission_cents' => 'commission_reversal_cents', 'platform_discount_cents' => 'promotion_reversal_cents'] as $field => $key) {
                $value = $plan->snapshot[$key] ?? null;
                abort_unless(is_int($value) && $value >= 0, 409, 'The original approved reversal amounts need review.');
                $totals[$field] += $value;
            }
        }

        return $totals;
    }
}
