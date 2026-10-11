<?php

namespace App\Services\Vouchers;

use App\Models\Order;
use App\Models\VoucherOrderAllocation;
use App\Services\Finance\CodMoney;

class VoucherReceiptService
{
    public function present(Order $order): array
    {
        $allocations = VoucherOrderAllocation::with('redemption')->where('order_id', $order->id)->orderBy('id')->get();
        $discounts = $allocations->map(fn ($allocation) => [
            'code' => $allocation->redemption->terms['code'], 'name' => $allocation->redemption->terms['name'],
            'issuer' => $allocation->redemption->terms['issuer_type'],
            'product_discount' => $allocation->product_discount_cents / 100,
            'shipping_discount' => $allocation->shipping_discount_cents / 100,
        ])->all();
        $shippingDiscount = $allocations->sum('shipping_discount_cents');
        $shipping = CodMoney::cents($order->shipping_fee);

        return ['merchandise' => CodMoney::cents($order->subtotal) / 100, 'shipping_before' => $shipping / 100,
            'shipping_after' => ($shipping - $shippingDiscount) / 100, 'discounts' => $discounts,
            'total_savings' => CodMoney::cents($order->voucher_discount ?? '0.00') / 100,
            'total' => CodMoney::cents($order->total_amount) / 100,
            'legacy_discount' => $order->voucher_accounting_version < 1 && CodMoney::cents($order->voucher_discount ?? '0.00') > 0];
    }
}
