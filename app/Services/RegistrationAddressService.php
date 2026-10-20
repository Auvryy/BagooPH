<?php

namespace App\Services;

use App\Services\Commerce\PhilippineAddressCatalog;
use Illuminate\Support\Facades\Validator;

class RegistrationAddressService
{
    public function rules(): array
    {
        return ['region' => ['nullable', 'string', 'regex:/\A[0-9]{10}\z/']];
    }

    public function resolve(array $values): array
    {
        if (array_key_exists('region', $values) && empty($values['region'])
            && collect($values)->only(['address', 'province', 'city', 'barangay'])->contains(fn ($value) => filled($value))) {
            Validator::make($values, ['region' => ['required']])->validate();
        }
        // Older clients submit address labels without a region code.
        if (empty($values['region'])) {
            return $values;
        }
        Validator::make($values, array_fill_keys(['province', 'city', 'barangay'], ['required', 'string']))->validate();
        $resolved = app(PhilippineAddressCatalog::class)->validate($values);

        return [...$resolved, 'municipality' => $resolved['city']];
    }
}
