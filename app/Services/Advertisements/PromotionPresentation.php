<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\AdvertisementSubmission;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionPresentation
{
    public function listing(User $actor, Request $request): array
    {
        $service = app(SellerPromotionService::class);
        $shop = $service->shop($actor);
        $installed = $service->installed();
        $filters = $request->validate(['search' => 'nullable|string|max:80',
            'review' => ['nullable', Rule::in(['all', 'not_submitted', 'pending', 'approved', 'changes_requested', 'rejected', 'withdrawn', 'superseded'])],
            'page' => 'nullable|integer|min:1|max:100000']);
        $rows = $installed ? Advertisement::where('shop_id', $shop->id)->where('created_by_id', $actor->id)
            ->with(['creator', 'shop', 'latestRevision.asset', 'publications.advertisement', 'publications.revision.asset'])->latest('id')->get()
            ->map(fn ($ad) => app(AdvertisementAdminService::class)->summary($ad, seller: true))->filter(fn ($ad) => (empty($filters['search']) || Str::contains(mb_strtolower($ad['title']), mb_strtolower(trim($filters['search']))))
                && (empty($filters['review']) || $filters['review'] === 'all' || $ad['review_state'] === $filters['review']))->values() : collect();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($rows->count() / 12)));

        return ['promotions' => new LengthAwarePaginator($rows->forPage($page, 12)->values(), $rows->count(), 12, $page,
            ['path' => $request->url(), 'query' => $request->only(['search', 'review'])]),
            'available' => $installed, 'filters' => $filters, 'shop' => $shop->only(['id', 'name']),
            'canSubmit' => Shop::eligible()->whereKey($shop->id)->exists()];
    }

    public function sellerEditor(User $actor, ?Advertisement $ad = null): array
    {
        $service = app(SellerPromotionService::class);
        $shop = $service->shop($actor);
        abort_unless($service->installed(), 503, 'Install the seller promotion migration before managing proposals.');
        $ad = $ad ? $service->find($actor, $ad->id) : null;
        $options = app(AdvertisementDestinationService::class)->options();

        return ['promotion' => $ad ? $this->detail($ad, true) : null, 'shop' => $shop->only(['id', 'name']),
            'canSubmit' => Shop::eligible()->whereKey($shop->id)->exists(),
            'options' => ['products' => $options['products']->where('shop_id', $shop->id)->values(),
                'shops' => $options['shops']->where('id', $shop->id)->values(), 'categories' => [],
                'offers' => $options['offers']->filter(fn ($offer) => (int) ($offer['shop_id'] ?? 0) === (int) $shop->id)->values()]];
    }

    public function adminEditor(User $actor, Advertisement $ad): array
    {
        app(AdvertisementManager::class)->authorize($actor);
        abort_unless(app(SellerPromotionService::class)->installed(), 503, 'Install the seller promotion migration before reviewing proposals.');
        $ad = Advertisement::findOrFail($ad->id);
        abort_unless($ad->shop_id, 404);

        return ['advertisement' => $this->detail($ad, false), 'slots' => AdvertisementInput::SLOTS,
            'displayEnabled' => (bool) config('advertisements.enabled')];
    }

    public function sellerPreview(User $actor, Advertisement $ad, ?int $revisionId): array
    {
        $ad = app(SellerPromotionService::class)->find($actor, $ad->id);
        $revision = $ad->revisions()->with('asset')->when($revisionId, fn ($query) => $query->whereKey($revisionId))->latest('id')->firstOrFail();

        return ['promotionId' => $ad->id, 'revision' => app(AdvertisementAdminService::class)->revision($revision, seller: true)];
    }

    private function detail(Advertisement $ad, bool $seller): array
    {
        $view = app(AdvertisementAdminService::class);
        $revision = $ad->latestRevision()->with('asset')->firstOrFail();
        $submissions = $ad->submissions()->with(['revision.asset', 'latestReview', 'reviews'])->latest('id')->get();

        return [...$view->summary($ad, $seller), 'slug' => $ad->slug, 'draft' => $view->revision($revision, $seller),
            'can_submit_revision' => ! $ad->archived_at && ! $submissions->contains('revision_id', $revision->id),
            'can_stop' => ! $ad->archived_at && $submissions->contains(fn ($submission) => in_array($submission->state(), ['pending', 'approved'], true)),
            'submissions' => $submissions->map(fn ($submission) => $this->submission($submission, $seller))->values(),
            'publications' => $ad->publications()->with(['advertisement', 'revision.asset'])->latest('id')->get()->map(fn ($publication) => $view->publication($publication)),
            'history' => $view->history($ad)];
    }

    private function submission(AdvertisementSubmission $submission, bool $seller): array
    {
        $service = app(SellerPromotionService::class);
        $contextCurrent = $service->contextCurrent($submission);
        $problem = null;
        try {
            $service->assertOwnedTarget($submission->advertisement->shop, $submission->revision, $submission->advertisement);
        } catch (ValidationException $error) {
            $problem = collect($error->errors())->flatten()->first();
        }
        if (! $contextCurrent) {
            $problem = 'The reviewed shop details changed. Save and submit a new draft before placement.';
        } elseif ($submission->revision->ends_at->lessThanOrEqualTo(now())) {
            $problem = 'The requested period has ended. A new draft and review are needed.';
        } elseif (! Storage::disk('local')->exists($submission->revision->asset->image_path)) {
            $problem = 'The submitted artwork is unavailable. Ask the seller for a replacement.';
        }

        return ['id' => $submission->id, 'revision_id' => $submission->revision_id, 'state' => $submission->state(),
            'shop_scope' => $submission->shop_scope, 'context_current' => $contextCurrent, 'eligibility_problem' => $problem,
            'products' => app(AdvertisementDestinationService::class)->products($submission->revision)->map(fn ($product) => [
                'id' => $product->id, 'name' => $product->name, 'price' => $product->price, 'url' => route('products.show', $product->slug),
            ])->values(),
            'submitted_at' => $submission->created_at->toIso8601String(), 'revision' => app(AdvertisementAdminService::class)->revision($submission->revision, $seller),
            'can_review' => $submission->state() === 'pending' && $submission->advertisement->latestRevision->id === $submission->revision_id,
            'reviews' => $submission->reviews->sortByDesc('id')->map(fn ($review) => ['id' => $review->id, 'decision' => $review->decision,
                'actor' => $review->actor_name, 'actor_role' => $review->actor_role, 'reason' => $review->reason, 'created_at' => $review->created_at->toIso8601String()])->values()];
    }
}
