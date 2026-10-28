<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\LogisticsHub;
use App\Models\SavedVoucher;
use App\Models\VoucherRevision;
use App\Services\BuyerAccessService;
use App\Services\Logistics\LogisticsCheckoutService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\VoucherPreviewService;
use App\Services\Orders\VoucherSelectionService;
use App\Services\Vouchers\SavedVoucherService;
use App\Services\Vouchers\VoucherChoiceService;
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
            'logistics_company_id' => ['nullable', 'integer', 'min:1'],
            'shipping_postal_code' => ['nullable', 'string', 'regex:/\A[0-9]{4}\z/'],
            'destination_barangay' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $selection = app(VoucherSelectionService::class)->normalize($input);
        $companies = null;
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
            $items = $quotes->selectedItems($buyer, $data['item_ids'], $input['item_quantities'] ?? []);
            $companies = app(LogisticsCheckoutService::class)->choices($items, $data);
            $company = app(LogisticsCheckoutService::class)->select($items, $data, $data['logistics_company_id'] ?? null, requireRoute: false);
            if (! empty($data['logistics_company_id']) && ! $company) {
                throw new CheckoutException('The selected company does not serve this address. Choose another company or address.');
            }
            $preview = app(VoucherPreviewService::class)->preview($buyer, $items, $data['delivery_type'] ?? 'doorstep', $selection, $company);
            $quote = $preview['quote'];
            $available = $quotes->offers($items, $data['delivery_type'] ?? 'doorstep', buyer: $buyer, selected: $preview['revisions'], company: $company);
            $message = $quote['voucher_error'] ?? ($quote['vouchers']
                ? 'Vouchers applied: -₱'.number_format($quote['discount'], 2).'.'
                : 'Your checkout total is up to date.');
            if ($request->expectsJson()) {
                return response()->json(['success' => $quote['voucher_error'] === null, 'quote' => $quote, 'logisticsCompanies' => $companies,
                    'voucher' => $quote['voucher'], 'discount' => $quote['discount'],
                    'availableVouchers' => $available['offers'], 'validSelection' => $preview['validSelection'],
                    'selectionErrors' => $preview['selectionErrors'], 'message' => $message], $quote['voucher_error'] ? 422 : 200);
            }

            return back()->with($quote['voucher_error'] ? 'error' : 'success', $message);
        } catch (CheckoutException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage(), 'logisticsCompanies' => $companies], 422);
            }

            return back()->with('error', $exception->getMessage());
        }
    }

    public function store(Request $request, VoucherRevision $revision): JsonResponse
    {
        $saved = app(SavedVoucherService::class)->save($request->user(), $revision);

        return response()->json(['saved_id' => $saved->id]);
    }

    public function destroy(Request $request, int $saved): JsonResponse
    {
        $bookmark = new SavedVoucher;
        $bookmark->id = $saved;
        app(SavedVoucherService::class)->remove($request->user(), $bookmark);

        return response()->json(['saved_id' => null]);
    }

    public function choices(Request $request): JsonResponse
    {
        return $this->choose($request, false);
    }

    public function best(Request $request): JsonResponse
    {
        return $this->choose($request, true);
    }

    private function choose(Request $request, bool $best): JsonResponse
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($request->user());
        $data = $request->validate([
            'item_ids' => 'required|array|list|min:1', 'delivery_type' => ['nullable', Rule::in(['doorstep', 'hub_self_pickup'])],
            'shop_id' => 'nullable|integer|min:1|max:2147483647', 'voucher_page' => 'nullable|integer|min:1|max:100000',
            'shipping_province' => 'nullable|string|max:100', 'shipping_city' => 'nullable|string|max:100',
            'pickup_hub_id' => 'nullable|integer|min:1',
            'logistics_company_id' => 'nullable|integer|min:1',
            'shipping_postal_code' => ['nullable', 'string', 'regex:/\A[0-9]{4}\z/'],
            'destination_barangay' => 'nullable|string|max:100',
        ]);
        try {
            if (! empty($data['shipping_province']) && ! empty($data['shipping_city'])
                && ! app(LogisticsRoutingEngine::class)->isContiguousRoadServiceable($data['shipping_province'], $data['shipping_city'])) {
                throw new CheckoutException('Delivery address is outside contiguous road freight boundaries. Maritime shipping is excluded.');
            }
            if (! empty($data['pickup_hub_id']) && ($data['delivery_type'] ?? 'doorstep') === 'hub_self_pickup'
                && ! LogisticsHub::eligible()->whereKey($data['pickup_hub_id'])->where('tier', 'local_bayan_hub')->where('allows_self_pickup', true)->exists()) {
                throw new CheckoutException('Choose an available Bayan Hub that supports self-pickup.');
            }
            $items = app(CheckoutQuoteService::class)->selectedItems($buyer, $data['item_ids'], $request->input('item_quantities', []));
            $company = app(LogisticsCheckoutService::class)->select($items, $data, $data['logistics_company_id'] ?? null, requireRoute: false);
            $choices = app(VoucherChoiceService::class);
            $result = $best ? $choices->best($buyer, $items, $data['delivery_type'] ?? 'doorstep', $company)
                : $choices->options($buyer, $items, $data['delivery_type'] ?? 'doorstep', $request->all(), isset($data['shop_id']) ? (int) $data['shop_id'] : null, $company);

            return response()->json($result);
        } catch (CheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
