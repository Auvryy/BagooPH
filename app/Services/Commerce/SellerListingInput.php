<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use App\Rules\AsciiPositiveInteger;
use App\Services\ShopEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SellerListingInput
{
    public function validate(Request $request, Shop $shop, ?Product $product = null): array
    {
        $publishing = $request->input('status', 'active') === 'active';
        $presence = $publishing ? 'required' : 'nullable';
        $values = $request->validate([
            'name' => ['required', 'string', 'min:5', 'max:120'],
            'category_id' => [$presence, 'exists:categories,id'],
            'weight_kg' => [$presence, 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0.01,50000'],
            'brand' => ['nullable', 'string', 'max:100', 'not_regex:/[<>\p{C}]/u'],
            'condition' => ['nullable', Rule::in(['new', 'used'])],
            'length_cm' => ['nullable', 'required_with:width_cm,height_cm', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0.01,10000'],
            'width_cm' => ['nullable', 'required_with:length_cm,height_cm', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0.01,10000'],
            'height_cm' => ['nullable', 'required_with:length_cm,width_cm', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0.01,10000'],
            'price' => [$presence, 'numeric', 'between:1,1000000', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/'],
            'compare_at_price' => ['nullable', 'numeric', 'gt:price', 'max:99999999.99', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/'],
            'stock' => [$presence, 'integer', 'between:0,99999'],
            'purchase_limit' => ['nullable', new AsciiPositiveInteger, 'integer', 'between:1,1000000'],
            'sku' => ['nullable', 'string', 'max:50', Rule::unique(Product::class, 'sku')->ignore($product?->id)],
            'description' => [$presence, 'string', 'min:20', 'max:3000'],
            'featured_image' => ['nullable', 'string'],
            'image_file' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'image_files' => ['nullable', 'array', 'list', 'max:9'],
            'image_files.*' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'gallery_manifest' => ['nullable', 'string'],
            'variants' => ['nullable'],
            'status' => [$product ? 'required' : 'sometimes', 'required', Rule::in($product ? ['active', 'draft', 'archived'] : ['active', 'draft'])],
            'compliance_restricted' => ['prohibited'],
            'moderation_version' => ['prohibited'],
        ], [
            'compare_at_price.gt' => 'The slashed price must be higher than the regular selling price.',
            'image_files.*.max' => 'Each product image must not exceed 5 MB.',
            'image_files.*.mimes' => 'Use PNG, JPG, JPEG, WEBP or GIF images.',
            'category_id.required' => 'Choose the product department before publishing.',
            'weight_kg.required' => 'Enter the declared product weight in kilograms before publishing.',
        ]);
        app(ProductGalleryInput::class)->validate($request, $values['status'] ?? 'active', $product);
        $values['category_id'] = app(ShopEligibilityService::class)->categoryFor($shop, $values['category_id'] ?? null);
        $values['price'] ??= $product?->price ?? '0.00';
        $values['stock'] ??= $product?->stock ?? 0;
        $values['description'] ??= $product?->description ?? '';
        $values['weight_kg'] ??= $product?->weight_kg;

        return $values;
    }
}
