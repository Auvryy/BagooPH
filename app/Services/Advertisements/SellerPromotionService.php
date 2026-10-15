<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementReview;
use App\Models\AdvertisementRevision;
use App\Models\AdvertisementSubmission;
use App\Models\Shop;
use App\Models\User;
use App\Models\VoucherRevision;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Notifications\GovernanceNoticeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerPromotionService
{
    public function installed(): bool
    {
        return app(AdvertisementManager::class)->installed() && Schema::hasTable('advertisement_submissions')
            && Schema::hasTable('advertisement_reviews') && Schema::hasColumn('advertisements', 'shop_id')
            && Schema::hasColumn('advertisement_submissions', 'shop_scope')
            && Schema::hasColumn('advertisement_publications', 'replaces_publication_id');
    }

    public function authorize(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isSeller() && $actor->canAccessPortal() && $actor->email_verified_at, 403);

        return $actor;
    }

    public function shop(User $actor): Shop
    {
        $actor = $this->authorize($actor);
        $shops = Shop::where('user_id', $actor->id)->limit(2)->get();
        abort_unless($shops->count() === 1, 409, 'This account needs its single registered shop before managing promotions.');

        return $shops->first();
    }

    public function find(User $actor, int $id): Advertisement
    {
        $shop = $this->shop($actor);
        $this->requireInstalled();

        return Advertisement::where('shop_id', $shop->id)->where('created_by_id', $actor->id)->findOrFail($id);
    }

    public function saveDraft(User $actor, array $input, ?Advertisement $advertisement = null): Advertisement
    {
        $this->authorize($actor);
        $meta = $this->metadata($input);
        $data = app(AdvertisementInput::class)->draft($input, seller: true);
        $file = $data['image'] ?? null;
        unset($data['image']);
        $fingerprint = $this->fingerprint(['action' => 'seller_draft', 'id' => $advertisement?->id, 'data' => $data,
            'image_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'source_version' => $meta['source_version']]);

        return $this->write($actor, $this->authorize(...), function (User $actor, array &$paths) use ($advertisement, $meta, $data, $file, $fingerprint) {
            $shop = $this->shop($actor);
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $this->find($actor, $retry->id);
            }
            $ad = $advertisement ? $this->find($actor, $advertisement->id) : null;
            $this->version($ad, $meta['source_version']);
            abort_if($ad?->archived_at, 422, 'This promotion is archived. Create a new draft.');
            $candidate = new AdvertisementRevision($data);
            $this->assertOwnedTarget($shop, $candidate);
            $before = $ad ? app(AdvertisementManager::class)->snapshot($ad) : [];
            $asset = $file ? app(AdvertisementAssetService::class)->store($actor, $file, $paths) : $ad?->latestRevision?->asset;
            if (! $asset || ! Storage::disk('local')->exists($asset->image_path)) {
                throw ValidationException::withMessages(['image' => 'Choose a valid image for this promotion.']);
            }
            $ad ??= Advertisement::create(['created_by_id' => $actor->id, 'shop_id' => $shop->id,
                'slug' => Str::substr(Str::slug($data['title']) ?: 'promotion', 0, 90).'-'.Str::lower(Str::random(10))]);
            // A newer draft cannot leave an older pending submission in the review queue.
            foreach ($ad->submissions()->whereDoesntHave('reviews')->get() as $submission) {
                $this->review($actor, $submission, 'superseded', 'Replaced by a newer draft.');
            }
            $ad->revisions()->create([...$data, 'revision_number' => $ad->revisions()->count() + 1,
                'asset_id' => $asset->id, 'created_by_id' => $actor->id]);
            $ad->version++;
            $ad->save();
            $this->record($actor, $ad, 'seller_draft_saved', $meta, $fingerprint, $before);

            return $ad;
        });
    }

    public function act(User $actor, Advertisement $advertisement, array $input): Advertisement
    {
        $this->authorize($actor);
        $meta = $this->metadata($input);
        if (array_key_exists('reason', $input)) {
            $input['reason'] = app(AdvertisementInput::class)->text($input['reason']);
        }
        $data = Validator::make($input, [
            'action' => 'required|in:submit,withdraw,stop',
            'revision_id' => ['required_if:action,submit', 'nullable', new AsciiPositiveInteger, 'integer'],
            'submission_id' => ['required_if:action,withdraw', 'nullable', new AsciiPositiveInteger, 'integer'],
            'reason' => ['nullable', 'string', new ApplicationText('notes', 1, 600)],
        ])->validate();
        $data = ['action' => $data['action'], 'revision_id' => (int) ($data['revision_id'] ?? 0),
            'submission_id' => (int) ($data['submission_id'] ?? 0), 'reason' => trim($data['reason'] ?? '') ?: null];
        $fingerprint = $this->fingerprint(['id' => $advertisement->id, 'data' => $data, 'source_version' => $meta['source_version']]);

        return $this->write($actor, $this->authorize(...), function (User $actor) use ($advertisement, $meta, $data, $fingerprint) {
            $ad = $this->find($actor, $advertisement->id);
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $this->find($actor, $retry->id);
            }
            $this->version($ad, $meta['source_version']);
            abort_if($ad->archived_at, 422, 'This promotion is archived.');
            $before = app(AdvertisementManager::class)->snapshot($ad);
            if ($data['action'] === 'submit') {
                $revision = $ad->latestRevision()->with('asset')->firstOrFail();
                abort_unless($revision->id === $data['revision_id'], 409, 'The draft changed. Reload and review the saved version before submitting.');
                abort_if($ad->submissions()->where('revision_id', $revision->id)->exists(), 409, 'This revision was already submitted. Save a new draft to request another review.');
                $this->assertOwnedTarget($ad->shop, $revision);
                abort_unless($revision->ends_at->greaterThan(now()), 422, 'Choose a future end time before submitting.');
                abort_unless(Storage::disk('local')->exists($revision->asset->image_path), 422, 'Replace the missing artwork before submitting.');
                $submission = $ad->submissions()->create(['revision_id' => $revision->id, 'submitted_by_id' => $actor->id, 'shop_scope' => $this->shopScope($ad->shop->fresh())]);
                app(GovernanceNoticeService::class)->record($submission);
            } elseif ($data['action'] === 'withdraw') {
                $submission = $ad->submissions()->with('latestReview')->findOrFail($data['submission_id']);
                abort_unless(in_array($submission->state(), ['pending', 'approved'], true), 409, 'This submission is already resolved or withdrawn.');
                $this->review($actor, $submission, 'withdrawn', $data['reason']);
                $this->endPlacements($ad, $submission->revision_id);
            } else {
                $stoppable = $ad->submissions()->with('latestReview')->get()->filter(fn ($submission) => in_array($submission->state(), ['pending', 'approved'], true));
                abort_if($stoppable->isEmpty(), 409, 'There is no current submission or approved promotion to stop.');
                foreach ($stoppable as $submission) {
                    $this->review($actor, $submission, 'withdrawn', $data['reason'] ?? 'Promotion stopped by its seller.');
                }
                $this->endPlacements($ad);
            }
            $ad->version++;
            $ad->save();
            $this->record($actor, $ad, 'seller_'.$data['action'], $meta, $fingerprint, $before, $data['reason']);

            return $ad;
        });
    }

    public function decide(User $actor, Advertisement $advertisement, array $input): Advertisement
    {
        $manager = app(AdvertisementManager::class);
        $manager->authorize($actor);
        $meta = app(AdvertisementInput::class)->metadata($input);
        if (array_key_exists('reason', $input)) {
            $input['reason'] = app(AdvertisementInput::class)->text($input['reason']);
        }
        $data = Validator::make($input, [
            'decision' => 'required|in:approved,changes_requested,rejected',
            'submission_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'revision_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'reason' => [in_array($input['decision'] ?? '', ['changes_requested', 'rejected'], true) ? 'required' : 'nullable', 'string', new ApplicationText('notes', 5, 600)],
            'claims_reviewed' => ($input['decision'] ?? '') === 'approved' ? ['required', 'accepted'] : ['prohibited'],
            'slot' => 'prohibited', 'publication_id' => 'prohibited', 'publish_now' => 'prohibited',
        ])->validate();
        $data['submission_id'] = (int) $data['submission_id'];
        $data['revision_id'] = (int) $data['revision_id'];
        $data['reason'] = trim($data['reason'] ?? '') ?: null;
        $data['claims_reviewed'] = $data['decision'] === 'approved';
        $fingerprint = $this->fingerprint(['id' => $advertisement->id, 'review' => $data, 'source_version' => $meta['source_version']]);

        return $this->write($actor, $manager->authorize(...), function (User $actor) use ($advertisement, $meta, $data, $fingerprint, $manager) {
            if ($retry = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $retry;
            }
            $ad = Advertisement::whereKey($advertisement->id)->lockForUpdate()->firstOrFail();
            abort_unless($ad->shop_id, 422, 'Only a submitted seller promotion uses this review workflow.');
            abort_if($ad->archived_at, 422, 'This promotion is archived.');
            $this->version($ad, $meta['source_version']);
            $submission = $ad->submissions()->with(['latestReview', 'revision.asset'])->findOrFail($data['submission_id']);
            abort_unless($submission->revision_id === $data['revision_id'] && $ad->latestRevision->id === $data['revision_id']
                && $submission->state() === 'pending', 409, 'The submitted revision changed or was already reviewed. Reload the promotion before deciding.');
            if ($data['decision'] === 'approved') {
                $this->assertCurrentContext($submission);
                $this->assertOwnedTarget($ad->shop, $submission->revision, $ad);
                abort_unless($submission->revision->ends_at->greaterThan(now()), 422, 'The requested period has ended. Ask the seller for a new draft.');
                abort_unless(Storage::disk('local')->exists($submission->revision->asset->image_path), 422, 'The submitted artwork is unavailable. Request a replacement.');
            }
            $before = $manager->snapshot($ad);
            $this->review($actor, $submission, $data['decision'], $data['reason']);
            $ad->version++;
            $ad->save();
            $this->record($actor, $ad, 'review_'.$data['decision'], $meta, $fingerprint, $before, $data['reason']);

            return $ad;
        });
    }

    public function approved(Advertisement $ad, int $submissionId): AdvertisementSubmission
    {
        $this->requireInstalled();
        $submission = $ad->submissions()->with(['latestReview', 'revision.asset'])->findOrFail($submissionId);
        abort_unless($submission->state() === 'approved', 422, 'This exact submission needs current Platform Admin approval before placement.');
        $this->assertCurrentContext($submission);
        $this->assertOwnedTarget($ad->shop, $submission->revision, $ad);

        return $submission;
    }

    public function approvedRevision(Advertisement $ad, AdvertisementRevision $revision): bool
    {
        if (! $ad->shop_id) {
            return true;
        }
        if (! $this->installed()) {
            return false;
        }
        $submission = $ad->submissions()->where('revision_id', $revision->id)->with('latestReview')->first();
        if (! $submission || $submission->state() !== 'approved' || ! $this->contextCurrent($submission)) {
            return false;
        }
        try {
            $this->assertOwnedTarget($ad->shop, $revision, $ad);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    public function contextCurrent(AdvertisementSubmission $submission): bool
    {
        $shop = $submission->advertisement->shop?->fresh();

        return $shop && $submission->shop_scope === $this->shopScope($shop);
    }

    private function assertCurrentContext(AdvertisementSubmission $submission): void
    {
        if (! $this->contextCurrent($submission)) {
            throw ValidationException::withMessages(['advertisement' => 'The reviewed shop name or category changed after submission. Ask the seller to save and submit a new draft.']);
        }
    }

    private function shopScope(Shop $shop): array
    {
        return ['shop_id' => (int) $shop->id, 'seller_id' => (int) $shop->user_id, 'name' => $shop->name,
            'root_category_id' => (int) $shop->root_category_id, 'root_category_name' => $shop->rootCategory?->name];
    }

    public function archiveReviews(User $actor, Advertisement $ad): void
    {
        app(AdvertisementManager::class)->authorize($actor);
        $this->requireInstalled();
        foreach ($ad->submissions()->with('latestReview')->get() as $submission) {
            if (in_array($submission->state(), ['pending', 'approved'], true)) {
                $this->review($actor, $submission, 'withdrawn', 'Promotion archived by Platform Admin.');
            }
        }
    }

    public function assertOwnedTarget(Shop $shop, AdvertisementRevision $revision, ?Advertisement $ad = null): void
    {
        $invalid = fn ($message) => throw ValidationException::withMessages(['advertisement' => $message]);
        if (! Shop::eligible()->whereKey($shop->id)->whereHas('user', fn ($user) => $user->whereNotNull('email_verified_at'))->exists()
            || ($ad && (int) $ad->created_by_id !== (int) $shop->user_id)
            || Shop::where('user_id', $shop->user_id)->count() !== 1) {
            $invalid('This promotion needs the original seller and its single approved active shop.');
        }
        if (! in_array($revision->destination_type, ['product', 'shop', 'offer'], true) || ! in_array($revision->purpose, ['feature', 'offer'], true)
            || ($revision->destination_type === 'shop' && (int) $revision->destination_id !== (int) $shop->id)) {
            $invalid('Choose a destination from your own approved shop. Platform events and other shops cannot be submitted here.');
        }
        $products = app(AdvertisementDestinationService::class)->products($revision);
        if ($products->isEmpty() || $products->contains(fn ($product) => (int) $product->shop_id !== (int) $shop->id)) {
            $invalid('Every promoted product must be available within your own approved shop category.');
        }
        if ($revision->offer_revision_id) {
            $offer = VoucherRevision::find($revision->offer_revision_id);
            if (! $offer || $offer->issuer_type !== 'shop' || (int) $offer->shop_id !== (int) $shop->id) {
                $invalid('Select a published shop-funded voucher belonging to this shop.');
            }
        }
        app(AdvertisementDestinationService::class)->assertPublishable($revision, $revision->starts_at, $revision->ends_at);
    }

    private function metadata(array $input): array
    {
        $data = app(AdvertisementInput::class)->metadata($input);
        Validator::make($input, array_fill_keys(['slot', 'publication_id', 'replaces_publication_id', 'publish_now', 'claims_reviewed', 'review_status', 'reviewed_by_id', 'reviewer_id', 'decision', 'import_key', 'imported_candidate', 'archived_at', 'shop_scope'], 'prohibited'))->validate();

        return $data;
    }

    private function write(User $actor, callable $authorize, callable $work): Advertisement
    {
        $this->requireInstalled();

        return app(AdvertisementTransaction::class)->run($actor, $authorize, $work);
    }

    private function requireInstalled(): void
    {
        abort_unless($this->installed(), 503, 'Install the seller promotion migration before managing proposals.');
    }

    private function version(?Advertisement $ad, int $version): void
    {
        abort_unless($version === ($ad?->version ?? 0), 409, 'This promotion changed. Reload it before saving or deciding again.');
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function retry(User $actor, string $key, string $fingerprint): ?Advertisement
    {
        $event = AdvertisementAction::where('request_key', $key)->first();
        if (! $event) {
            return null;
        }
        abort_unless((int) $event->actor_id === (int) $actor->id && hash_equals($event->fingerprint, $fingerprint), 409, 'This request was already used for a different promotion change.');

        return Advertisement::findOrFail($event->advertisement_id);
    }

    private function record(User $actor, Advertisement $ad, string $action, array $meta, string $fingerprint, array $before, ?string $reason = null): void
    {
        AdvertisementAction::create(['advertisement_id' => $ad->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
            'request_key' => $meta['request_key'], 'source_version' => $meta['source_version'], 'fingerprint' => $fingerprint,
            'action' => $action, 'reason' => $reason, 'before_state' => $before, 'after_state' => app(AdvertisementManager::class)->snapshot($ad)]);
    }

    private function review(User $actor, AdvertisementSubmission $submission, string $decision, ?string $reason): void
    {
        $review = AdvertisementReview::create(['submission_id' => $submission->id, 'actor_id' => $actor->id,
            'actor_name' => $actor->name, 'actor_role' => $actor->role, 'decision' => $decision, 'reason' => $reason]);
        app(GovernanceNoticeService::class)->record($review);
    }

    private function endPlacements(Advertisement $ad, ?int $revisionId = null): void
    {
        $ad->publications()->when($revisionId, fn ($query) => $query->where('revision_id', $revisionId))
            ->whereNull('ended_at')->where('ends_at', '>', now())->update(['ended_at' => CarbonImmutable::now('UTC')->startOfSecond()]);
    }
}
