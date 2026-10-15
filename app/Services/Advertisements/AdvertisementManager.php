<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementPublication;
use App\Models\AdvertisementRevision;
use App\Models\User;
use App\Services\AccountRestrictionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvertisementManager
{
    public const ARTWORK = [
        'mega-sale' => '11.11 Mega Sale', 'collection' => '11.11 Collection', 'payday' => 'Payday Craze',
        'carnival' => 'Mega Carnival', 'frenzy' => 'Epic Frenzy',
    ];

    public function installed(): bool
    {
        foreach (['advertisements', 'advertisement_assets', 'advertisement_revisions', 'advertisement_publications', 'advertisement_actions', 'advertisement_schedule_locks'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function authorize(User $actor): User
    {
        $actor = app(AccountRestrictionService::class)->currentActor($actor);
        abort_unless($actor->email_verified_at, 403);

        return $actor;
    }

    public function saveDraft(User $actor, array $input, ?Advertisement $advertisement = null): Advertisement
    {
        $this->authorize($actor);
        $meta = app(AdvertisementInput::class)->metadata($input);
        $data = app(AdvertisementInput::class)->draft($input);
        $file = $data['image'] ?? null;
        unset($data['image']);
        $fingerprint = $this->fingerprint(['action' => 'save', 'id' => $advertisement?->id, 'data' => $data,
            'image_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'source_version' => (int) $meta['source_version']]);

        return $this->write($actor, function (User $actor, array &$paths) use ($advertisement, $meta, $data, $file, $fingerprint) {
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $retry;
            }
            $ad = $advertisement ? Advertisement::whereKey($advertisement->id)->lockForUpdate()->firstOrFail() : null;
            abort_if($ad?->shop_id, 422, 'Seller artwork is reviewed as submitted. Use its review and placement controls instead of editing its content.');
            abort_if($ad?->archived_at, 422, 'This advertisement is archived. Create a new draft.');
            $this->version($ad, $meta['source_version']);
            $candidate = new AdvertisementRevision($data);
            app(AdvertisementDestinationService::class)->assertPublishable($candidate, $candidate->starts_at, $candidate->ends_at);
            $before = $ad ? $this->snapshot($ad) : [];
            $asset = $file ? app(AdvertisementAssetService::class)->store($actor, $file, $paths) : $ad?->latestRevision?->asset;
            if (! $asset || ! Storage::disk('local')->exists($asset->image_path)) {
                throw ValidationException::withMessages(['image' => 'Choose a valid image for this draft.']);
            }
            $ad ??= Advertisement::create(['slug' => Str::substr(Str::slug($data['title']) ?: 'promotion', 0, 90).'-'.Str::lower(Str::random(10)), 'created_by_id' => $actor->id]);
            $revision = $ad->revisions()->create([...$data, 'revision_number' => $ad->revisions()->count() + 1,
                'asset_id' => $asset->id, 'created_by_id' => $actor->id]);
            $ad->update(['version' => $ad->version + 1]);
            $ad->unsetRelation('latestRevision');
            $this->record($actor, $ad, 'draft_saved', $meta, $fingerprint, $before);

            return $ad;
        });
    }

    public function importArtwork(User $actor, array $input): Advertisement
    {
        $this->authorize($actor);
        $meta = app(AdvertisementInput::class)->metadata($input);
        $data = Validator::make($input, ['artwork' => ['required', Rule::in(array_keys(self::ARTWORK))]])->validate();
        $fingerprint = $this->fingerprint(['action' => 'import', 'artwork' => $data['artwork'], 'source_version' => (int) $meta['source_version']]);

        return $this->write($actor, function (User $actor, array &$paths) use ($meta, $data, $fingerprint) {
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $retry;
            }
            if ($existing = Advertisement::where('import_key', $data['artwork'])->first()) {
                return $existing;
            }
            $this->version(null, $meta['source_version']);
            $path = public_path('images/buyer/advertisements/'.$data['artwork'].'.webp');
            abort_unless(is_file($path), 422, 'The supplied artwork is not available. Upload an image instead.');
            $asset = app(AdvertisementAssetService::class)->store($actor, new UploadedFile($path, $data['artwork'].'.webp', 'image/webp', null, true), $paths);
            $title = self::ARTWORK[$data['artwork']];
            $ad = Advertisement::create(['slug' => $data['artwork'].'-'.Str::lower(Str::random(10)), 'created_by_id' => $actor->id,
                'import_key' => $data['artwork'], 'version' => 1]);
            $ad->revisions()->create(['revision_number' => 1, 'asset_id' => $asset->id, 'created_by_id' => $actor->id,
                'purpose' => 'offer', 'title' => $title, 'alt_text' => 'Bagoo '.$title.' artwork', 'destination_type' => 'collection',
                'product_ids' => [], 'imported_candidate' => true]);
            $this->record($actor, $ad, 'artwork_imported', $meta, $fingerprint, []);

            return $ad;
        });
    }

    public function act(User $actor, Advertisement $advertisement, array $input): Advertisement
    {
        $this->authorize($actor);
        $meta = app(AdvertisementInput::class)->metadata($input);
        $data = app(AdvertisementInput::class)->action($input);
        $fingerprint = $this->fingerprint(['id' => $advertisement->id, 'data' => $data, 'source_version' => (int) $meta['source_version']]);

        return $this->write($actor, function (User $actor) use ($advertisement, $meta, $data, $fingerprint) {
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $retry;
            }
            $ad = Advertisement::whereKey($advertisement->id)->lockForUpdate()->firstOrFail();
            $this->version($ad, $meta['source_version']);
            abort_if($ad->archived_at, 422, 'This advertisement is archived. Its original history is retained.');
            $before = $this->snapshot($ad);
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if (in_array($data['action'], ['publish', 'reschedule'], true)) {
                $this->publish($actor, $ad, $data, $now);
            } elseif ($data['action'] === 'archive') {
                if ($ad->shop_id) {
                    app(SellerPromotionService::class)->archiveReviews($actor, $ad);
                }
                $ad->publications()->whereNull('ended_at')->where('ends_at', '>', $now)->update(['ended_at' => $now]);
                $ad->archived_at = $now;
            } else {
                $publication = $ad->publications()->whereKey($data['publication_id'] ?? null)->lockForUpdate()->first();
                abort_unless($publication, 422, 'Choose a recorded placement from this advertisement.');
                abort_if($publication->effectiveEnd()->lessThanOrEqualTo($now) || $publication->effectiveEnd()->lessThanOrEqualTo($publication->starts_at), 422, 'This placement has ended. Save a new revision to publish again.');
                if ($data['action'] === 'pause') {
                    abort_if($publication->paused_at, 409, 'This placement is already paused.');
                    $publication->paused_at = $now;
                } elseif ($data['action'] === 'resume') {
                    abort_unless($publication->paused_at, 409, 'This placement is not paused.');
                    abort_unless(app(SellerPromotionService::class)->approvedRevision($ad, $publication->revision), 422, 'This seller submission no longer has current approval.');
                    app(AdvertisementDestinationService::class)->assertPublishable($publication->revision, $publication->starts_at, $publication->effectiveEnd());
                    abort_unless(Storage::disk('local')->exists($publication->revision->asset->image_path), 422, 'Replace the missing artwork before resuming.');
                    $publication->paused_at = null;
                } else {
                    $publication->ended_at = $now;
                }
                $publication->save();
            }
            $ad->version++;
            $ad->save();
            $this->record($actor, $ad, $data['action'], $meta, $fingerprint, $before, $data['reason'] ?? null);

            return $ad;
        });
    }

    public function snapshot(Advertisement $ad): array
    {
        $snapshot = ['version' => $ad->version, 'revision_id' => $ad->latestRevision()->value('advertisement_revisions.id'),
            'archived_at' => $ad->archived_at?->toIso8601String(), 'placements' => $ad->publications()->orderBy('id')->get()
                ->map(fn ($publication) => $publication->only(['id', 'revision_id', 'slot', 'starts_at', 'ends_at', 'paused_at', 'ended_at', 'superseded_at', 'replaces_publication_id']))->all()];
        if ($ad->shop_id && Schema::hasTable('advertisement_submissions')) {
            $snapshot['submissions'] = $ad->submissions()->with('latestReview')->orderBy('id')->get()->map(fn ($submission) => [
                'id' => $submission->id, 'revision_id' => $submission->revision_id, 'state' => $submission->state(), 'review_id' => $submission->latestReview?->id,
            ])->all();
        }

        return $snapshot;
    }

    private function publish(User $actor, Advertisement $ad, array $data, CarbonImmutable $now): void
    {
        $seller = (bool) $ad->shop_id;
        if ($seller) {
            abort_unless($data['submission_id'] ?? null, 422, 'Choose the exact approved seller submission.');
            $revision = app(SellerPromotionService::class)->approved($ad, $data['submission_id'])->revision;
        } else {
            abort_if($data['action'] === 'reschedule' || array_intersect(array_keys($data), ['submission_id', 'slot', 'starts_at', 'ends_at']), 422, 'Save a new platform draft to change its placement.');
            $revision = $ad->latestRevision()->with('asset')->firstOrFail();
        }
        $replaced = null;
        if ($data['action'] === 'reschedule') {
            $replaced = $ad->publications()->where('revision_id', $revision->id)->findOrFail($data['publication_id']);
            abort_if($replaced->effectiveEnd()->lessThanOrEqualTo($now) || $replaced->effectiveEnd()->lessThanOrEqualTo($replaced->starts_at)
                || AdvertisementPublication::where('replaces_publication_id', $replaced->id)->exists(), 409, 'This placement changed or ended. Reload before scheduling a replacement.');
        } else {
            abort_if(AdvertisementPublication::where('revision_id', $revision->id)->exists(), 409, 'This revision already has a placement. Use its placement controls or save a new draft.');
        }
        $slot = $seller ? ($data['slot'] ?? null) : $revision->slot;
        abort_unless($revision->starts_at && $revision->ends_at && in_array($slot, AdvertisementInput::SLOTS, true), 422, 'Choose a position and complete the requested period before publishing.');
        $start = ($data['publish_now'] ?? false) ? $now : ($seller && isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'], 'UTC') : $revision->starts_at);
        $end = $seller && isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'], 'UTC') : $revision->ends_at;
        abort_if($seller && ($start->lessThan($revision->starts_at) || $end->greaterThan($revision->ends_at)), 422, 'Place this promotion within its reviewed requested period. Ask the seller for a new revision to extend it.');
        abort_if(! ($data['publish_now'] ?? false) && $start->lessThan($now), 422, 'Choose a future start time to schedule, or use Publish now.');
        abort_unless($end->greaterThan($now) && $end->greaterThan($start), 422, 'Choose an end date and time in the future.');
        app(AdvertisementDestinationService::class)->assertPublishable($revision, $start, $end);
        abort_unless(Storage::disk('local')->exists($revision->asset->image_path), 422, 'The artwork is unavailable. Replace it before publishing.');

        if ($replaced && $replaced->starts_at->greaterThan($now)) {
            $replaced->update(['ended_at' => $now]);
        }

        // Every publisher owns the same calendar lock before inspecting or changing positions.
        $overlaps = AdvertisementPublication::where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->orderBy('id')->lockForUpdate()->get()->filter(fn ($publication) => $publication->effectiveEnd()->greaterThan($start)
                && $publication->effectiveEnd()->greaterThan($publication->starts_at));
        foreach ($overlaps as $publication) {
            if ((int) $publication->advertisement_id !== (int) $ad->id && $publication->slot === $slot) {
                throw ValidationException::withMessages(['advertisement' => 'That position is already reserved during this period. Choose another position or date. Paused placements keep their reservation until ended.']);
            }
        }
        foreach ($overlaps->where('advertisement_id', $ad->id) as $publication) {
            $publication->update(['superseded_at' => $start]);
        }
        $ad->publications()->create(['revision_id' => $revision->id, 'published_by_id' => $actor->id,
            'slot' => $slot, 'starts_at' => $start, 'ends_at' => $end, 'claims_reviewed' => true,
            ...($replaced ? ['replaces_publication_id' => $replaced->id] : [])]);
    }

    private function write(User $actor, callable $work): Advertisement
    {
        return app(AdvertisementTransaction::class)->run($actor, $this->authorize(...), $work);
    }

    private function version(?Advertisement $ad, mixed $version): void
    {
        abort_unless((int) $version === ($ad?->version ?? 0), 409, 'This advertisement changed. Reload it before saving again.');
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function retry(User $actor, string $key, string $fingerprint): ?Advertisement
    {
        if (! $event = AdvertisementAction::where('request_key', $key)->first()) {
            return null;
        }
        abort_unless((int) $event->actor_id === (int) $actor->id && hash_equals($event->fingerprint, $fingerprint), 409, 'This request key was already used for a different change.');

        return Advertisement::findOrFail($event->advertisement_id);
    }

    private function record(User $actor, Advertisement $ad, string $action, array $meta, string $fingerprint, array $before, ?string $reason = null): void
    {
        AdvertisementAction::create(['advertisement_id' => $ad->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
            'request_key' => $meta['request_key'], 'fingerprint' => $fingerprint, 'source_version' => $meta['source_version'],
            'action' => $action, 'reason' => $reason, 'before_state' => $before, 'after_state' => $this->snapshot($ad)]);
    }
}
