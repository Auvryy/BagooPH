<?php

namespace App\Rules;

use App\Models\LogisticsCompany;
use App\Services\ApplicationIdentityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueCompanyName implements ValidationRule
{
    public function __construct(private ?int $companyId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (LogisticsCompany::where('name_key', ApplicationIdentityService::shopNameKey($value))
            ->when($this->companyId !== null, fn ($query) => $query->where('id', '<>', $this->companyId))->exists()) {
            $fail('This company name is already registered. Choose a different name.');
        }
    }
}
