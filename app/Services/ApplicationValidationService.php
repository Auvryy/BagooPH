<?php

namespace App\Services;

use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\BirthDate;
use App\Rules\MasterCategory;
use App\Rules\PhilippineContact;
use App\Rules\PhilippineLicenseNumber;
use App\Rules\PhilippinePlateNumber;
use App\Rules\UniqueAccountEmail;
use App\Rules\UniqueAccountMobile;
use App\Rules\UniqueShopName;
use App\Rules\WholeNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Normalizer;

class ApplicationValidationService
{
    public const ACCOUNT_FIELDS = ['name', 'first_name', 'last_name', 'middle_name', 'sex', 'birthday', 'email', 'phone', 'address', 'city', 'province', 'municipality', 'barangay', 'postal_code'];

    public const VEHICLES = ['Motorcycle', 'Scooter', 'Sedan / Van'];

    public const FLEET_TYPES = ['motorcycle', 'l300_van', 'wing_truck'];

    public static function emailRules(): array
    {
        return ['bail', 'required', 'string', 'max:255', 'email:rfc,strict', 'not_regex:/[\p{C}\s]/u'];
    }

    public function normalize(array $data, string $role): array
    {
        foreach ($data as $field => $value) {
            if (! is_string($value) || ! in_array($field, $this->fields($role), true) || preg_match('/\p{C}/u', $value)) {
                continue;
            }
            // Phone/postal digits must be ASCII before normalization can hide look-alikes.
            $value = in_array($field, ['phone', 'shop_phone', 'company_phone', 'postal_code'], true) ? trim($value, ' ') : trim(Normalizer::normalize($value, Normalizer::FORM_KC), ' ');
            if (in_array($field, ['phone', 'shop_phone', 'company_phone'], true)) {
                $landline = $field !== 'phone';
                $value = PhilippineContact::canonical($value, $landline) ?? $value;
            } elseif (in_array($field, ['email', 'company_email'], true) && str_contains($value, '@')) {
                [$local, $domain] = explode('@', $value, 2);
                $value = $local.'@'.strtolower($domain);
            } elseif (in_array($field, ['plate_number', 'license_number', 'company_code', 'franchise_number'], true)) {
                $value = preg_replace('/[ -]+/', '-', strtoupper($value));
            }
            $data[$field] = $value === '' ? null : $value;
        }

        return $data;
    }

    public function rules(string $role, ?User $user = null): array
    {
        $worker = in_array($role, ['seller', 'courier', 'logistics'], true);
        $text = fn (string $kind, int $min, int $max, bool $required = false) => ['bail', $required ? 'required' : 'nullable', 'string', new ApplicationText($kind, $min, $max)];
        $rules = [
            'name' => $text('name', 2, 100, true),
            'first_name' => $text('name', 2, 50), 'middle_name' => $text('name', 1, 50), 'last_name' => $text('name', 2, 50),
            'sex' => ['bail', 'required', 'string', Rule::in(['Female', 'Male', 'Other'])],
            'birthday' => [$worker ? 'required' : 'nullable', new BirthDate($worker)],
            'email' => [...self::emailRules(), new UniqueAccountEmail($user?->id)],
            'phone' => ['bail', $worker ? 'required' : 'nullable', 'string', new PhilippineContact, new UniqueAccountMobile($user?->id)],
            'address' => $text('address', 5, 500, $worker),
            'city' => $text('location', 2, 255, $worker),
            'province' => $text('location', 2, 255), 'municipality' => $text('location', 2, 255), 'barangay' => $text('location', 2, 255),
            'postal_code' => ['bail', 'nullable', 'string', 'regex:/\A[0-9]{4}\z/'],
        ];
        if ($role === 'seller') {
            $rules += ['shop_name' => [...$text('text', 2, 255, true), new UniqueShopName($user?->shop?->id)], 'root_category_id' => ['bail', 'required', 'integer', new MasterCategory],
                'shop_phone' => ['bail', 'required', 'string', new PhilippineContact(true)], 'shop_address' => $text('address', 5, 500, true), 'shop_city' => $text('location', 2, 255, true)];
        } elseif ($role === 'courier') {
            $rules += ['vehicle_type' => ['bail', 'required', 'string', Rule::in(self::VEHICLES)], 'plate_number' => $text('code', 2, 50, true), 'license_number' => $text('code', 2, 50)];
        } elseif ($role === 'logistics') {
            $rules += ['company_name' => $text('text', 2, 255, true), 'company_code' => [...$text('code', 2, 20, $user?->logisticsCompany !== null), Rule::unique('logistics_companies', 'code')->ignore($user?->logisticsCompany?->id)],
                'company_email' => self::emailRules(),
                'company_phone' => ['bail', 'required', 'string', new PhilippineContact(true)], 'company_address' => $text('address', 5, 500, true),
                'operating_province' => $text('location', 2, 255),
                'franchise_number' => [...$text('code', 2, 100), Rule::notIn(['PENDING-LTFRB'])], 'fleet_size' => ['nullable', 'integer', 'min:1', 'max:10000'],
                'vehicle_types' => ['nullable', 'array', 'max:3'], 'vehicle_types.*' => ['bail', 'required', 'string', 'distinct', Rule::in(self::FLEET_TYPES)]];
        }

        return $rules;
    }

    public function fields(string $role): array
    {
        return array_filter(array_keys($this->rules($role)), fn ($key) => ! str_contains($key, '.'));
    }

    public function registrationValues(array $data, string $role): array
    {
        if ($role === 'seller') {
            $data = array_replace($data, ['shop_phone' => $data['phone'] ?? null, 'shop_address' => $data['address'] ?? null, 'shop_city' => $data['city'] ?? null]);
        } elseif ($role === 'logistics') {
            $data = array_replace($data, ['company_email' => $data['email'] ?? null, 'company_phone' => $data['phone'] ?? null, 'company_address' => $data['address'] ?? null, 'operating_province' => $data['operating_province'] ?? $data['province'] ?? null]);
        }

        $data = $this->submissionValues($data, $role);
        foreach (['email', 'company_email'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = mb_strtolower($data[$field]);
            }
        }

        return $data;
    }

    public function submissionValues(array $data, string $role): array
    {
        $identifiers = array_intersect_key($data, array_flip(['plate_number', 'license_number', 'company_code', 'franchise_number']));
        $data = $this->normalize($data, $role);
        if (isset($data['middle_initial']) && is_string($data['middle_initial']) && ! preg_match('/\p{C}/u', $data['middle_initial'])) {
            $data['middle_initial'] = trim(Normalizer::normalize($data['middle_initial'], Normalizer::FORM_KC), ' ') ?: null;
        }
        // Reject malformed separators and non-ASCII look-alikes instead of repairing identifiers.
        foreach ($identifiers as $field => $value) {
            $data[$field] = is_string($value) && ! preg_match('/\p{C}/u', $value) ? strtoupper(trim($value, ' ')) : $value;
        }
        if (isset($data['license_number']) && is_string($data['license_number'])) {
            $data['license_number'] = PhilippineLicenseNumber::canonical($data['license_number']);
        }

        return $data;
    }

    public function correctionValues(array $data, User $user): array
    {
        $data = $this->submissionValues($data, $user->role);
        $components = array_intersect_key($data, array_flip(['first_name', 'middle_name', 'last_name']));
        $hasParts = $user->first_name || $user->last_name || array_filter($components, fn ($value) => $value !== null && $value !== '');
        if ($components && $hasParts) {
            $parts = array_replace($user->only(['first_name', 'middle_name', 'last_name']), $components);
            $rules = $this->rules($user->role, $user);
            foreach (['first_name', 'last_name'] as $field) {
                $rules[$field] = array_map(fn ($rule) => $rule === 'nullable' ? 'required' : $rule, $rules[$field]);
            }
            Validator::make($parts, array_intersect_key($rules, $parts))->validate();
            $data['name'] = implode(' ', array_filter($parts, fn ($value) => $value !== null && $value !== ''));
        }

        return $data;
    }

    public function submissionFieldRules(string $role, mixed $vehicleType = null, ?User $user = null): array
    {
        return match ($role) {
            'seller' => ['root_category_id' => ['bail', 'required', new WholeNumber(PHP_INT_MAX), 'integer', new MasterCategory]],
            'courier' => [
                'plate_number' => ['bail', 'required', 'string', new PhilippinePlateNumber($vehicleType)],
                'license_number' => ['bail', 'required', 'string', new PhilippineLicenseNumber],
            ],
            'logistics' => [
                'company_code' => ['bail', 'required', 'string', 'regex:/\A[A-Z]{2,10}\z/', Rule::unique('logistics_companies', 'code')->ignore($user?->logisticsCompany?->id)],
                'fleet_size' => ['bail', 'nullable', new WholeNumber(10000)],
            ],
            default => [],
        };
    }

    public function submissionMessages(): array
    {
        return ['company_code.regex' => 'Use 2–10 letters for your company initials, without numbers or symbols.'];
    }

    public function fieldErrors(array $values, string $role, ?User $user = null): array
    {
        return Validator::make($this->normalize($values, $role), $this->rules($role, $user))->errors()->toArray();
    }

    public function values(User $user): array
    {
        $data = $user->only(self::ACCOUNT_FIELDS);
        $data['birthday'] = $user->birthday?->toDateString();
        if ($user->isSeller() && $shop = $user->shop) {
            $data += ['shop_name' => $shop->name, 'root_category_id' => $shop->root_category_id, 'shop_phone' => $shop->phone, 'shop_address' => $shop->address, 'shop_city' => $shop->city];
        } elseif ($user->isCourier() && $profile = $user->courierProfile) {
            $data += $profile->only(['vehicle_type', 'plate_number', 'license_number']);
        } elseif ($user->isLogistics() && $company = $user->logisticsCompany) {
            $data += ['company_name' => $company->name, 'company_code' => $company->code, 'company_email' => $company->contact_email, 'company_phone' => $company->contact_phone, 'company_address' => $company->address];
            $data += array_intersect_key($company->accreditation_details ?? [], array_flip(['operating_province', 'franchise_number', 'fleet_size', 'vehicle_types']));
        }

        return $data;
    }

    public function errors(User $user): array
    {
        // Readiness inspects a normalized copy. It never writes legacy identity or manufactures missing details.
        return $this->fieldErrors($this->values($user), $user->role, $user);
    }

    public function form(User $user): array
    {
        $labels = [
            'first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name / initial',
            'sex' => 'Sex', 'email' => 'Account email', 'phone' => 'Account mobile number', 'address' => 'Street address', 'city' => 'City',
            'province' => 'Province', 'municipality' => 'Municipality', 'barangay' => 'Barangay', 'postal_code' => 'Postal code',
            'shop_name' => 'Shop name', 'shop_phone' => 'Shop contact number', 'shop_address' => 'Shop street address', 'shop_city' => 'Shop city',
            'vehicle_type' => 'Vehicle type', 'plate_number' => 'Plate number', 'license_number' => 'License number',
            'company_name' => 'Company name', 'company_code' => 'Company initials', 'company_email' => 'Company contact email',
            'company_phone' => 'Company contact number', 'company_address' => 'Company street address',
            'operating_province' => 'Declared operating province',
            'franchise_number' => 'Declared franchise number', 'fleet_size' => 'Declared fleet size', 'vehicle_types' => 'Vehicle categories',
        ];
        $fields = [];
        foreach ($this->rules($user->role, $user) as $field => $rules) {
            if (! isset($labels[$field])) {
                continue;
            }
            $options = match ($field) {
                'sex' => ['Female', 'Male', 'Other'], 'vehicle_type' => self::VEHICLES, 'vehicle_types' => self::FLEET_TYPES, default => []
            };
            [$minimum, $maximum] = match (true) {
                in_array($field, ['first_name', 'last_name']) => [2, 50], $field === 'middle_name' => [1, 50],
                $field === 'postal_code' => [4, 4], $field === 'plate_number' => [5, 8], $field === 'license_number' => [11, 13],
                $field === 'company_code' => [2, 10], $field === 'fleet_size' => [1, 5], $field === 'franchise_number' => [2, 100],
                str_contains($field, 'phone') => [null, 24], str_contains($field, 'address') => [5, 500],
                str_contains($field, 'email') => [null, 255], default => [2, 255],
            };
            $required = in_array('required', $rules, true) || (in_array($field, ['first_name', 'last_name']) && ($user->first_name || $user->last_name))
                || ($field === 'license_number' && filled($user->courierProfile?->license_number));
            $fields[] = ['key' => $field, 'label' => $labels[$field], 'required' => (bool) $required,
                'type' => $field === 'vehicle_types' ? 'multiselect' : ($options ? 'select' : (str_contains($field, 'email') ? 'email' : (str_contains($field, 'phone') ? 'tel' : 'text'))), 'options' => $options,
                'min_length' => $minimum, 'max_length' => $maximum,
                'input_mode' => in_array($field, ['postal_code', 'fleet_size']) ? 'numeric' : null];
        }

        return ['values' => $this->values($user), 'fields' => $fields, 'errors' => $this->errors($user),
            'can_correct' => in_array($user->kyc_status, ['pending_approval', 'rejected', ...($user->isBuyer() ? ['none'] : [])], true) && match ($user->role) {
                'seller' => $user->shop !== null, 'courier' => $user->courierProfile !== null, 'logistics' => $user->logisticsCompany !== null, default => true,
            }];
    }
}
