<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementAsset;
use App\Models\AdvertisementPublication;
use App\Services\Commerce\BuyerDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class AdvertisementReader
{
    public function state(AdvertisementPublication $publication): array
    {
        $now = CarbonImmutable::now('UTC');
        if ($publication->advertisement->archived_at) {
            return ['state' => 'archived', 'reason' => 'This advertisement is archived.'];
        }
        if ($publication->effectiveEnd()->lessThanOrEqualTo($now)
            || $publication->effectiveEnd()->lessThanOrEqualTo($publication->starts_at)) {
            return ['state' => 'ended', 'reason' => 'This placement has ended.'];
        }
        if ($publication->paused_at) {
            return ['state' => 'paused', 'reason' => 'Paused placements retain their reserved position until ended.'];
        }
        if (! config('advertisements.enabled')) {
            return ['state' => 'unavailable', 'reason' => 'Marketplace advertisement display is currently disabled.'];
        }
        if (! app(SellerPromotionService::class)->approvedRevision($publication->advertisement, $publication->revision)) {
            return ['state' => 'unavailable', 'reason' => 'The seller submission no longer has current approval or eligibility.'];
        }
        if (! $publication->claims_reviewed || ! $publication->revision->asset
            || ! Storage::disk('local')->exists($publication->revision->asset->image_path)) {
            return ['state' => 'unavailable', 'reason' => 'The reviewed artwork is unavailable.'];
        }
        $future = $publication->starts_at->greaterThan($now);
        $problem = app(AdvertisementDestinationService::class)->problem($publication->revision, $future,
            $publication->starts_at, $publication->effectiveEnd());
        if ($problem) {
            return ['state' => 'unavailable', 'reason' => $problem];
        }

        return ['state' => $future ? 'scheduled' : 'live', 'reason' => null];
    }

    public function homepage(): array
    {
        if (! config('advertisements.enabled') || ! app(AdvertisementManager::class)->installed()) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $publications = AdvertisementPublication::with(['advertisement', 'revision.asset'])
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->whereNull('paused_at')->whereNull('ended_at')
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhere('superseded_at', '>', $now))
            ->whereHas('advertisement', fn ($query) => $query->whereNull('archived_at'))->orderBy('slot')->orderBy('id')->get();
        $banners = [];
        foreach ($publications as $publication) {
            if (isset($banners[$publication->slot]) || ! in_array($publication->slot, AdvertisementInput::SLOTS, true)
                || $this->state($publication)['state'] !== 'live') {
                continue;
            }
            $banners[$publication->slot] = $this->banner($publication);
        }

        return array_values($banners);
    }

    public function publication(string $slug, ?int $publicationId = null): AdvertisementPublication
    {
        abort_unless(app(AdvertisementManager::class)->installed(), 404);
        $ad = Advertisement::where('slug', $slug)->firstOrFail();
        $query = $ad->publications()->with(['advertisement', 'revision.asset'])->where('claims_reviewed', true)
            ->where('starts_at', '<=', CarbonImmutable::now('UTC'));
        if ($publicationId) {
            return $query->whereKey($publicationId)->firstOrFail();
        }
        $publications = $query->orderByDesc('starts_at')->orderByDesc('id')->get();

        return $publications->first(fn ($publication) => $this->state($publication)['state'] === 'live')
            ?? $publications->first() ?? abort(404);
    }

    public function page(AdvertisementPublication $publication): array
    {
        $revision = $publication->revision;
        $available = $this->state($publication)['state'] === 'live';

        return ['promotion' => [
            'title' => $available ? $revision->title : 'Promotion unavailable', 'summary' => $available ? $revision->summary : null, 'available' => $available,
            'state' => $available ? 'live' : 'unavailable', 'image' => $available ? $this->imageUrl($revision->asset) : null,
            'alt_text' => $available ? $revision->alt_text : '', 'width' => $revision->asset->width, 'height' => $revision->asset->height,
            'starts_at' => $publication->starts_at->toIso8601String(), 'ends_at' => $publication->effectiveEnd()->toIso8601String(),
            'offer' => $available ? app(AdvertisementDestinationService::class)->offerTerms($revision) : null,
            'target' => $available ? app(AdvertisementDestinationService::class)->target($revision) : null,
        ], 'products' => $available ? app(AdvertisementDestinationService::class)->products($revision)->values() : [],
            'categories' => app(BuyerDiscoveryService::class)->categories()];
    }

    public function banner(AdvertisementPublication $publication): array
    {
        $revision = $publication->revision;

        return ['id' => $publication->id, 'title' => $revision->title, 'alt_text' => $revision->alt_text,
            'image' => $this->imageUrl($revision->asset), 'width' => $revision->asset->width, 'height' => $revision->asset->height,
            'slot' => $publication->slot, 'placement' => str_starts_with($publication->slot, 'main_') ? 'main' : 'side',
            'href' => route('advertisements.visit', ['slug' => $publication->advertisement->slug, 'publication' => $publication->id])];
    }

    public function imageUrl(AdvertisementAsset $asset, bool $preview = false): string
    {
        return $preview ? route('admin.advertisements.artwork', $asset->id) : route('advertisements.artwork', $asset->token);
    }

    public function publicAsset(string $token): AdvertisementAsset
    {
        abort_unless(app(AdvertisementManager::class)->installed(), 404);
        $asset = AdvertisementAsset::where('token', $token)->firstOrFail();
        // Once shown publicly, a historical rendition contains no private draft/source metadata.
        abort_unless(AdvertisementPublication::where('claims_reviewed', true)->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhereColumn('ended_at', '>', 'starts_at'))
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhereColumn('superseded_at', '>', 'starts_at'))
            ->whereHas('revision', fn ($query) => $query->where('asset_id', $asset->id))->exists(), 404);

        return $asset;
    }
}
