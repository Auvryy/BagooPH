<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductModerationDecision;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductModerationService
{
    public function __construct(private readonly AccountRestrictionService $governance, private readonly ShopEligibilityService $shops) {}

    public function validate(array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim(\Normalizer::normalize($input['reason'], \Normalizer::FORM_KC), ' ');
        }

        return Validator::make($input, [
            'action' => 'required|in:remove,reinstate',
            'source_token' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
            'reason' => ['bail', 'required', 'string', new ApplicationText('notes', 5, 1000)],
            'status' => 'prohibited', 'price' => 'prohibited', 'stock' => 'prohibited',
            'shop_id' => 'prohibited', 'category_id' => 'prohibited', 'actor_id' => 'prohibited',
            'compliance_restricted' => 'prohibited', 'moderation_version' => 'prohibited',
        ])->validate();
    }

    public function state(Product $product): array
    {
        $shop = Shop::with('user')->findOrFail($product->shop_id);

        return [
            'product' => $product->only(['id', 'shop_id', 'category_id', 'name', 'slug', 'description', 'status', 'price', 'compare_at_price', 'weight_kg', 'stock', 'variants', 'sku', 'featured_image', 'compliance_restricted', 'moderation_version']),
            'images' => $product->images()->orderBy('id')->get(['id', 'image_url', 'is_primary', 'sort_order'])->toArray(),
            'shop' => $shop->only(['id', 'user_id', 'name', 'status', 'review_status', 'review_decision_id', 'review_version', 'restriction_version', 'root_category_id']),
            'seller' => $shop->user?->only(['id', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'identity_version', 'restriction_version', 'closed_at']),
            'shop_eligible' => $this->shops->isEligible($shop),
            'category_eligible' => in_array((int) $product->category_id, $this->shops->categoryIds($shop), true),
        ];
    }

    public function actions(Product $product): array
    {
        if (! in_array($product->status, ['active', 'draft', 'archived'], true)) {
            return [];
        }

        return $product->compliance_restricted
            ? (in_array($product->status, ['active', 'draft'], true) ? ['reinstate'] : []) : ['remove'];
    }

    public function presentation(User $actor, Product $product): array
    {
        $this->governance->currentActor($actor);
        $product = $product->fresh();
        $state = $this->state($product);

        return [
            'id' => $product->id, 'name' => $product->name, 'state' => $state,
            'source_token' => $this->governance->token($state), 'actions' => $this->actions($product),
            'history' => $product->moderationDecisions()->orderByDesc('id')->get()->map(fn ($decision) => [
                'id' => $decision->id, 'action' => $decision->action, 'reason' => $decision->reason,
                'actor' => $decision->actor_name, 'decided_at' => $decision->decided_at->toISOString(),
                'before_restricted' => $decision->before_state['product']['compliance_restricted'],
                'after_restricted' => $decision->after_state['product']['compliance_restricted'],
                'prior_decision_id' => $decision->prior_decision_id,
            ])->all(),
            'note' => 'Compliance decisions leave seller listing status, prices, stock and purchased items unchanged. Reinstatement also needs an eligible seller, shop and category.',
        ];
    }

    public function decide(User $actor, Product $product, array $input): ProductModerationDecision
    {
        $this->governance->currentActor($actor);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $product, $data) {
            $original = Product::findOrFail($product->id);
            $ownerId = Shop::findOrFail($original->shop_id)->user_id;
            // Match checkout and seller writers: ordered users -> shop -> categories -> product.
            $users = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            abort_unless($actor?->isAdmin() && $actor->canAccessPortal(), 403);
            $shop = Shop::whereKey($original->shop_id)->lockForUpdate()->firstOrFail();
            $this->shops->lockCategories();
            $product = Product::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $this->governance->requireCurrent($product->shop_id === $shop->id && $shop->user_id === $ownerId,
                ['id' => $product->id], 'The product ownership changed. Reload before deciding.', []);
            $before = $this->state($product);
            $existing = $product->moderationDecisions()->where('source_token', $data['source_token'])->first();
            if ($existing) {
                $this->governance->requireCurrent($existing->actor_id === $actor->id && $existing->action === $data['action'] && $existing->reason === $data['reason'],
                    $before['product'], 'This product source was already decided differently. Reload its current state.', $this->actions($product));

                return $existing;
            }
            $this->governance->requireCurrent(hash_equals($this->governance->token($before), $data['source_token']),
                $before['product'], 'The product or its selling scope changed. Reload before deciding.', $this->actions($product));
            $this->governance->requireCurrent(in_array($data['action'], $this->actions($product), true),
                $before['product'], 'This action is not permitted from the current listing state.', $this->actions($product));
            if ($data['action'] === 'reinstate') {
                $this->governance->requireCurrent($before['shop_eligible'] && $before['category_eligible'],
                    $before['product'], 'The seller, shop and category must be eligible before reinstatement.', $this->actions($product));
            }
            $priorId = $product->moderationDecisions()->orderByDesc('id')->value('id');
            $product->forceFill(['compliance_restricted' => $data['action'] === 'remove', 'moderation_version' => $product->moderation_version + 1])->save();

            return ProductModerationDecision::create([
                'product_id' => $product->id, 'actor_id' => $actor->id, 'actor_role' => $actor->role,
                'actor_name' => $actor->name, 'action' => $data['action'], 'reason' => $data['reason'],
                'source_token' => $data['source_token'], 'before_state' => $before,
                'after_state' => $this->state($product), 'prior_decision_id' => $priorId, 'decided_at' => now(),
            ]);
        }, 3);
    }
}
