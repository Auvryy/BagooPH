<?php

namespace App\Services;

use App\Models\User;

class RegistrationDeliveryPinService
{
    public function addRules(array $rules): array
    {
        $rules['delivery_latitude'] = ['bail', 'nullable', 'required_with:delivery_longitude', 'numeric', 'regex:/\A-?[0-9]+(?:\.[0-9]{1,7})?\z/', 'between:-90,90'];
        $rules['delivery_longitude'] = ['bail', 'nullable', 'required_with:delivery_latitude', 'numeric', 'regex:/\A-?[0-9]+(?:\.[0-9]{1,7})?\z/', 'between:-180,180'];
        foreach (['phone', 'region', 'province', 'city', 'barangay', 'address'] as $field) {
            $rules[$field][] = 'required_with:delivery_latitude,delivery_longitude';
        }

        return $rules;
    }

    public function save(User $buyer, array $details): void
    {
        if (! isset($details['delivery_latitude'], $details['delivery_longitude'])) {
            return;
        }
        $buyer->addresses()->create([
            'recipient_name' => $buyer->name, 'phone' => $buyer->phone,
            'region' => $details['region'], 'province' => $details['province'], 'city' => $details['city'],
            'barangay' => $details['barangay'], 'street' => $details['address'],
            'postal_code' => $details['postal_code'] ?? null,
            'latitude' => $details['delivery_latitude'], 'longitude' => $details['delivery_longitude'],
            'type' => 'Home', 'is_default' => true,
        ]);
    }
}
