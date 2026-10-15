<?php

namespace App\Services\Advertisements;

use App\Models\AdvertisementPublication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class AdvertisementMeasurementService
{
    public const TOKEN_SECONDS = 1800;

    public const RAW_RETENTION_DAYS = 7;

    public function installed(): bool
    {
        return Schema::hasTable('advertisement_events') && Schema::hasTable('advertisement_daily_statistics');
    }

    public function collecting(): bool
    {
        return config('advertisements.enabled') && config('advertisements.measurement_enabled')
            && $this->installed() && app(AdvertisementManager::class)->installed();
    }

    public function issue(AdvertisementPublication $publication, string $page): ?array
    {
        try {
            if (! $this->collecting()) {
                return null;
            }

            return ['token' => Crypt::encryptString(json_encode(['publication' => $publication->id,
                'revision' => $publication->revision_id, 'page' => $page, 'issued' => CarbonImmutable::now('UTC')->getTimestampMs()], JSON_THROW_ON_ERROR)),
                'revision_id' => $publication->revision_id, 'endpoint' => route('advertisements.events')];
        } catch (Throwable $error) {
            // Optional measurement must not stop the public catalogue from loading.
            $this->unavailable($error);

            return null;
        }
    }

    public function record(array $input): bool
    {
        if (array_diff(array_keys($input), ['token', 'type'])) {
            throw ValidationException::withMessages(['event' => 'Send only the advertisement event token and event type.']);
        }
        $data = Validator::make($input, ['token' => 'required|string|max:2048', 'type' => 'required|in:impression,click'])->validate();
        if (! $this->collecting()) {
            return false;
        }
        try {
            $token = json_decode(Crypt::decryptString($data['token']), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return false;
        }
        $now = CarbonImmutable::now('UTC');
        if (! is_array($token) || count($token) !== 4
            || ! is_int($token['publication'] ?? null) || $token['publication'] < 1
            || ! is_int($token['revision'] ?? null) || $token['revision'] < 1
            || ! is_int($token['issued'] ?? null) || ! is_string($token['page'] ?? null)
            || ! preg_match('/\A[a-f0-9]{32}\z/', $token['page'])) {
            return false;
        }
        $age = $now->getTimestampMs() - $token['issued'];
        if ($age < 0 || $age >= self::TOKEN_SECONDS * 1000 || ($data['type'] === 'impression' && $age < 1000)) {
            return false;
        }

        return DB::transaction(function () use ($token, $data) {
            // Use the same calendar boundary as pause, withdrawal and publication.
            if (! DB::table('advertisement_schedule_locks')->where('id', 1)->lockForUpdate()->first()) {
                return false;
            }
            $now = CarbonImmutable::now('UTC');
            $age = $now->getTimestampMs() - $token['issued'];
            if ($age < 0 || $age >= self::TOKEN_SECONDS * 1000 || ($data['type'] === 'impression' && $age < 1000)) {
                return false;
            }
            $publication = AdvertisementPublication::with(['advertisement', 'revision.asset'])->find($token['publication']);
            if (! $publication || (int) $publication->revision_id !== $token['revision']
                || app(AdvertisementReader::class)->state($publication)['state'] !== 'live') {
                return false;
            }
            $inserted = DB::table('advertisement_events')->insertOrIgnore(['publication_id' => $publication->id,
                'event_type' => $data['type'], 'dedupe_hash' => hash('sha256', $token['page'].'|'.$token['revision']), 'recorded_at' => $now]);
            if (! $inserted) {
                return false;
            }
            $day = $now->setTimezone('Asia/Manila')->toDateString();
            DB::table('advertisement_daily_statistics')->insertOrIgnore(['publication_id' => $publication->id,
                'day' => $day, 'impressions' => 0, 'clicks' => 0]);
            DB::table('advertisement_daily_statistics')->where('publication_id', $publication->id)->where('day', $day)
                ->increment($data['type'] === 'impression' ? 'impressions' : 'clicks');

            return true;
        }, 3);
    }

    public function prune(): int
    {
        if (! $this->installed()) {
            return 0;
        }
        $cutoff = CarbonImmutable::now('UTC')->subDays(self::RAW_RETENTION_DAYS);
        $deleted = 0;
        do {
            $ids = DB::table('advertisement_events')->where('recorded_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            $deleted += DB::table('advertisement_events')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $deleted;
    }

    public function unavailable(Throwable $error): string
    {
        $id = (string) Str::uuid();
        try {
            Log::warning('Advertisement reporting is unavailable.', ['request_id' => $id,
                'exception_type' => $error::class, 'code' => (string) $error->getCode()]);
        } catch (Throwable) {
            // A logging outage must not make optional measurement block shopping.
        }

        return $id;
    }
}
