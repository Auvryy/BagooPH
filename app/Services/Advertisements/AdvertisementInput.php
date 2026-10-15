<?php

namespace App\Services\Advertisements;

use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Normalizer;

class AdvertisementInput
{
    public const SLOTS = ['main_1', 'main_2', 'main_3', 'side_1', 'side_2'];

    public function metadata(array $input): array
    {
        if (array_key_exists('source_version', $input) && ((! is_int($input['source_version']) && ! is_string($input['source_version']))
            || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $input['source_version']))) {
            throw ValidationException::withMessages(['source_version' => 'The saved version must use whole-number digits. Reload the promotion before trying again.']);
        }
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'source_version' => 'required|integer|min:0|max:2147483647',
            'actor_id' => 'prohibited', 'created_by_id' => 'prohibited', 'published_by_id' => 'prohibited',
            'shop_id' => 'prohibited', 'status' => 'prohibited', 'is_active' => 'prohibited',
            'destination_url' => 'prohibited', 'url' => 'prohibited', 'href' => 'prohibited',
            'asset_id' => 'prohibited', 'source_path' => 'prohibited', 'image_path' => 'prohibited',
        ])->validate();
        $data['request_key'] = strtolower($data['request_key']);
        $data['source_version'] = (int) $data['source_version'];

        return $data;
    }

    public function draft(array $input, bool $seller = false): array
    {
        // Multipart forms omit an empty list; collections still require at least one product.
        if (! array_key_exists('product_ids', $input)) {
            $input['product_ids'] = [];
        }
        foreach (['title', 'alt_text', 'summary'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = $this->text($input[$key]);
            }
        }
        $type = $input['destination_type'] ?? null;
        $collection = in_array($type, ['collection', 'offer'], true);
        $data = Validator::make($input, [
            'title' => ['required', 'string', new ApplicationText('notes', 1, 80), 'regex:/\A[^\r\n]*\z/u'],
            'alt_text' => ['required', 'string', new ApplicationText('notes', 1, 180), 'regex:/\A[^\r\n]*\z/u'],
            'summary' => ['nullable', 'string', new ApplicationText('notes', 1, 600)],
            'purpose' => ['required', Rule::in($seller ? ['feature', 'offer'] : ['feature', 'event', 'offer'])],
            'destination_type' => ['required', Rule::in($seller ? ['product', 'shop', 'offer'] : ['product', 'shop', 'category', 'collection', 'offer'])],
            'destination_id' => [Rule::requiredIf(! $collection), 'nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'product_ids' => ['present', 'array', 'list', $collection ? 'min:1' : 'max:0', 'max:24'],
            'product_ids.*' => ['required', new AsciiPositiveInteger, 'integer', 'distinct', 'max:2147483647'],
            'offer_revision_id' => [Rule::requiredIf(($input['purpose'] ?? null) === 'offer' || $type === 'offer'), 'nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'slot' => $seller ? ['prohibited'] : ['required', Rule::in(self::SLOTS)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'image' => ['nullable', 'file', 'max:5120'],
        ])->validate();
        if ($collection && ! empty($data['destination_id'])) {
            throw ValidationException::withMessages(['destination_id' => 'A collection uses its selected products, not another destination ID.']);
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Asia/Manila')->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Asia/Manila')->utc();
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Choose an end date and time after the start.']);
        }
        $data['starts_at'] = $start->format('Y-m-d H:i:s');
        $data['ends_at'] = $end->format('Y-m-d H:i:s');
        $data['destination_id'] = $collection ? null : (int) $data['destination_id'];
        $data['product_ids'] = array_map('intval', $data['product_ids']);
        $data['offer_revision_id'] = empty($data['offer_revision_id']) ? null : (int) $data['offer_revision_id'];
        $data['summary'] = ($data['summary'] ?? '') !== '' ? $data['summary'] : null;
        if ($seller) {
            $data['slot'] = null;
        }

        return $data;
    }

    public function action(array $input): array
    {
        if (array_key_exists('reason', $input)) {
            $input['reason'] = $this->text($input['reason']);
        }
        $data = Validator::make($input, [
            'action' => 'required|in:publish,reschedule,pause,resume,end,archive',
            'publication_id' => ['required_if:action,reschedule', 'nullable', new AsciiPositiveInteger, 'integer'],
            'publish_now' => 'sometimes|boolean',
            'claims_reviewed' => in_array($input['action'] ?? '', ['publish', 'reschedule'], true) ? ['required', 'accepted'] : ['prohibited'],
            'reason' => ['nullable', 'string', new ApplicationText('notes', 1, 600)],
            'submission_id' => ['nullable', new AsciiPositiveInteger, 'integer'],
            'slot' => ['nullable', Rule::in(self::SLOTS)],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ])->validate();

        $result = ['action' => $data['action'], 'publication_id' => empty($data['publication_id']) ? null : (int) $data['publication_id'],
            'publish_now' => (bool) ($data['publish_now'] ?? false), 'claims_reviewed' => in_array($data['action'], ['publish', 'reschedule'], true),
            'reason' => ($data['reason'] ?? '') !== '' ? $data['reason'] : null];
        // Omitted placement fields preserve fingerprints for existing platform commands.
        foreach (['submission_id', 'slot', 'starts_at', 'ends_at'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                continue;
            }
            $result[$field] = match ($field) {
                'submission_id' => (int) $data[$field],
                'starts_at', 'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$field], 'Asia/Manila')->utc()->format('Y-m-d H:i:s'),
                default => $data[$field],
            };
        }

        return $result;
    }

    public function text(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return $normalized === false ? $value : (preg_replace('/\A[\pZ ]+|[\pZ ]+\z/u', '', $normalized) ?? $value);
    }
}
