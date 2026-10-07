<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BuyerAccessService;
use App\Services\Logistics\PickupClaimService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PickupClaimController extends Controller
{
    public function issue(Request $request, Order $order, PickupClaimService $service): JsonResponse
    {
        app(BuyerAccessService::class)->requireExistingOrders($request->user(), $order);
        abort_unless($order->delivery, 404);
        try {
            $code = $service->issue($order->delivery, $request->user());
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409)->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['code' => $code])->header('Cache-Control', 'no-store, private');
    }
}
