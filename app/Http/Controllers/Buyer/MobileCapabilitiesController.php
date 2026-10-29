<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\BuyerMobile\NativeGoogleConfiguration;
use Illuminate\Http\JsonResponse;

class MobileCapabilitiesController extends Controller
{
    public function __invoke(NativeGoogleConfiguration $google): JsonResponse
    {
        return response()->json([
            'contract_version' => 1,
            'features' => [
                'native_google_sign_in' => $google->ready(),
                'device_push' => false,
            ],
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Vary', 'Cookie');
    }
}
