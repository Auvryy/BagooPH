<?php

namespace App\Services\Commerce;

use App\Rules\ApplicationText;
use App\Rules\PhilippineContact;
use App\Services\ApplicationValidationService;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Normalizer;

class CommerceInputService
{
    public function checkout(array $input, array $itemIds): array
    {
        $input = $this->normalize($input, [
            'recipient_name' => 'name', 'recipient_phone' => 'phone', 'shipping_address' => 'address',
            'shipping_city' => 'city', 'shipping_province' => 'province',
            'destination_barangay' => 'barangay', 'shipping_postal_code' => 'postal_code',
        ]);
        $input['item_ids'] = $itemIds;
        $data = Validator::make($input, [
            'recipient_name' => $this->text('name', 2, 100, true),
            'recipient_phone' => ['bail', 'required', 'string', new PhilippineContact],
            'shipping_address' => $this->text('address', 5, 500, true),
            'shipping_city' => $this->text('location', 2, 100, true),
            'shipping_province' => $this->text('location', 2, 100, true),
            'destination_barangay' => $this->text('location', 2, 100, true),
            'shipping_postal_code' => ['bail', 'required', 'string', 'regex:/\A[0-9]{4}\z/'],
            'shipping_latitude' => $this->coordinate('shipping_longitude', 90),
            'shipping_longitude' => $this->coordinate('shipping_latitude', 180),
            'landmark' => $this->text('address', 5, 255),
            'notes' => $this->text('notes', 1, 500),
            'delivery_type' => ['nullable', Rule::in(['doorstep', 'hub_self_pickup'])],
            'pickup_hub_id' => ['bail', 'required_if:delivery_type,hub_self_pickup', 'nullable', $this->identifier(), 'integer'],
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'bank_transfer', 'e_wallet'])],
            'voucher_code' => ['bail', 'nullable', 'string', 'max:50', 'regex:/\A[A-Z0-9_-]+\z/'],
            'save_address' => ['nullable', 'boolean'],
            'item_ids' => ['required', 'array', 'list', 'min:1'],
            'item_ids.*' => ['bail', 'required', $this->identifier(), 'integer', 'distinct'],
        ])->validate();

        foreach (['shipping_latitude', 'shipping_longitude', 'landmark', 'notes', 'voucher_code', 'pickup_hub_id'] as $field) {
            $data[$field] ??= null;
        }
        foreach (['shipping_latitude', 'shipping_longitude'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = $this->decimal($data[$field]);
            }
        }
        $data['delivery_type'] ??= 'doorstep';
        $data['pickup_hub_id'] = $data['delivery_type'] === 'hub_self_pickup' ? (int) $data['pickup_hub_id'] : null;
        $data['payment_method'] = 'cod';
        $data['save_address'] = (bool) ($data['save_address'] ?? false);
        $data['item_ids'] = array_map('intval', $data['item_ids']);
        sort($data['item_ids'], SORT_NUMERIC);

        return $data;
    }

    public function address(array $input): array
    {
        $input = $this->normalize($input, ['recipient_name' => 'name', 'phone' => 'phone', 'street' => 'address',
            'city' => 'city', 'province' => 'province', 'barangay' => 'barangay', 'postal_code' => 'postal_code']);
        $data = Validator::make($input, [
            'recipient_name' => $this->text('name', 2, 100, true),
            'phone' => ['bail', 'required', 'string', new PhilippineContact],
            'street' => $this->text('address', 5, 500, true),
            'city' => $this->text('location', 2, 100, true),
            'province' => $this->text('location', 2, 100),
            'barangay' => $this->text('location', 2, 100),
            'postal_code' => ['bail', 'nullable', 'string', 'regex:/\A[0-9]{4}\z/'],
            'latitude' => $this->coordinate('longitude', 90),
            'longitude' => $this->coordinate('latitude', 180),
            'landmark' => $this->text('address', 5, 255),
            'type' => ['nullable', Rule::in(['Home', 'Office', 'Other'])],
            'is_default' => ['nullable', 'boolean'],
        ])->validate();

        foreach (['province', 'barangay', 'postal_code', 'latitude', 'longitude', 'landmark'] as $field) {
            $data[$field] ??= null;
        }
        foreach (['latitude', 'longitude'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = $this->decimal($data[$field]);
            }
        }
        $data['type'] ??= 'Home';
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }

    public function selection(array $ids): array
    {
        $data = Validator::make(['item_ids' => $ids], [
            'item_ids' => ['required', 'array', 'list', 'min:1'],
            'item_ids.*' => ['bail', 'required', $this->identifier(), 'integer', 'distinct'],
        ])->validate();

        return array_map('intval', $data['item_ids']);
    }

    private function normalize(array $input, array $aliases): array
    {
        $application = [];
        foreach ($aliases as $field => $canonical) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if (! is_string($value) || preg_match('//u', $value) === 1) {
                    $application[$canonical] = $value;
                }
            }
        }
        $application = app(ApplicationValidationService::class)->normalize($application, 'buyer');
        foreach ($aliases as $field => $canonical) {
            if (array_key_exists($canonical, $application)) {
                $input[$field] = $application[$canonical];
            }
        }
        foreach (['landmark', 'notes', 'voucher_code'] as $field) {
            $value = $input[$field] ?? null;
            if (! is_string($value) || preg_match('//u', $value) !== 1) {
                continue;
            }
            $controls = $field === 'notes' ? str_replace(["\r", "\n"], '', $value) : $value;
            if (preg_match('/\p{C}/u', $controls)) {
                continue;
            }
            // Voucher identifiers must be ASCII before Unicode normalization.
            $value = $field === 'voucher_code' ? strtoupper(trim($value, ' '))
                : trim(Normalizer::normalize($value, Normalizer::FORM_KC), $field === 'notes' ? " \r\n" : ' ');
            $input[$field] = $value === '' ? null : $value;
        }

        return $input;
    }

    private function text(string $kind, int $minimum, int $maximum, bool $required = false): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'string', new ApplicationText($kind, $minimum, $maximum)];
    }

    private function identifier(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $value)) {
                $fail('The :attribute must be a positive whole-number identifier.');
            }
        };
    }

    private function coordinate(string $paired, int $maximum): array
    {
        return ['bail', 'nullable', 'required_with:'.$paired, 'numeric',
            'regex:/\A-?[0-9]+(?:\.[0-9]{1,7})?\z/', "between:-{$maximum},{$maximum}"];
    }

    private function decimal(mixed $value): string
    {
        $result = rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');

        return $result === '-0' ? '0' : $result;
    }
}
