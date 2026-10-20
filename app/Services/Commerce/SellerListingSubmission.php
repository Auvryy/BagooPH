<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SellerListingSubmission
{
    /** The caller holds the owning user/shop locks and the surrounding transaction. */
    public function run(Request $request, Shop $shop, string $action, Closure $write): Product
    {
        $data = $request->validate(['request_token' => ['nullable', 'uuid']]);
        if (empty($data['request_token'])) {
            return $write();
        }
        $input = $request->input();
        unset($input['_token'], $input['_method'], $input['request_token']);
        $fingerprint = hash('sha256', json_encode($this->canonical([$action, $input, $request->allFiles()]), JSON_THROW_ON_ERROR));
        $query = DB::table('seller_product_submissions')->where('shop_id', $shop->id)->where('request_token', $data['request_token']);
        $saved = (clone $query)->lockForUpdate()->first();
        if ($saved) {
            if (! hash_equals($saved->payload_hash, $fingerprint)) {
                throw ValidationException::withMessages(['request_token' => 'This save was already used for different details. Start a new listing before saving again.']);
            }
            $product = Product::where('shop_id', $shop->id)->find($saved->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['request_token' => 'The original listing was removed. Start a new listing to create another one.']);
            }

            return $product;
        }
        $product = $write();
        DB::table('seller_product_submissions')->insert(['shop_id' => $shop->id, 'request_token' => $data['request_token'],
            'payload_hash' => $fingerprint, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);

        return $product;
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['contents' => hash_file('sha256', $value->getPathname())];
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
