<?php

namespace App\Rules;

use App\Models\Shop;
use App\Services\ApplicationIdentityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueShopName implements ValidationRule
{
    public function __construct(private ?int $shopId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Shop::where('name_key', ApplicationIdentityService::shopNameKey($value))
            ->when($this->shopId !== null, fn ($query) => $query->where('id', '<>', $this->shopId))->exists()) {
            $fail('This shop name is already taken. Choose a different name.');
        }
    }
}
