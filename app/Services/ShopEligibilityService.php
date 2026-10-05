<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShopEligibilityService
{
    public const DETAILS = ['name', 'phone', 'address', 'city', 'root_category_id'];

    public function eligibleShops(Builder $query): Builder
    {
        return $this->reviewedShops($query->where('shops.status', 'active')
            ->whereHas('user', fn (Builder $owner) => $owner->where('role', 'seller')->where('status', 'active')
                ->whereIn('kyc_status', User::APPROVED_KYC_STATUSES)
                ->where(fn ($age) => $age->where(fn ($legacy) => $legacy->whereNull('birthday')->where('identity_version', 0))->orWhere(fn ($date) => $date
                    ->whereDate('birthday', '>=', '0001-01-01')->whereDate('birthday', '<=', app(BirthDateEligibility::class)->limits()['adult_maximum'])))));
    }

    public function reviewedShops(Builder $query): Builder
    {
        // Reviewed identity/category scope is distinct from account and shop activity.
        return $query->where('shops.review_status', 'approved')
            ->whereIn('shops.root_category_id', app(MasterCategoryService::class)->activeRoots()->select('categories.id'))
            ->whereHas('currentReview', function (Builder $review) {
                $review->where('decision', 'approved')->where('reviewer_role', 'admin')
                    ->whereColumn('shop_review_decisions.shop_id', 'shops.id')
                    ->whereColumn('shop_review_decisions.seller_id', 'shops.user_id')
                    ->whereColumn('shop_review_decisions.root_category_id', 'shops.root_category_id');
                foreach (['name', 'phone', 'address', 'city'] as $field) {
                    $review->whereColumn('shop_review_decisions.submission->shop->'.$field, 'shops.'.$field);
                }
            });
    }

    public function isEligible(Shop $shop): bool
    {
        return Shop::eligible()->whereKey($shop->id)->exists();
    }

    public function assertEligible(Shop $shop): void
    {
        abort_unless($this->isEligible($shop), 403, 'This shop needs current approval and an active seller account before accepting new work.');
    }

    public function context(Request $request, bool $lock = false, bool $history = false): Shop
    {
        $user = User::whereKey($request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($user->isSeller() && $user->canAccessPortal(), 403);
        $id = $history && $request->has('shop_id') ? $request->input('shop_id') : $request->session()->get('active_seller_shop_id');
        $query = Shop::where('user_id', $user->id)->with('rootCategory');
        if ($id !== null) {
            abort_unless(is_scalar($id) && preg_match('/\A[1-9][0-9]*\z/', (string) $id), 403);
            $shop = $query->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
            abort_unless($shop, 403, 'The selected shop is no longer available to your account. Choose a shop on the Shops page.');
        } else {
            $shop = $query->when(! $history, fn ($q) => $q->eligible())->orderByDesc('is_default')->orderBy('id')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if (! $shop) {
                throw new HttpResponseException(redirect()->route('seller.shops.index')->with('info', 'Submit or select an approved shop to continue.'));
            }
        }
        if (! $history) {
            if ($lock) {
                $this->lockCategories();
            }
            if (! $this->isEligible($shop)) {
                throw new HttpResponseException(redirect()->route('seller.shops.index')->with('info', 'This shop cannot accept new work. Review its approval and activity on the Shops page.'));
            }
            $request->session()->put('active_seller_shop_id', $shop->id);
        }

        return $shop;
    }

    public function mutate(Request $request, Closure $work): mixed
    {
        // Keep the write inside this callback. A routing pipeline can render an exception before middleware sees it.
        return DB::transaction(function () use ($request, $work) {
            $request->attributes->set('locked_seller_shop', $this->context($request, lock: true));
            try {
                return $work();
            } finally {
                $request->attributes->remove('locked_seller_shop');
            }
        }, 3);
    }

    public function lockCategories(): void
    {
        // Reviews and new work lock User -> Shop -> Category -> Product, each in ID order.
        Category::orderBy('id')->lockForUpdate()->get();
    }

    public function categoryIds(Shop $shop): array
    {
        return $this->categoryScopes()[(int) $shop->root_category_id] ?? [];
    }

    private function categoryScopes(): array
    {
        $categories = Category::where('is_active', true)->get(['id', 'parent_id']);
        $scopes = [];
        foreach (app(MasterCategoryService::class)->activeRoots()->pluck('id') as $rootId) {
            $scopes[$rootId] = $this->descendants($rootId, $categories);
        }

        return $scopes;
    }

    private function descendants(int $rootId, Collection $categories): array
    {
        $ids = [$rootId];
        do {
            $before = count($ids);
            foreach ($categories as $category) {
                if ($category->parent_id && in_array((int) $category->parent_id, $ids, true) && ! in_array($category->id, $ids, true)) {
                    $ids[] = $category->id;
                }
            }
        } while (count($ids) !== $before);

        return $ids;
    }

    public function categoryFor(Shop $shop, mixed $categoryId): int
    {
        $id = (int) ($categoryId ?? $shop->root_category_id);
        if (! in_array($id, $this->categoryIds($shop), true)) {
            throw ValidationException::withMessages(['category_id' => 'Choose an active category within this shop\'s approved master category.']);
        }

        return $id;
    }

    public function productIsEligible(Product $product): bool
    {
        $shop = Shop::find($product->shop_id);

        return $product->status === 'active' && $shop && $this->isEligible($shop)
            && in_array((int) $product->category_id, $this->categoryIds($shop), true);
    }

    public function lockSaleProducts(array $ids, array $additionalUserIds = []): Collection
    {
        $shopIds = Product::whereIn('id', $ids)->pluck('shop_id')->filter()->unique();
        $ownerIds = Shop::whereIn('id', $shopIds)->pluck('user_id')->merge($additionalUserIds)->unique();
        User::whereIn('id', $ownerIds)->orderBy('id')->lockForUpdate()->get();
        $shops = Shop::whereIn('id', $shopIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->lockCategories();
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($products as $product) {
            $shop = $shops->get($product->shop_id);
            if (! $shop || ! $ownerIds->contains($shop->user_id) || ! $this->productIsEligible($product)) {
                throw new RuntimeException('One or more selected products or shops are unavailable.');
            }
            $product->setRelation('shop', $shop);
        }

        return $products;
    }

    public function availableProducts(Builder $query): Builder
    {
        $scopes = $this->categoryScopes();

        return $query->where('products.status', 'active')->whereHas('shop', fn ($q) => $q->eligible())
            ->where(function ($queryScopes) use ($scopes) {
                $queryScopes->whereRaw('1 = 0');
                foreach ($scopes as $rootId => $categoryIds) {
                    $queryScopes->orWhere(fn ($scope) => $scope->whereIn('products.category_id', $categoryIds)
                        ->whereHas('shop', fn ($q) => $q->where('root_category_id', $rootId)));
                }
            });
    }

    public function protectReviewedDetails(Shop $shop, array $values): void
    {
        foreach (self::DETAILS as $field) {
            if (array_key_exists($field, $values) && (string) $values[$field] !== (string) $shop->$field) {
                throw ValidationException::withMessages([$field => 'Reviewed shop details require a separate correction review. Branding cannot change them.']);
            }
        }
    }
}
