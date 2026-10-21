<?php

namespace App\Services\Vouchers;

use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCampaignAction;
use App\Models\VoucherCampaignDraft;
use App\Models\VoucherRevision;
use App\Models\VoucherRevisionBalance;
use App\Services\AccountRestrictionService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\GovernanceNoticeService;
use App\Services\ShopEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoucherCampaignService
{
    private const RELATIONS = ['draft', 'shop:id,name,user_id', 'currentRevision.balance'];

    public function authorizedQuery(User $actor, ?Shop $shop = null): Builder
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && $actor->email_verified_at, 403);
        if ($shop) {
            $shop = Shop::findOrFail($shop->id);
            abort_unless($actor->isSeller() && $shop->user_id === $actor->id
                && Shop::where('user_id', $actor->id)->count() === 1, 403);
            app(ShopEligibilityService::class)->assertEligible($shop);

            return Voucher::where('shop_id', $shop->id);
        }
        app(AccountRestrictionService::class)->currentActor($actor);

        return Voucher::query();
    }

    public function listing(User $actor, ?Shop $shop = null): array
    {
        $query = $this->authorizedQuery($actor, $shop);
        $campaigns = (clone $query)->with(self::RELATIONS)->latest('id')->paginate(15)->withQueryString();
        $campaigns->through(fn (Voucher $voucher) => $this->present($voucher));
        $all = (clone $query)->with(['currentRevision.balance'])->get();
        $revisions = VoucherRevision::whereIn('voucher_id', (clone $query)->select('id'));
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', (clone $revisions)->select('id'));
        $stats = ['campaigns' => $all->count(), 'active' => $all->filter(fn ($v) => $this->state($v) === 'active')->count(),
            'saves' => DB::table('saved_vouchers')->whereIn('voucher_revision_id', (clone $revisions)->select('id'))->count(),
            'redemptions' => DB::table('voucher_redemptions')->whereIn('voucher_revision_id', (clone $revisions)->select('id'))->count(),
            'legacy_uses' => (int) $all->sum('used_count') - DB::table('voucher_redemptions')->whereIn('voucher_revision_id', (clone $revisions)->select('id'))->count(),
            'cancelled_allocations' => DB::table('voucher_funding_entries')->where('event_type', 'release')
                ->whereIn('voucher_order_allocation_id', DB::table('voucher_order_allocations')
                    ->whereIn('voucher_redemption_id', DB::table('voucher_redemptions')->whereIn('voucher_revision_id', (clone $revisions)->select('id'))->select('id'))->select('id'))->count(),
            'budget_cents' => (int) (clone $revisions)->sum('budget_cents') + (int) VoucherCampaignAction::whereIn('voucher_revision_id', (clone $revisions)->select('id'))->where('action', 'budget_increased')->sum('budget_increase_cents')];
        foreach (['committed_cents', 'spent_cents', 'released_cents'] as $field) {
            $stats[$field] = (int) (clone $balances)->sum($field);
        }
        $stats['remaining_cents'] = $stats['budget_cents'] - $stats['committed_cents'] - $stats['spent_cents'];
        $stats['legacy_uses'] = max(0, $stats['legacy_uses']);

        return ['campaigns' => $campaigns, 'stats' => $stats];
    }

    public function detail(User $actor, Voucher $voucher, ?Shop $shop = null): array
    {
        $voucher = $this->authorizedQuery($actor, $shop)->with(self::RELATIONS)->findOrFail($voucher->id);

        return ['campaign' => $this->present($voucher), 'history' => $voucher->campaignActions()->latest('id')->paginate(15)->withQueryString()
            ->through(fn ($event) => $event->only(['id', 'action', 'actor_name', 'reason', 'voucher_revision_id', 'budget_increase_cents', 'before_state', 'after_state', 'created_at']))];
    }

    public function saveDraft(User $actor, array $input, ?Voucher $voucher = null, ?Shop $shop = null): Voucher
    {
        $meta = $this->metadata($input);

        return DB::transaction(function () use ($actor, $input, $voucher, $shop, $meta) {
            $actor = $this->lockAuthority($actor, $shop);
            $issuer = $shop ? 'shop' : 'platform';
            if ($voucher) {
                $voucher = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
                $this->assertWriter($voucher, $issuer, $shop);
            }
            // Check the retained request before uniqueness and current-version validation.
            $fingerprint = $this->fingerprint(['action' => 'draft_saved', 'voucher_id' => $voucher?->id, 'input' => $input]);
            if ($event = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $event->voucher;
            }
            $scopeInput = $input;
            if ($voucher && ! array_key_exists('product_scope', $input) && ! array_key_exists('product_ids', $input)) {
                $prior = $voucher->draft?->terms ?? $voucher->currentRevision?->terms() ?? [];
                $scopeInput += array_intersect_key($prior, ['product_scope' => true, 'product_ids' => true]);
            }
            $terms = [...$this->terms($input, $issuer, $voucher, $meta['request_key']),
                ...app(VoucherProductScopeService::class)->validate($scopeInput, $shop?->id)];
            if ($voucher) {
                $this->requireVersion($voucher, $meta['source_version']);
                abort_if($voucher->archived_at, 422, 'An archived campaign retains its history. Create a new campaign.');
                $before = $this->snapshot($voucher);
            } else {
                abort_unless($meta['source_version'] === 0, 409, 'A new draft starts at version zero.');
                $voucher = Voucher::create(['code' => $terms['code'], 'name' => $terms['name'], 'description' => $terms['description'],
                    'issuer_type' => $issuer, 'shop_id' => $shop?->id, 'is_active' => false, 'used_count' => 0,
                    'discount_type' => $terms['discount_type'], 'discount_value' => $terms['percentage_bps'] ? $terms['percentage_bps'] / 100 : $terms['value_cents'] / 100,
                    'min_spend' => $terms['minimum_cents'] / 100, 'max_discount' => $terms['maximum_cents'] / 100,
                    'usage_limit' => $terms['global_limit'], 'expires_at' => $terms['ends_at']]);
                $before = ['version' => 0, 'current_revision_id' => null, 'draft' => false];
            }
            VoucherCampaignDraft::updateOrCreate(['voucher_id' => $voucher->id], ['terms' => $terms,
                'saved_by_id' => $actor->id, 'based_on_revision_id' => $voucher->current_revision_id]);
            $voucher->update(['campaign_version' => $voucher->campaign_version + 1]);
            $this->record($actor, $voucher, 'draft_saved', $meta, $fingerprint, $before);

            return $voucher;
        }, 3);
    }

    public function act(User $actor, Voucher $voucher, array $input, ?Shop $shop = null): Voucher
    {
        $data = Validator::make($input, ['action' => 'required|in:publish,pause,resume,archive,increase_budget,suspend,reinstate',
            'reason' => 'nullable|string|min:5|max:1000', 'budget_increase' => ['nullable', 'regex:'.CodMoney::RULE],
            'shop_id' => 'prohibited', 'issuer_type' => 'prohibited', 'funding_party' => 'prohibited', 'actor_id' => 'prohibited'])->validate();
        $meta = $this->metadata($input);
        $moderation = in_array($data['action'], ['suspend', 'reinstate'], true);

        return DB::transaction(function () use ($actor, $voucher, $data, $meta, $shop, $moderation) {
            $original = Voucher::findOrFail($voucher->id);
            $actor = $this->lockAuthority($actor, $shop, $moderation ? $original->shop_id : null);
            $voucher = Voucher::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($moderation) {
                abort_unless($actor->isAdmin() && $voucher->issuer_type === 'shop' && $voucher->current_revision_id, 403);
            } elseif ($data['action'] === 'archive' && $voucher->issuer_type === 'legacy_unknown') {
                abort_unless($voucher->shop_id === $shop?->id || (! $shop && $actor->isAdmin()), 403);
            } else {
                $this->assertWriter($voucher, $shop ? 'shop' : 'platform', $shop);
            }
            $fingerprint = $this->fingerprint(['voucher_id' => $voucher->id, ...$data, ...$meta]);
            if ($event = $this->retry($actor, $meta['request_key'], $fingerprint)) {
                return $event->voucher;
            }
            $this->requireVersion($voucher, $meta['source_version']);
            $voucher->load(['draft', 'currentRevision.balance']);
            $before = $this->snapshot($voucher);
            abort_if($voucher->archived_at, 422, 'This campaign is archived; its order history is retained.');
            $action = $data['action'];
            $increase = 0;
            if ($action === 'publish') {
                abort_if($voucher->suspended_at, 422, 'Platform Admin must clear this suspension before publication.');
                $draft = $voucher->draft;
                abort_unless($draft, 422, 'Save complete draft terms before publication.');
                abort_unless(CarbonImmutable::parse($draft->terms['ends_at'])->isFuture(), 422, 'Choose a future end date before publishing this draft.');
                $scope = app(VoucherProductScopeService::class)->validate($draft->terms, $voucher->shop_id);
                $revision = VoucherRevision::create([...$draft->terms, ...$scope, 'voucher_id' => $voucher->id,
                    'revision_number' => (int) $voucher->revisions()->max('revision_number') + 1, 'issuer_type' => $voucher->issuer_type,
                    'shop_id' => $voucher->shop_id, 'funding_party' => $voucher->issuer_type, 'created_by_id' => $actor->id]);
                $voucher->update(['current_revision_id' => $revision->id, 'is_active' => true]);
                $draft->delete();
                $voucher->unsetRelation('draft')->unsetRelation('currentRevision');
            } elseif ($action === 'archive') {
                $voucher->update(['is_active' => false, 'archived_at' => now()]);
            } else {
                $revision = $voucher->currentRevision;
                abort_unless($revision, 422, 'This campaign needs complete published funding terms.');
                $balance = VoucherRevisionBalance::where('voucher_revision_id', $revision->id)->lockForUpdate()->firstOrFail();
                $revision->setRelation('balance', $balance);
                if ($action === 'increase_budget') {
                    $increase = CodMoney::cents((string) ($data['budget_increase'] ?? '0'));
                    abort_unless($increase > 0 && $revision->approvedBudget() + $increase <= 99999999999, 422, 'Enter a positive increase within the spending ceiling.');
                    abort_unless(! $revision->ends_at->isPast() && ! $voucher->suspended_at, 422, 'An ended or suspended campaign cannot receive a budget increase.');
                    $this->requireReason($data);
                    $action = 'budget_increased';
                } elseif ($action === 'suspend') {
                    $this->requireReason($data);
                    abort_if($voucher->suspended_at, 422, 'This campaign is already suspended.');
                    $voucher->update(['is_active' => false, 'suspended_at' => now()]);
                } elseif ($action === 'reinstate') {
                    $this->requireReason($data);
                    abort_unless($voucher->suspended_at, 422, 'This campaign has no suspension to clear.');
                    $voucher->update(['suspended_at' => null, 'is_active' => false]);
                } elseif ($action === 'resume') {
                    abort_if($voucher->suspended_at, 422, 'Platform Admin must clear this suspension first.');
                    abort_unless($revision->ends_at->isFuture() && $balance->redemption_count < $revision->global_limit
                        && $revision->approvedBudget() > $balance->committed_cents + $balance->spent_cents, 422, 'An expired or exhausted campaign cannot resume.');
                    abort_if($voucher->is_active, 422, 'This campaign is already published.');
                    $voucher->update(['is_active' => true]);
                } else {
                    abort_unless($voucher->is_active, 422, 'This campaign is already paused.');
                    $voucher->update(['is_active' => false]);
                }
            }
            $voucher->update(['campaign_version' => $voucher->campaign_version + 1]);
            $event = $this->record($actor, $voucher, $action, $meta, $fingerprint, $before, $data['reason'] ?? null, $increase);
            if ($moderation) {
                app(GovernanceNoticeService::class)->record($event);
            }

            return $voucher;
        }, 3);
    }

    public function state(Voucher $voucher, int $additionalBudget = 0): string
    {
        if ($voucher->archived_at) {
            return 'archived';
        }
        if (! $revision = $voucher->currentRevision) {
            return $voucher->issuer_type === 'legacy_unknown' ? 'legacy_review' : 'draft';
        }
        if ($revision->ends_at->lessThanOrEqualTo(now())) {
            return 'expired';
        }
        if (! $revision->balance || $revision->balance->redemption_count >= $revision->global_limit
            || $revision->approvedBudget() + $additionalBudget <= $revision->balance->committed_cents + $revision->balance->spent_cents) {
            return 'exhausted';
        }
        if ($voucher->suspended_at) {
            return 'suspended';
        }
        if ($voucher->issuer_type === 'shop' && (! $voucher->shop || ! app(ShopEligibilityService::class)->isEligible($voucher->shop))) {
            return 'unavailable';
        }
        if (! $voucher->is_active) {
            return 'paused';
        }
        if ($revision->starts_at->isFuture()) {
            return 'scheduled';
        }

        return 'active';
    }

    public function present(Voucher $voucher): array
    {
        $voucher->loadMissing(self::RELATIONS);
        $revision = $voucher->currentRevision;
        $draft = $voucher->draft?->terms;
        $terms = $draft ?? $revision?->terms();

        return ['id' => $voucher->id, 'code' => $voucher->code, 'name' => $revision?->name ?? $draft['name'] ?? $voucher->name,
            'issuer_type' => $voucher->issuer_type, 'shop_name' => $voucher->shop?->name,
            'state' => $this->state($voucher), 'version' => $voucher->campaign_version, 'has_draft' => $draft !== null,
            'current_revision_id' => $voucher->current_revision_id, 'draft_terms' => $draft,
            'selected_products' => app(VoucherProductScopeService::class)->sellerRows($voucher->shop_id, $draft ?? $revision?->terms() ?? []),
            'terms' => $terms, 'published_terms' => $revision?->terms(),
            'approved_budget_cents' => $revision?->approvedBudget(), 'balance' => $revision?->balance?->only(['redemption_count', 'committed_cents', 'spent_cents', 'released_cents']),
            'legacy_used_count' => $revision ? null : $voucher->used_count, 'suspended_at' => $voucher->suspended_at?->toIso8601String()];
    }

    private function lockAuthority(User $actor, ?Shop $shop, ?int $moderatedShopId = null): User
    {
        $ownerId = $shop?->user_id ?? ($moderatedShopId ? Shop::findOrFail($moderatedShopId)->user_id : null);
        $users = User::whereIn('id', array_filter([$actor->id, $ownerId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actor->id);
        abort_unless($actor && $actor->canAccessPortal() && $actor->email_verified_at, 403);
        if ($shop) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            app(ShopEligibilityService::class)->lockCategories();
            abort_unless($actor->isSeller() && $shop->user_id === $actor->id && Shop::where('user_id', $actor->id)->count() === 1, 403);
            app(ShopEligibilityService::class)->assertEligible($shop);
        } else {
            app(AccountRestrictionService::class)->currentActor($actor);
            if ($moderatedShopId) {
                Shop::whereKey($moderatedShopId)->lockForUpdate()->firstOrFail();
            }
        }

        return $actor;
    }

    private function assertWriter(Voucher $voucher, string $issuer, ?Shop $shop): void
    {
        abort_unless($voucher->issuer_type === $issuer && $voucher->shop_id === $shop?->id, 403, 'Only the original campaign issuer can manage its terms.');
    }

    private function metadata(array $input): array
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'source_version' => 'required|integer|min:0'])->validate();

        return ['request_key' => $data['request_key'], 'source_version' => (int) $data['source_version']];
    }

    private function terms(array $input, string $issuer, ?Voucher $voucher, string $key): array
    {
        $input['code'] = is_string($input['code'] ?? null) && trim($input['code'], ' ') !== ''
            ? strtoupper(trim($input['code'], ' ')) : ($voucher?->code ?? 'BGO'.strtoupper(substr(hash('sha256', $key), 0, 9)));
        $data = Validator::make($input, [
            'code' => $voucher
                ? ['required', 'string', Rule::in([$voucher->code])]
                : ['required', 'string', 'min:4', 'max:12', 'regex:/\A[A-Z0-9]+\z/', Rule::unique('vouchers', 'code')],
            'name' => 'required|string|max:100', 'description' => 'nullable|string|max:1000',
            'discount_type' => ['required', Rule::in($issuer === 'shop' ? ['fixed', 'percent'] : ['fixed', 'percent', 'free_shipping'])],
            'discount_value' => ['required', 'regex:'.CodMoney::RULE], 'min_spend' => ['required', 'regex:'.CodMoney::RULE],
            'max_discount' => ['required', 'regex:'.CodMoney::RULE], 'budget' => ['required', 'regex:'.CodMoney::RULE],
            'usage_limit' => 'required|integer|min:1|max:1000000', 'per_buyer_limit' => 'required|integer|min:1|lte:usage_limit',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'shop_id' => 'prohibited', 'issuer_type' => 'prohibited', 'funding_party' => 'prohibited', 'actor_id' => 'prohibited',
            'is_active' => 'prohibited', 'current_revision_id' => 'prohibited', 'used_count' => 'prohibited',
        ], ['code.regex' => 'Use 4–12 letters and numbers without spaces or symbols.'])->validate();
        if ($voucher && $data['code'] !== $voucher->code) {
            throw ValidationException::withMessages(['code' => 'Keep the original campaign code. Create a separate campaign for another code.']);
        }
        $value = CodMoney::cents((string) $data['discount_value']);
        $minimum = CodMoney::cents((string) $data['min_spend']);
        $maximum = CodMoney::cents((string) $data['max_discount']);
        $budget = CodMoney::cents((string) $data['budget']);
        $percent = $data['discount_type'] === 'percent';
        $errors = [];
        if ($maximum < 1 || $budget < $maximum) {
            $errors['budget'] = 'Set a positive maximum discount and enough budget for at least that amount.';
        }
        if (($percent && ($value < 1 || $value > ($issuer === 'shop' ? 9000 : 10000)))
            || (! $percent && $data['discount_type'] === 'fixed' && $value < 1)) {
            $errors['discount_value'] = $percent ? ($issuer === 'shop' ? 'Use 0.01% to 90% so the seller payout stays affordable.' : 'Use 0.01% to 100%.') : 'Use a positive fixed discount.';
        }
        if ($issuer === 'shop' && ! $percent && min($value, $maximum) > SellerSettlementService::split($minimum)['seller_cents']) {
            $errors['min_spend'] = 'The minimum purchase must cover this discount within the seller’s 90% share.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['code' => $data['code'], 'name' => trim($data['name']), 'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'], 'value_cents' => $percent ? 0 : $value, 'percentage_bps' => $percent ? $value : null,
            'minimum_cents' => $minimum, 'maximum_cents' => $maximum, 'budget_cents' => $budget,
            'global_limit' => (int) $data['usage_limit'], 'per_buyer_limit' => (int) $data['per_buyer_limit'],
            'starts_at' => CarbonImmutable::parse($data['starts_at'], 'Asia/Manila')->utc()->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'], 'Asia/Manila')->utc()->toIso8601String()];
    }

    private function requireVersion(Voucher $voucher, int $version): void
    {
        app(AccountRestrictionService::class)->requireCurrent($voucher->campaign_version === $version,
            ['version' => $voucher->campaign_version], 'This campaign changed. Reload before saving or deciding.', []);
    }

    private function requireReason(array $data): void
    {
        if (empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Explain this decision in at least five characters.']);
        }
    }

    private function snapshot(Voucher $voucher): array
    {
        return ['version' => $voucher->campaign_version, 'current_revision_id' => $voucher->current_revision_id,
            'state' => $this->state($voucher), 'draft' => $voucher->draft()->exists(),
            'approved_budget_cents' => $voucher->currentRevision?->approvedBudget()];
    }

    private function fingerprint(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }

    private function retry(User $actor, string $key, string $fingerprint): ?VoucherCampaignAction
    {
        $event = VoucherCampaignAction::where('request_key', $key)->first();
        if ($event) {
            abort_unless($event->actor_id === $actor->id && hash_equals($event->fingerprint, $fingerprint), 409, 'This request was already used for a different campaign change.');
        }

        return $event;
    }

    private function record(User $actor, Voucher $voucher, string $action, array $meta, string $fingerprint, array $before, ?string $reason = null, int $increase = 0): VoucherCampaignAction
    {
        $voucher->unsetRelation('currentRevision')->unsetRelation('draft');
        $after = $this->snapshot($voucher);
        if ($increase) {
            $after['approved_budget_cents'] += $increase;
            $after['state'] = $this->state($voucher, $increase);
        }
        $event = VoucherCampaignAction::create(['voucher_id' => $voucher->id, 'voucher_revision_id' => $voucher->current_revision_id,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action, ...$meta, 'fingerprint' => $fingerprint,
            'before_state' => $before, 'after_state' => $after, 'reason' => $reason, 'budget_increase_cents' => $increase]);
        if ($increase) {
            // Read again after the immutable increase exists; the prior snapshot stays retained.
            $voucher->unsetRelation('currentRevision');
        }

        return $event;
    }
}
