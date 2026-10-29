<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\BuyerMobile\BuyerAppAssociations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppAssociationController extends Controller
{
    public function android(Request $request, BuyerAppAssociations $associations): JsonResponse
    {
        return $this->response($associations->android($request));
    }

    public function apple(Request $request, BuyerAppAssociations $associations): JsonResponse
    {
        return $this->response($associations->apple($request));
    }

    private function response(?array $association): JsonResponse
    {
        return response()->json($association ?? ['message' => 'Buyer app links are not configured.'], $association === null ? 404 : 200)
            ->header('Cache-Control', $association === null ? 'no-store, private' : 'public, max-age=300')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
