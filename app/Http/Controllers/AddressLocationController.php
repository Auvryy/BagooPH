<?php

namespace App\Http\Controllers;

use App\Services\Commerce\PhilippineAddressCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressLocationController extends Controller
{
    public function __invoke(Request $request, PhilippineAddressCatalog $catalog): JsonResponse
    {
        $values = $request->validate([
            'region' => ['nullable', 'string', 'max:10'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($catalog->options($values))->header('Cache-Control', 'public, max-age=3600');
    }
}
