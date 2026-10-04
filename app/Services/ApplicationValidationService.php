<?php

namespace App\Services;

use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\BirthDate;
use App\Rules\MasterCategory;
use App\Rules\PhilippineContact;
use App\Rules\UniqueAccountEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Normalizer;

class ApplicationValidationService
{
    public const ACCOUNT_FIELDS = ['name', 'first_name', 'last_name', 'middle_name', 'sex', 'birthday', 'email', 'phone', 'address', 'city', 'province', 'municipality', 'barangay', 'postal_code'];

    public const VEHICLES = ['Motorcycle', 'Scooter', 'Sedan / Van'];

    public const FLEET_TYPES = ['motorcycle', 'l300_van', 'wing_truck'];

    public function normalize(array $data, string $role): array
    {
        foreach ($data as $field => $value) {
            if (! is_string($value) || ! in_array($field, $this->fields($role), true) || preg_match('/\p{C}/u', $value)) {
                continue;
            }
            // Phone/postal digits must be ASCII before normalization can hide look-alikes.
            $value = in_array($field, ['phone', 'shop_phone', 'company_phone', 'postal_code'], true) ? trim($value, ' ') : trim(Normalizer::normalize($value, Normalizer::FORM_KC), ' ');
            if (in_array($field, ['phone', 'shop_phone', 'company_phone'], true)) {
                $landline = $field !== 'phone' || in_array($role, ['seller', 'logistics'], true);
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
            'first_name' => $text('name', 1, 100), 'last_name' => $text('name', 1, 100), 'middle_name' => $text('name', 1, 100),
            'sex' => ['nullable', Rule::in(['Female', 'Male', 'Other'])],
            'birthday' => [$worker ? 'required' : 'nullable', new BirthDate($worker)],
            'email' => ['bail', 'required', 'string', 'max:255', 'email', 'not_regex:/[\p{C}\s]/u', new UniqueAccountEmail($user?->id)],
            'phone' => ['bail', $worker ? 'required' : 'nullable', 'string', new PhilippineContact(in_array($role, ['seller', 'logistics'], true))],
            'address' => $text('address', 5, 500, $worker),
            'city' => $text('location', 2, 255, $worker),
            'province' => $text('location', 2, 255), 'municipality' => $text('location', 2, 255), 'barangay' => $text('location', 2, 255),
            'postal_code' => ['bail', 'nullable', 'string', 'regex:/\A[0-9]{4}\z/'],
        ];
        if ($role === 'seller') {
            $rules += ['shop_name' => $text('text', 2, 255, true), 'root_category_id' => ['bail', 'required', 'integer', new MasterCategory],
                'shop_phone' => ['bail', 'required', 'string', new PhilippineContact(true)], 'shop_address' => $text('address', 5, 500, true), 'shop_city' => $text('location', 2, 255, true)];
        } elseif ($role === 'courier') {
            $rules += ['vehicle_type' => ['required', Rule::in(self::VEHICLES)], 'plate_number' => $text('code', 2, 50, true), 'license_number' => $text('code', 2, 50)];
        } elseif ($role === 'logistics') {
            $rules += ['company_name' => $text('text', 2, 255, true), 'company_code' => [...$text('code', 2, 20, $user?->logisticsCompany !== null), Rule::unique('logistics_companies', 'code')->ignore($user?->logisticsCompany?->id)],
                'company_email' => ['bail', 'required', 'string', 'max:255', 'email', 'not_regex:/[\p{C}\s]/u'],
                'company_phone' => ['bail', 'required', 'string', new PhilippineContact(true)], 'company_address' => $text('address', 5, 500, true),
                'operating_province' => $text('location', 2, 255),
                'franchise_number' => [...$text('code', 2, 100), Rule::notIn(['PENDING-LTFRB'])], 'fleet_size' => ['nullable', 'integer', 'min:1', 'max:10000'],
                'vehicle_types' => ['nullable', 'array', 'max:3'], 'vehicle_types.*' => ['required', 'distinct', Rule::in(self::FLEET_TYPES)]];
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

        return $this->normalize($data, $role);
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
            'name' => 'Full name', 'first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name',
            'sex' => 'Sex', 'email' => 'Account email', 'phone' => 'Contact number', 'address' => 'Street address', 'city' => 'City',
            'province' => 'Province', 'municipality' => 'Municipality', 'barangay' => 'Barangay', 'postal_code' => 'Postal code',
            'shop_name' => 'Shop name', 'shop_phone' => 'Shop contact number', 'shop_address' => 'Shop street address', 'shop_city' => 'Shop city',
            'vehicle_type' => 'Vehicle type', 'plate_number' => 'Plate number', 'license_number' => 'License number',
            'company_name' => 'Company name', 'company_code' => 'Company code', 'company_email' => 'Company email',
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
            $fields[] = ['key' => $field, 'label' => $labels[$field], 'required' => in_array('required', $rules, true),
                'type' => $field === 'vehicle_types' ? 'multiselect' : ($options ? 'select' : (str_contains($field, 'email') ? 'email' : (str_contains($field, 'phone') ? 'tel' : ($field === 'fleet_size' ? 'number' : 'text')))), 'options' => $options];
        }

        return ['values' => $this->values($user), 'fields' => $fields, 'errors' => $this->errors($user),
            'can_correct' => in_array($user->kyc_status, ['pending_approval', 'rejected'], true) && match ($user->role) {
                'seller' => $user->shop !== null, 'courier' => $user->courierProfile !== null, 'logistics' => $user->logisticsCompany !== null, default => true,
            }];
    }
}
