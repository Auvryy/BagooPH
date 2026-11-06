<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Commerce\StoreTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerTagAssistantController extends Controller
{
    use HasSellerShop;

    public function assist(Request $request, StoreTagService $tags): JsonResponse
    {
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'min:1', 'max:120'],
            'tags' => ['nullable', 'array', 'list', 'max:20'],
            'tags.*' => ['required', 'string', 'min:1', 'max:50'],
        ]);

        return response()->json(['tags' => $tags->suggest($this->getActiveShop($request), trim($validated['product_name']), $validated['tags'] ?? [])]);
    }
}
