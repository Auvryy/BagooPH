<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function apply(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $code = strtoupper(trim($request->input('code')));
        $user = $request->user();

        $cart = Cart::where('user_id', $user?->id)->with('items.product')->first();
        $subtotal = $cart ? (float) $cart->total : 0.0;
        $shippingFee = $subtotal > 1500 ? 0.0 : 50.0;

        $voucher = Voucher::where('code', $code)->where('is_active', true)->first();

        if (! $voucher) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Invalid voucher code.'], 422);
            }
            return back()->with('error', 'Invalid or inactive voucher code.');
        }

        if (! $voucher->isValidForAmount($subtotal)) {
            $msg = "Voucher requires a minimum spend of ₱" . number_format($voucher->min_spend, 2);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $discount = $voucher->calculateDiscount($subtotal, $shippingFee);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'voucher' => $voucher,
                'discount' => $discount,
                'message' => "Voucher '{$voucher->code}' applied: -₱" . number_format($discount, 2),
            ]);
        }

        return back()->with([
            'success' => "Voucher '{$voucher->code}' applied successfully!",
            'appliedVoucher' => [
                'code' => $voucher->code,
                'discount' => $discount,
                'type' => $voucher->discount_type,
            ],
        ]);
    }
}
