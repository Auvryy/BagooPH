<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Rules\ApplicationText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

class LogisticsSortingInputService
{
    public function validate(array $input): array
    {
        foreach (['barangay', 'bin', 'notes'] as $field) {
            $value = $input[$field] ?? null;
            if (! is_string($value) || preg_match('//u', $value) !== 1) {
                continue;
            }
            $controls = $field === 'notes' ? str_replace(["\r", "\n"], '', $value) : $value;
            if (preg_match('/\p{C}/u', $controls)) {
                continue;
            }
            $value = trim(Normalizer::normalize($value, Normalizer::FORM_KC), ' ');
            $input[$field] = $value === '' ? null : $value;
        }

        return Validator::make($input, [
            'delivery_id' => ['bail', 'required', 'integer', 'min:1', 'exists:deliveries,id'],
            'barangay' => ['bail', 'nullable', 'string', new ApplicationText('location', 2, 100)],
            'bin' => ['bail', 'nullable', 'string', new ApplicationText('location', 1, 150)],
            'notes' => ['bail', 'nullable', 'string', new ApplicationText('notes', 1, 1000)],
        ])->validate();
    }

    public function destination(Delivery $delivery, array $input): array
    {
        $destination = $delivery->order?->destination_barangay;
        Validator::make(['barangay' => $destination], [
            'barangay' => ['bail', 'required', 'string', new ApplicationText('location', 2, 100)],
        ])->validate();

        $destination = trim(Normalizer::normalize($destination, Normalizer::FORM_KC), ' ');
        $canonical = fn (string $value): string => mb_strtolower(preg_replace('/ +/u', ' ', $value));
        if (isset($input['barangay']) && $canonical($input['barangay']) !== $canonical($destination)) {
            throw ValidationException::withMessages(['barangay' => 'Use the parcel\'s recorded destination barangay.']);
        }

        $bin = $input['bin'] ?? $delivery->destination_bin ?? 'BIN: BRGY-'.mb_strtoupper(str_replace(' ', '-', $destination));
        Validator::make(['bin' => $bin], [
            'bin' => ['bail', 'required', 'string', new ApplicationText('location', 1, 150)],
        ])->validate();

        return ['barangay' => $destination, 'bin' => $bin];
    }
}
