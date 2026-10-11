<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\LogisticsHub;
use App\Models\Voucher;
use App\Services\BuyerAccessService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Orders\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function apply(Request $request): JsonResponse|RedirectResponse
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($request->user());
        $input = $request->all();
        if (is_string($input['code'] ?? null)) {
            $input['code'] = strtoupper(trim($input['code'], ' '));
        }
        $data = validator($input, [
            'code' => ['nullable', 'string', 'max:50', 'regex:/\A[A-Z0-9_-]+\z/'],
            'item_ids' => ['required', 'array', 'list', 'min:1'],
            'delivery_type' => ['nullable', Rule::in(['doorstep', 'hub_self_pickup'])],
            'shipping_province' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'pickup_hub_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        try {
            if (! empty($data['shipping_province']) && ! empty($data['shipping_city'])
                && ! app(LogisticsRoutingEngine::class)->isContiguousRoadServiceable($data['shipping_province'], $data['shipping_city'])) {
                throw new CheckoutException('Delivery address is outside contiguous road freight boundaries. Maritime shipping is excluded.');
            }
            if (! empty($data['pickup_hub_id']) && ($data['delivery_type'] ?? 'doorstep') === 'hub_self_pickup'
                && ! LogisticsHub::eligible()->whereKey($data['pickup_hub_id'])->where('tier', 'local_bayan_hub')->where('allows_self_pickup', true)->exists()) {
                throw new CheckoutException('Choose an available Bayan Hub that supports self-pickup.');
            }
            $quotes = app(CheckoutQuoteService::class);
            $items = $quotes->selectedItems($buyer, $data['item_ids']);
            $code = $data['code'] ?? '';
            $voucher = $code !== '' ? Voucher::where('code', $code)->first() : null;
            $quote = $quotes->quote($items, $data['delivery_type'] ?? 'doorstep', $voucher);
            if ($code !== '' && ! $voucher) {
                $quote['voucher_error'] = 'This voucher is unavailable. Remove it or choose another voucher.';
            }
            $available = $quotes->offers($items, $data['delivery_type'] ?? 'doorstep');
            $message = $quote['voucher_error'] ?? ($quote['voucher']
                ? 'Voucher applied: -₱'.number_format($quote['discount'], 2).'.'
                : 'Your checkout total is up to date.');
            if ($request->expectsJson()) {
                return response()->json(['success' => $quote['voucher_error'] === null, 'quote' => $quote,
                    'voucher' => $quote['voucher'], 'discount' => $quote['discount'],
                    'availableVouchers' => $available['offers'], 'message' => $message], $quote['voucher_error'] ? 422 : 200);
            }

            return back()->with($quote['voucher_error'] ? 'error' : 'success', $message);
        } catch (CheckoutException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }
    }
}
