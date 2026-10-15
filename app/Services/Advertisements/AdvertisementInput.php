<?php

namespace App\Services\Advertisements;

use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvertisementInput
{
    public const SLOTS = ['main_1', 'main_2', 'main_3', 'side_1', 'side_2'];

    public function metadata(array $input): array
    {
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

    public function draft(array $input): array
    {
        // Multipart forms omit an empty list; collections still require at least one product.
        if (! array_key_exists('product_ids', $input)) {
            $input['product_ids'] = [];
        }
        foreach (['title', 'alt_text', 'summary'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = preg_replace('/\A[\pZ\s]+|[\pZ\s]+\z/u', '', $input[$key]);
            }
        }
        $type = $input['destination_type'] ?? null;
        $collection = in_array($type, ['collection', 'offer'], true);
        $data = Validator::make($input, [
            'title' => ['required', 'string', new ApplicationText('notes', 1, 80), 'regex:/\A[^\r\n]*\z/u'],
            'alt_text' => ['required', 'string', new ApplicationText('notes', 1, 180), 'regex:/\A[^\r\n]*\z/u'],
            'summary' => ['nullable', 'string', new ApplicationText('notes', 1, 600)],
            'purpose' => ['required', Rule::in(['feature', 'event', 'offer'])],
            'destination_type' => ['required', Rule::in(['product', 'shop', 'category', 'collection', 'offer'])],
            'destination_id' => [Rule::requiredIf(! $collection), 'nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'product_ids' => ['present', 'array', 'list', $collection ? 'min:1' : 'max:0', 'max:24'],
            'product_ids.*' => ['required', new AsciiPositiveInteger, 'integer', 'distinct', 'max:2147483647'],
            'offer_revision_id' => [Rule::requiredIf(($input['purpose'] ?? null) === 'offer' || $type === 'offer'), 'nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'slot' => ['required', Rule::in(self::SLOTS)],
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

        return $data;
    }

    public function action(array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, [
            'action' => 'required|in:publish,pause,resume,end,archive',
            'publication_id' => ['nullable', new AsciiPositiveInteger, 'integer'],
            'publish_now' => 'sometimes|boolean',
            'claims_reviewed' => ($input['action'] ?? '') === 'publish' ? ['required', 'accepted'] : ['prohibited'],
            'reason' => ['nullable', 'string', new ApplicationText('notes', 1, 600)],
        ])->validate();

        return ['action' => $data['action'], 'publication_id' => empty($data['publication_id']) ? null : (int) $data['publication_id'],
            'publish_now' => (bool) ($data['publish_now'] ?? false), 'claims_reviewed' => $data['action'] === 'publish',
            'reason' => $data['reason'] ?? null];
    }
}
