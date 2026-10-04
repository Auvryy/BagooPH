<?php

namespace App\Rules;

use App\Services\MasterCategoryService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MasterCategory implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(MasterCategoryService::class)->activeRoots()->whereKey($value)->exists()) {
            $fail(MasterCategoryService::ISSUE);
        }
    }
}
