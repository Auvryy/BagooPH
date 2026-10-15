<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementAction;
use App\Models\AdvertisementPublication;
use App\Models\AdvertisementRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdvertisementAdminService
{
    public function find(User $actor, int $id): Advertisement
    {
        app(AdvertisementManager::class)->authorize($actor);
        abort_unless(app(AdvertisementManager::class)->installed(), 503, 'Install the pending advertisement migration before managing promotions.');

        return Advertisement::findOrFail($id);
    }

    public function listing(User $actor, Request $request): array
    {
        app(AdvertisementManager::class)->authorize($actor);
        $filters = $request->validate(['search' => 'nullable|string|max:80',
            'state' => ['nullable', Rule::in(['all', 'draft', 'live', 'scheduled', 'paused', 'ended', 'archived', 'unavailable'])],
            'scope' => ['nullable', Rule::in(['all', 'platform', 'seller'])],
            'review' => ['nullable', Rule::in(['all', 'not_submitted', 'pending', 'approved', 'changes_requested', 'rejected', 'withdrawn', 'superseded'])],
            'page' => 'nullable|integer|min:1|max:100000']);
        $installed = app(AdvertisementManager::class)->installed();
        $rows = $installed ? Advertisement::with(['creator', 'latestRevision.asset', 'publications.revision.asset', 'publications.advertisement'])->latest('id')->get()
            ->map(fn ($ad) => $this->summary($ad))->filter(function ($ad) use ($filters) {
                return (! ($filters['search'] ?? '') || Str::contains(mb_strtolower($ad['title']), mb_strtolower(trim($filters['search']))))
                    && (empty($filters['state']) || $filters['state'] === 'all'
                        || ($filters['state'] === 'draft' ? $ad['has_draft'] : $ad['state'] === $filters['state']))
                    && (empty($filters['scope']) || $filters['scope'] === 'all' || $ad['owner_type'] === $filters['scope'])
                    && (empty($filters['review']) || $filters['review'] === 'all' || $ad['review_state'] === $filters['review']);
            })->values() : collect();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($rows->count() / 15)));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 15)->values(), $rows->count(), 15, $page,
            ['path' => $request->url(), 'query' => $request->only(['search', 'state', 'scope', 'review'])]);

        return ['advertisements' => $paginator, 'available' => $installed, 'displayEnabled' => (bool) config('advertisements.enabled'),
            'filters' => $filters, 'suppliedArtwork' => collect(AdvertisementManager::ARTWORK)->map(fn ($title, $key) => [
                'key' => $key, 'title' => $title, 'image' => '/images/buyer/advertisements/'.$key.'.webp',
                'imported' => $installed && Advertisement::where('import_key', $key)->exists(),
            ])->values()];
    }

    public function editor(User $actor, ?Advertisement $advertisement = null): array
    {
        app(AdvertisementManager::class)->authorize($actor);
        abort_unless(app(AdvertisementManager::class)->installed(), 503, 'Install the pending advertisement migration before managing promotions.');
        $ad = $advertisement ? $this->find($actor, $advertisement->id) : null;
        $revision = $ad?->latestRevision()->with('asset')->first();
        $publications = $ad ? $ad->publications()->with(['advertisement', 'revision.asset'])->orderByDesc('id')->get() : collect();

        return ['advertisement' => $ad ? [...$this->summary($ad), 'slug' => $ad->slug, 'draft' => $revision ? $this->revision($revision) : null,
            'publications' => $publications->map(fn ($publication) => $this->publication($publication))->values(),
            'history' => $this->history($ad),
        ] : null, 'options' => app(AdvertisementDestinationService::class)->options(), 'slots' => AdvertisementInput::SLOTS,
            'displayEnabled' => (bool) config('advertisements.enabled')];
    }

    public function preview(User $actor, Advertisement $ad, ?int $revisionId, ?string $slot = null): array
    {
        $ad = $this->find($actor, $ad->id);
        $revision = $ad->revisions()->with('asset')->when($revisionId, fn ($query) => $query->whereKey($revisionId))->latest('id')->firstOrFail();
        $position = $slot ?? $revision->slot ?? 'main_1';
        $ownedPlacements = $ad->publications()->pluck('id');
        $banners = collect(app(AdvertisementReader::class)->homepage())->reject(fn ($banner) => $banner['slot'] === $position || $ownedPlacements->contains($banner['id']))->values();
        $banners->push(['id' => -$revision->id, 'title' => $revision->title, 'alt_text' => $revision->alt_text,
            'image' => app(AdvertisementReader::class)->imageUrl($revision->asset, true), 'width' => $revision->asset->width, 'height' => $revision->asset->height,
            'slot' => $position, 'placement' => str_starts_with($position, 'main_') ? 'main' : 'side', 'href' => '#advertisement-preview']);

        return ['advertisementId' => $ad->id, 'revision' => $this->revision($revision), 'banners' => $banners->sortBy('slot')->values(), 'previewSlot' => $position,
            'problem' => app(AdvertisementDestinationService::class)->problem($revision, true)];
    }

    public function history(Advertisement $ad)
    {
        return AdvertisementAction::where('advertisement_id', $ad->id)->orderByDesc('id')->paginate(15, ['*'], 'history_page')->withQueryString()
            ->through(fn ($action) => ['id' => $action->id, 'action' => $action->action, 'actor' => $action->actor_name, 'reason' => $action->reason,
                'created_at' => $action->created_at->toIso8601String(), 'before_version' => $action->before_state['version'] ?? 0,
                'after_version' => $action->after_state['version'], 'revision_id' => $action->after_state['revision_id']]);
    }

    public function summary(Advertisement $ad, bool $seller = false): array
    {
        $revision = $ad->latestRevision;
        $publications = $ad->publications->sortByDesc('id');
        $placements = $publications->map(fn ($publication) => $this->publication($publication));
        $active = $placements->firstWhere('state', 'live') ?? $placements->firstWhere('state', 'scheduled')
            ?? $placements->firstWhere('state', 'paused') ?? $placements->first();

        $submission = $ad->shop_id && app(SellerPromotionService::class)->installed()
            ? $ad->submissions()->where('revision_id', $revision?->id)->with('latestReview')->first() : null;

        return ['id' => $ad->id, 'version' => $ad->version, 'title' => $revision?->title ?? 'Untitled advertisement',
            'owner_type' => $ad->shop_id ? 'seller' : 'platform', 'shop_name' => $ad->shop_id ? $ad->shop?->name : null,
            'review_state' => $submission?->state() ?? 'not_submitted', 'current_submission_id' => $submission?->id,
            'creator' => $ad->creator?->name, 'target' => $revision ? app(AdvertisementDestinationService::class)->target($revision) : [],
            'state' => $ad->archived_at ? 'archived' : ($active['state'] ?? 'draft'), 'reason' => $active['reason'] ?? null,
            'has_draft' => $revision && ! $publications->contains('revision_id', $revision->id),
            'archived' => $ad->archived_at !== null, 'slot' => $active['slot'] ?? $revision?->slot,
            'image' => $revision ? ($seller ? route('seller.promotions.artwork', $revision->asset_id) : app(AdvertisementReader::class)->imageUrl($revision->asset, true)) : null,
            'starts_at' => $active['starts_at'] ?? null, 'ends_at' => $active['effective_end'] ?? null];
    }

    public function revision(AdvertisementRevision $revision, bool $seller = false): array
    {
        return $revision->only(['id', 'revision_number', 'title', 'alt_text', 'summary', 'purpose', 'destination_type', 'destination_id', 'product_ids', 'offer_revision_id', 'slot', 'imported_candidate'])
            + ['image' => $seller ? route('seller.promotions.artwork', $revision->asset_id) : app(AdvertisementReader::class)->imageUrl($revision->asset, true), 'image_bytes' => $revision->asset->image_bytes,
                'width' => $revision->asset->width, 'height' => $revision->asset->height,
                'starts_at' => $revision->starts_at?->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'ends_at' => $revision->ends_at?->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'target' => app(AdvertisementDestinationService::class)->target($revision),
                'offer' => app(AdvertisementDestinationService::class)->offerTerms($revision)];
    }

    public function publication(AdvertisementPublication $publication): array
    {
        return ['id' => $publication->id, 'revision_id' => $publication->revision_id, 'slot' => $publication->slot,
            'title' => $publication->revision->title, 'starts_at' => $publication->starts_at->toIso8601String(),
            'ends_at' => $publication->ends_at->toIso8601String(), 'effective_end' => $publication->effectiveEnd()->toIso8601String(),
            'replaces_publication_id' => $publication->replaces_publication_id,
            ...app(AdvertisementReader::class)->state($publication)];
    }
}
