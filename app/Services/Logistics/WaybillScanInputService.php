<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Rules\ApplicationText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

class WaybillScanInputService
{
    public function normalize(array $input): array
    {
        if (isset($input['barcode']) && is_string($input['barcode']) && preg_match('/\A[ -~]+\z/', $input['barcode'])) {
            $input['barcode'] = strtoupper(trim($input['barcode'], ' '));
        }
        foreach (['action' => 'strtoupper', 'expected_status' => 'strtolower'] as $field => $canonical) {
            if (isset($input[$field]) && is_string($input[$field]) && preg_match('/\A[A-Za-z_ ]+\z/', $input[$field])) {
                $input[$field] = $canonical(trim($input[$field], ' '));
            }
        }
        $notes = $input['notes'] ?? null;
        if (is_string($notes) && preg_match('//u', $notes) === 1 && ! preg_match('/\p{C}/u', str_replace(["\r", "\n"], '', $notes))) {
            $input['notes'] = trim(Normalizer::normalize($notes, Normalizer::FORM_KC), ' ');
            $input['notes'] = $input['notes'] === '' ? null : $input['notes'];
        }

        return $input;
    }

    public function barcodeRules(bool $required = true): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'string', 'max:255', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/'];
    }

    public function notesRules(int $maximum = 1000): array
    {
        return ['bail', 'nullable', 'string', new ApplicationText('notes', 1, $maximum)];
    }

    public function matchedBarcode(mixed $input, Delivery $delivery, bool $allowOrderNumber = false): string
    {
        $values = $this->normalize(['barcode' => $input]);
        $validated = Validator::make($values, ['barcode' => $this->barcodeRules()])->validate();
        $codes = [$delivery->tracking_number, ...($allowOrderNumber ? [$delivery->order?->order_number] : [])];
        if (! in_array($validated['barcode'], $codes, true)) {
            throw ValidationException::withMessages(['barcode' => 'Scan the waybill attached to this parcel.']);
        }

        return $validated['barcode'];
    }
}
