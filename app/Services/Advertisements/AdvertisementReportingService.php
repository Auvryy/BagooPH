<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementPublication;
use App\Models\AdvertisementRevision;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisementReportingService
{
    public function report(User $actor, Request $request, bool $seller = false): array
    {
        $shop = $seller ? app(SellerPromotionService::class)->shop($actor) : null;
        if (! $seller) {
            app(AdvertisementManager::class)->authorize($actor);
        }
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $minimum = $today->subYear()->toDateString();
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$minimum, 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$minimum, 'before_or_equal:'.$today->toDateString()],
            'advertisement_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'revision_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'publication_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'max:2147483647'],
            'page' => 'nullable|integer|min:1|max:100000']);
        $from = $input['from'] ?? $today->subDays(29)->toDateString();
        $to = $input['to'] ?? $today->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 89) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start, covering at most 90 days.']);
        }
        $filters = ['from' => $from, 'to' => $to];
        foreach (['advertisement_id', 'revision_id', 'publication_id'] as $field) {
            $filters[$field] = empty($input[$field]) ? null : (int) $input[$field];
        }
        $base = ['filters' => $filters, 'dateLimits' => ['minimum' => $minimum, 'maximum' => $today->toDateString()],
            'seller' => $seller, 'available' => false, 'collecting' => false, 'totals' => null,
            'placements' => null, 'failureReference' => null, 'options' => ['advertisements' => [], 'revisions' => [], 'placements' => []]];
        if (! app(AdvertisementManager::class)->installed() || ($seller && ! app(SellerPromotionService::class)->installed())) {
            return $base;
        }
        $ads = Advertisement::query()->when($shop, fn ($query) => $query->where('shop_id', $shop->id)->where('created_by_id', $actor->id));
        if ($filters['advertisement_id']) {
            abort_unless((clone $ads)->whereKey($filters['advertisement_id'])->exists(), 404);
        }
        $selectedAds = (clone $ads)->when($filters['advertisement_id'], fn ($query, $id) => $query->whereKey($id));
        if ($filters['revision_id']) {
            abort_unless(AdvertisementRevision::whereIn('advertisement_id', (clone $selectedAds)->select('id'))->whereKey($filters['revision_id'])->exists(), 404);
        }
        $scope = AdvertisementPublication::whereIn('advertisement_id', (clone $selectedAds)->select('id'))
            ->when($filters['revision_id'], fn ($query, $id) => $query->where('revision_id', $id));
        if ($filters['publication_id']) {
            abort_unless((clone $scope)->whereKey($filters['publication_id'])->exists(), 404);
        }
        $scope->when($filters['publication_id'], fn ($query, $id) => $query->whereKey($id));
        if (! app(AdvertisementMeasurementService::class)->installed()) {
            return $base;
        }
        try {
            $totals = DB::table('advertisement_daily_statistics')->whereIn('publication_id', (clone $scope)->select('id'))
                ->whereBetween('day', [$from, $to])->selectRaw('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks')->first();
            $rows = (clone $scope)->with(['advertisement.creator', 'revision'])->select('advertisement_publications.*');
            foreach (['impressions', 'clicks'] as $column) {
                $rows->selectSub(DB::table('advertisement_daily_statistics')->selectRaw("COALESCE(SUM({$column}), 0)")
                    ->whereColumn('publication_id', 'advertisement_publications.id')->whereBetween('day', [$from, $to]), $column);
            }
            $placements = $rows->latest('id')->paginate(25)->withQueryString()->through(fn ($publication) => [
                'id' => $publication->id, 'advertisement_id' => $publication->advertisement_id, 'revision_id' => $publication->revision_id,
                'revision_number' => $publication->revision->revision_number, 'title' => $publication->revision->title,
                'owner' => $publication->advertisement->shop_id ? 'Seller' : 'Platform', 'creator' => $publication->advertisement->creator->name,
                'purpose' => $publication->revision->purpose, 'slot' => $publication->slot,
                'starts_at' => $publication->starts_at->toIso8601String(), 'ends_at' => $publication->ends_at->toIso8601String(),
                'effective_end' => $publication->effectiveEnd()->toIso8601String(),
                'state' => app(AdvertisementReader::class)->state($publication)['state'],
                ...$this->counts((int) $publication->impressions, (int) $publication->clicks),
            ]);
            $choices = (clone $ads)->with('latestRevision')->latest('id')->limit(250)->get()->map(fn ($ad) => ['id' => $ad->id, 'title' => $ad->latestRevision?->title ?? 'Untitled advertisement']);
            $revisionChoices = AdvertisementRevision::whereIn('advertisement_id', (clone $ads)->select('id'))
                ->whereHas('publications')->latest('id')->limit(250)->get(['id', 'advertisement_id', 'title', 'revision_number'])
                ->map(fn ($revision) => ['id' => $revision->id, 'advertisement_id' => $revision->advertisement_id, 'title' => $revision->title.' · Revision '.$revision->revision_number]);
            $placementChoices = AdvertisementPublication::whereIn('advertisement_id', (clone $ads)->select('id'))
                ->latest('id')->limit(250)->get(['id', 'advertisement_id', 'slot', 'revision_id'])
                ->map(fn ($publication) => ['id' => $publication->id, 'advertisement_id' => $publication->advertisement_id, 'revision_id' => $publication->revision_id,
                    'title' => 'Placement '.$publication->id.' · '.str_replace('_', ' ', $publication->slot)]);

            return [...$base, 'available' => true, 'collecting' => app(AdvertisementMeasurementService::class)->collecting(),
                'totals' => $this->counts((int) $totals->impressions, (int) $totals->clicks), 'placements' => $placements,
                'options' => ['advertisements' => $choices, 'revisions' => $revisionChoices, 'placements' => $placementChoices]];
        } catch (QueryException $error) {
            // Unavailable counters are distinct from a measured zero.
            return [...$base, 'failureReference' => app(AdvertisementMeasurementService::class)->unavailable($error)];
        }
    }

    private function counts(int $impressions, int $clicks): array
    {
        return ['impressions' => $impressions, 'clicks' => $clicks,
            'click_through_rate' => $impressions > 0 ? round(100 * $clicks / $impressions, 2) : null];
    }
}
