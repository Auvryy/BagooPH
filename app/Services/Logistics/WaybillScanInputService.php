<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Rules\ApplicationText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

class WaybillScanInputService
{
    public function normalize(array $input, bool $allowTrackingUrl = false): array
    {
        if (isset($input['barcode']) && is_string($input['barcode']) && preg_match('/\A[ -~]+\z/', $input['barcode'])) {
            if ($allowTrackingUrl) {
                $input['barcode'] = $this->trackingUrlCode(trim($input['barcode'], ' ')) ?? $input['barcode'];
            }
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

    private function trackingUrlCode(string $value): ?string
    {
        if (strlen($value) > 255) {
            return null;
        }
        $url = parse_url($value);
        if (! is_array($url) || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || ! preg_match('~\A/track/([A-Za-z0-9][A-Za-z0-9_-]{0,254})/?\z~', $url['path'] ?? '', $match)) {
            return null;
        }
        foreach ([config('app.url'), request()->getSchemeAndHttpHost()] as $origin) {
            $current = parse_url((string) $origin);
            $root = preg_replace('/\A(seller|courier|hub|admin)\./i', '', strtolower($current['host'] ?? ''));
            $hosts = [$root, ...array_map(fn ($prefix) => $prefix.'.'.$root, ['seller', 'courier', 'hub', 'admin'])];
            if ($root && in_array(strtolower($url['host'] ?? ''), $hosts, true)
                && ($url['port'] ?? null) === ($current['port'] ?? null)) {
                return $match[1];
            }
        }

        return null;
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
