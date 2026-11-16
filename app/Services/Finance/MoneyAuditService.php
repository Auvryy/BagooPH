<?php

namespace App\Services\Finance;

use App\Models\CodAccount;
use App\Models\LogisticsEarning;
use App\Models\Order;
use App\Services\Logistics\LogisticsRateService;
use App\Services\Vouchers\VoucherFundingService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read-only check that every peso of each order lands where it should:
 * the buyer total, the seller and platform shares, the shipping shares,
 * and the cash on delivery record must all add up to the same order.
 */
class MoneyAuditService
{
    /** @return array{orders:int, checked:int, issues:list<array{order:string, check:string, detail:string}>} */
    public function run(): array
    {
        $issues = [];
        $checked = 0;
        $orders = Order::query()->orderBy('id')->get();
        $earnings = LogisticsEarning::whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        $cash = CodAccount::whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        foreach ($orders as $order) {
            $rows = $earnings->get($order->id, collect());
            $before = count($issues);
            $this->orderTotal($order, $issues);
            $order->status === 'cancelled'
                ? $this->cancelled($order, $rows, $cash->get($order->id, collect()), $issues)
                : $this->shares($order, $rows, $issues);
            $this->cash($order, $cash->get($order->id, collect()), $issues);
            $checked += count($issues) === $before ? 1 : 0;
        }

        return ['orders' => $orders->count(), 'checked' => $checked, 'issues' => $issues];
    }

    private function orderTotal(Order $order, array &$issues): void
    {
        $expected = CodMoney::cents($order->subtotal) + CodMoney::cents($order->shipping_fee) - CodMoney::cents($order->voucher_discount);
        if ($expected !== CodMoney::cents($order->total_amount)) {
            $this->issue($issues, $order, 'Order total', 'Items + shipping - discount is '.$this->peso($expected).' but the order total is '.$this->peso(CodMoney::cents($order->total_amount)).'.');
        }
    }

    private function shares(Order $order, Collection $rows, array &$issues): void
    {
        if ($rows->isEmpty()) {
            return; // Shares are recorded when the parcel is handed over; nothing to reconcile yet.
        }
        $subtotal = CodMoney::cents($order->subtotal);
        $products = $rows->where('pool', 'products');
        if ($products->isNotEmpty()) {
            $split = SellerSettlementService::split($subtotal);
            $seller = $products->firstWhere('role', 'seller');
            $platform = $products->firstWhere('role', 'platform');
            if ($products->sum('amount_cents') !== $subtotal) {
                $this->issue($issues, $order, 'Seller and platform shares', 'They add up to '.$this->peso($products->sum('amount_cents')).' instead of the item subtotal '.$this->peso($subtotal).'.');
            }
            if ($seller && $seller->amount_cents !== $split['seller_cents']) {
                $this->issue($issues, $order, 'Seller share', 'Seller gets '.$this->peso($seller->amount_cents).'; 90% of items is '.$this->peso($split['seller_cents']).'.');
            }
            if ($platform && $platform->amount_cents !== $split['commission_cents']) {
                $this->issue($issues, $order, 'Platform commission', 'Commission is '.$this->peso($platform->amount_cents).'; 10% of items is '.$this->peso($split['commission_cents']).'.');
            }
            try {
                $funding = app(VoucherFundingService::class)->amounts($order);
                $net = $products->sum('net_amount_cents');
                $expectedNet = $subtotal - $funding['seller_promotion_cents'] - $funding['platform_promotion_cents'];
                if ($net !== $expectedNet) {
                    $this->issue($issues, $order, 'Voucher funding', 'Shares after vouchers are '.$this->peso($net).' instead of '.$this->peso($expectedNet).'.');
                }
            } catch (Throwable $error) {
                $this->issue($issues, $order, 'Voucher funding', 'Could not read the voucher funding: '.$error->getMessage());
            }
        }
        $shipping = $rows->where('pool', 'shipping');
        $fee = CodMoney::cents($order->shipping_fee);
        if ($shipping->isNotEmpty() && $order->status === 'completed' && $shipping->sum('amount_cents') !== $fee) {
            $this->issue($issues, $order, 'Shipping shares', 'Pickup, delivery and company shares add up to '.$this->peso($shipping->sum('amount_cents')).' instead of the shipping fee '.$this->peso($fee).'.');
        }
        try {
            $split = app(LogisticsRateService::class)->purchasedShippingSplit($order);
            foreach (['pickup', 'delivery', 'company'] as $role) {
                $row = $shipping->firstWhere('role', $role);
                if ($row && $row->amount_cents !== $split[$role]) {
                    $this->issue($issues, $order, ucfirst($role).' share', 'Recorded '.$this->peso($row->amount_cents).' but the order saved '.$this->peso($split[$role]).'.');
                }
            }
        } catch (Throwable $error) {
            $this->issue($issues, $order, 'Shipping split', $error->getMessage());
        }
    }

    private function cancelled(Order $order, Collection $rows, Collection $cash, array &$issues): void
    {
        if ($rows->whereNull('reversed_at')->where('pool', 'products')->isNotEmpty()) {
            $this->issue($issues, $order, 'Cancelled order', 'A cancelled order still pays a seller or platform share.');
        }
        if ($cash->contains(fn (CodAccount $account) => ($account->state['collected_cents'] ?? 0) > 0)) {
            $this->issue($issues, $order, 'Cancelled order', 'Cash was collected for a cancelled order.');
        }
    }

    private function cash(Order $order, Collection $accounts, array &$issues): void
    {
        foreach ($accounts as $account) {
            $parts = [
                'expected' => [$account->expected_cents, CodMoney::cents($order->total_amount)],
                'items' => [$account->product_subtotal_cents, CodMoney::cents($order->subtotal)],
                'shipping' => [$account->shipping_cents, CodMoney::cents($order->shipping_fee)],
                'discount' => [$account->discount_cents, CodMoney::cents($order->voucher_discount)],
            ];
            foreach ($parts as $label => [$recorded, $order_value]) {
                if ($recorded !== $order_value) {
                    $this->issue($issues, $order, 'Cash on delivery', "The cash record's {$label} is ".$this->peso($recorded).' but the order says '.$this->peso($order_value).'.');
                }
            }
            $state = $account->state ?? [];
            $collected = (int) ($state['collected_cents'] ?? 0);
            if ($collected > 0 && $collected !== $account->expected_cents && empty($state['discrepancies'])) {
                $this->issue($issues, $order, 'Cash on delivery', 'Collected '.$this->peso($collected).' but expected '.$this->peso($account->expected_cents).' with no recorded discrepancy.');
            }
            $held = array_sum(array_map('intval', $state['balances'] ?? []));
            $reconciled = (int) ($state['reconciled_cents'] ?? 0);
            if ($collected > 0 && empty($state['discrepancies'])) {
                // Until the platform reconciles the cash, riders and hubs together hold every collected peso;
                // after reconciliation the reconciled amount must equal what was collected.
                if ($reconciled > 0 ? $reconciled !== $collected : $held !== $collected) {
                    $this->issue($issues, $order, 'Cash on delivery', $reconciled > 0
                        ? 'Reconciled '.$this->peso($reconciled).' but collected '.$this->peso($collected).'.'
                        : 'Cash held by riders and hubs ('.$this->peso($held).') does not account for the '.$this->peso($collected).' collected.');
                }
            }
        }
    }

    private function issue(array &$issues, Order $order, string $check, string $detail): void
    {
        $issues[] = ['order' => $order->order_number, 'check' => $check, 'detail' => $detail];
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
