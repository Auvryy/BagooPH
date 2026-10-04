<?php

namespace Tests\Feature\Auth;

use App\Models\Category;
use App\Models\KycDecision;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\MasterCategoryService;
use App\Services\SellerApplicationService;
use Database\Seeders\MasterCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class SellerCategoryApprovalTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    private function categories(): array
    {
        $this->seed(MasterCategorySeeder::class);

        return Category::whereNull('parent_id')->orderBy('id')->get()->all();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Bagoo Applicant', 'shop_name' => 'Bagoo Application Shop',
            'email' => 'category-applicant@bagoo.test', 'role' => 'seller',
            'birthday' => '2000-01-01', 'phone' => '+639171234567',
            'address' => 'Bagoo Test Street', 'city' => 'Makati',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234',
            'id_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ], $overrides);
    }

    private function applicant(?Category $category, string $kyc = 'pending_approval', string $status = 'pending_approval'): User
    {
        $user = User::factory()->create([
            'role' => 'seller', 'birthday' => '2000-01-01', 'kyc_status' => $kyc,
            'status' => $status, 'kyc_submitted_at' => now(),
        ]);
        Shop::factory()->create(['user_id' => $user->id, 'root_category_id' => $category?->id, 'status' => 'pending']);
        $this->addKycEvidence($user);

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'kyc_status' => 'none']);
    }

    public function test_master_installation_matches_the_document_and_is_idempotent(): void
    {
        $names = [];
        preg_match_all('/\| \*\*\d+\*\* \| \*\*(.*?)\*\* \|/', file_get_contents(base_path('docs/CATEGORIES.md')), $matches);
        $names = $matches[1];
        $this->assertCount(14, $names);
        $this->categories();
        $original = Category::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->seed(MasterCategorySeeder::class);
        $this->assertSame($original, Category::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($names, array_column(app(MasterCategoryService::class)->choices(), 'name'));
        $this->assertDatabaseCount('categories', 14);
    }

    public function test_installation_preserves_legacy_links_inactive_masters_and_slug_collisions(): void
    {
        $legacy = Category::factory()->create(['name' => 'Legacy Pets', 'slug' => 'pet-supplies', 'image' => '/images/legacy-pets.jpg']);
        $child = Category::factory()->create(['parent_id' => $legacy->id]);
        $inactive = Category::factory()->create(['name' => 'Books and Media', 'slug' => 'legacy-books', 'is_active' => false]);
        $shop = Shop::factory()->create(['root_category_id' => $legacy->id]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $child->id]);
        $original = [$legacy->fresh()->getRawOriginal(), $child->fresh()->getRawOriginal(), $inactive->fresh()->getRawOriginal(), $shop->fresh()->getRawOriginal(), $product->fresh()->getRawOriginal()];
        $this->categories();
        $this->seed(MasterCategorySeeder::class);
        $this->assertSame($original, [$legacy->fresh()->getRawOriginal(), $child->fresh()->getRawOriginal(), $inactive->fresh()->getRawOriginal(), $shop->fresh()->getRawOriginal(), $product->fresh()->getRawOriginal()]);
        $master = Category::where('name', 'Pet Supplies')->sole();
        $this->assertNotSame($legacy->id, $master->id);
        $this->assertSame('pet-supplies-master', $master->slug);
        $this->assertCount(13, app(MasterCategoryService::class)->choices());
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_ambiguous_existing_roots_are_preserved_and_not_offered(): void
    {
        $first = Category::factory()->create(['name' => 'Pet Supplies']);
        $second = Category::factory()->create(['name' => 'Pet Supplies']);
        $this->categories();
        $this->seed(MasterCategorySeeder::class);
        $this->assertSame(2, Category::where('name', 'Pet Supplies')->whereNull('parent_id')->count());
        $this->assertFalse(app(MasterCategoryService::class)->snapshot($first->id)['eligible']);
        $this->assertFalse(app(MasterCategoryService::class)->snapshot($second->id)['eligible']);
        $this->assertCount(13, app(MasterCategoryService::class)->choices());
    }

    public static function registrationUrls(): array
    {
        return [['http://localhost/register', 'http://localhost/seller/register'], ['http://seller.localhost/register', 'http://seller.localhost/register']];
    }

    #[DataProvider('registrationUrls')]
    public function test_registration_persists_the_category_and_returns_server_selected_choices(string $postUrl, string $getUrl): void
    {
        Storage::fake('local');
        [$category] = $this->categories();
        $this->get($getUrl)->assertInertia(fn (Assert $page) => $page->component('Auth/SellerRegister')->has('masterCategories', 14)->where('masterCategories.0.id', $category->id));
        $response = $this->post($postUrl, $this->registration(['root_category_id' => $category->id, 'status' => 'active', 'kyc_status' => 'approved']));
        $response->assertRedirect()->assertSessionHasNoErrors();
        $user = User::where('email', 'category-applicant@bagoo.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('pending_approval', $user->status);
        $this->assertSame('pending_approval', $user->kyc_status);
        $this->assertSame($category->id, $user->shop->root_category_id);
        $this->assertSame('pending', $user->shop->status);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('shops', 1);
    }

    public static function invalidCategories(): array
    {
        return [['missing'], ['child'], ['inactive'], ['legacy'], ['nonexistent'], ['ambiguous'], ['decimal'], ['array']];
    }

    private function invalidCategory(string $kind, Category $master): mixed
    {
        return match ($kind) {
            'missing' => null,
            'child' => Category::factory()->create(['parent_id' => $master->id])->id,
            'inactive' => tap($master)->update(['is_active' => false])->id,
            'legacy' => Category::factory()->create(['name' => 'Legacy Collection'])->id,
            'nonexistent' => 999999,
            'ambiguous' => tap($master, fn () => Category::factory()->create(['name' => $master->name]))->id,
            'decimal' => '1.5',
            'array' => [$master->id],
        };
    }

    #[DataProvider('invalidCategories')]
    public function test_invalid_registration_category_does_not_create_records_or_files(string $kind): void
    {
        Storage::fake('local');
        [$master] = $this->categories();
        $value = $this->invalidCategory($kind, $master);
        $this->post('/register', $this->registration(['root_category_id' => $value]))->assertSessionHasErrors('root_category_id');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_category_is_rechecked_before_registration_and_new_uploads_are_cleaned(): void
    {
        Storage::fake('local');
        [$category] = $this->categories();
        app()->instance(SellerApplicationService::class, new class(app(MasterCategoryService::class)) extends SellerApplicationService
        {
            public function register(array $account, string $shopName, int $categoryId): User
            {
                Category::whereKey($categoryId)->update(['is_active' => false]);

                return parent::register($account, $shopName, $categoryId);
            }
        });
        $this->post('/register', $this->registration(['root_category_id' => $category->id]))->assertSessionHasErrors('root_category_id');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_original_shop_failure_rolls_back_the_account_and_new_uploads(): void
    {
        Storage::fake('local');
        [$category] = $this->categories();
        Event::listen('eloquent.created: '.Shop::class, fn () => throw new RuntimeException('Shop write failed'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/register', $this->registration(['root_category_id' => $category->id]));
            $this->fail('Expected the shop write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shop write failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertGuest();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function correctionStates(): array
    {
        return [['pending_approval', 'pending_approval'], ['rejected', 'pending_approval'], ['pending_approval', 'suspended'], ['rejected', 'inactive']];
    }

    #[DataProvider('correctionStates')]
    public function test_owned_category_corrections_preserve_restrictions_documents_and_other_shops(string $kyc, string $status): void
    {
        [$old, $new] = $this->categories();
        $user = $this->applicant($old, $kyc, $status);
        $original = $user->shop;
        $original->update(['status' => 'suspended']);
        $extra = Shop::factory()->create(['user_id' => $user->id, 'root_category_id' => $old->id, 'status' => 'pending']);
        $files = [$user->id_document_path, $user->business_permit_path];
        $token = $this->kycPayload($user)['review_token'];
        $this->travel(1)->seconds();
        $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $new->id])->assertSessionHas('success');
        $this->assertSame($new->id, $original->fresh()->root_category_id);
        $this->assertSame('suspended', $original->fresh()->status);
        $this->assertSame($status, $user->fresh()->status);
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertSame('seller', $user->fresh()->role);
        $this->assertSame($old->id, $extra->fresh()->root_category_id);
        $this->assertSame($files, [$user->fresh()->id_document_path, $user->fresh()->business_permit_path]);
        Storage::disk('local')->assertExists($files);
        $this->assertNotSame($token, $this->kycPayload($user->fresh())['review_token']);
    }

    public function test_category_correction_invalidates_old_review_and_requires_new_document_inspection(): void
    {
        [$old, $new] = $this->categories();
        $user = $this->applicant($old);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $oldPayload = $this->kycPayload($user);
        $this->travel(1)->seconds();
        $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $new->id])->assertSessionHas('success');
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/approve', $oldPayload)->assertConflict();
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user->fresh()))->assertSessionHasErrors('review');
        $this->inspectKycEvidence($admin, $user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user->fresh()))->assertSessionHas('success');
        $this->assertSame($new->id, KycDecision::sole()->submission['shop']['root_category_id']);
        $this->assertSame($new->name, KycDecision::sole()->submission['shop_category']['name']);
    }

    public static function reviewedStates(): array
    {
        return [['approved'], ['verified']];
    }

    #[DataProvider('reviewedStates')]
    public function test_reviewed_categories_cannot_be_replaced_by_applicant_resubmission(string $kyc): void
    {
        [$old, $new] = $this->categories();
        $user = $this->applicant($old, $kyc, 'inactive');
        $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $new->id])->assertConflict();
        $this->assertSame($old->id, $user->shop->fresh()->root_category_id);
        $this->assertSame($kyc, $user->fresh()->kyc_status);
        $this->assertSame('inactive', $user->fresh()->status);
        $this->get('/pending-approval')->assertInertia(fn (Assert $page) => $page->where('sellerCategory.can_correct', false));
    }

    public function test_foreign_shop_target_and_non_seller_category_corrections_are_rejected(): void
    {
        [$old, $new] = $this->categories();
        $owner = $this->applicant($old);
        $foreign = $this->applicant($old);
        $this->actingAs($owner)->post('/kyc/resubmit', ['root_category_id' => $new->id, 'shop_id' => $foreign->shop->id])->assertSessionHasErrors('shop_id');
        $this->assertSame($old->id, $owner->shop->fresh()->root_category_id);
        $this->assertSame($old->id, $foreign->shop->fresh()->root_category_id);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $this->actingAs($buyer)->post('/kyc/resubmit', ['root_category_id' => $new->id])->assertSessionHasErrors('root_category_id');
    }

    public function test_missing_original_shop_and_unchanged_corrections_are_not_guessed(): void
    {
        [$category] = $this->categories();
        $user = $this->applicant($category);
        $before = $user->getRawOriginal();
        $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $category->id])->assertSessionHasErrors('root_category_id');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $user->shop->delete();
        $this->post('/kyc/resubmit', ['root_category_id' => $category->id])->assertSessionHasErrors('root_category_id');
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_category_correction_failure_preserves_old_files_and_scope(): void
    {
        [$old, $new] = $this->categories();
        $user = $this->applicant($old, 'rejected');
        $before = $user->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        Event::listen('eloquent.updating: '.User::class, fn () => throw new RuntimeException('Application write failed'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $new->id, 'business_permit' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')]);
            $this->fail('Expected application rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Application write failed', $exception->getMessage());
        }
        $this->assertSame($old->id, $user->shop->fresh()->root_category_id);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public static function categoryChanges(): array
    {
        return [['deactivate'], ['rename'], ['reparent'], ['duplicate']];
    }

    #[DataProvider('categoryChanges')]
    public function test_taxonomy_changes_invalidate_pending_review_and_block_fresh_approval(string $change): void
    {
        [$category, $parent] = $this->categories();
        $user = $this->applicant($category);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        match ($change) {
            'deactivate' => $category->update(['is_active' => false]),
            'rename' => $category->update(['name' => 'Legacy Pets']),
            'reparent' => $category->update(['parent_id' => $parent->id]),
            'duplicate' => Category::factory()->create(['name' => $category->name]),
        };
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertConflict();
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user->fresh()))->assertSessionHasErrors('review');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertSame('pending', $user->shop->fresh()->status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_completed_retry_after_category_deactivation_preserves_the_original_decision_and_restrictions(): void
    {
        [$category] = $this->categories();
        $user = $this->applicant($category);
        $admin = $this->admin();
        $this->inspectKycEvidence($admin, $user);
        $payload = $this->kycPayload($user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $record = KycDecision::sole()->getRawOriginal();
        $user->update(['status' => 'suspended']);
        $user->shop->update(['status' => 'suspended']);
        $category->update(['is_active' => false]);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHas('success');
        $this->assertSame($record, KycDecision::sole()->getRawOriginal());
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame('suspended', $user->shop->fresh()->status);
        $this->actingAs($this->admin())->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertConflict();
        $this->assertDatabaseCount('kyc_decisions', 1);
    }

    public function test_rejection_correction_preserves_the_original_decision_and_evidence(): void
    {
        [$old, $new] = $this->categories();
        $user = $this->applicant($old);
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/kyc/'.$user->id.'/reject', $this->kycPayload($user) + ['reason' => 'Please correct the shop category.'])->assertSessionHas('success');
        $original = KycDecision::sole()->getRawOriginal();
        $this->actingAs($user)->post('/kyc/resubmit', ['root_category_id' => $new->id])->assertSessionHas('success');
        $this->assertSame($original, KycDecision::sole()->getRawOriginal());
        $this->inspectKycEvidence($admin, $user->fresh());
        $this->post('/admin/kyc/'.$user->id.'/approve', $this->kycPayload($user->fresh()))->assertSessionHas('success');
        $this->assertDatabaseCount('kyc_decisions', 2);
        Storage::disk('local')->assertExists([$user->id_document_path, $user->business_permit_path]);
    }

    public function test_holding_and_admin_review_report_legacy_scope_without_assigning_it(): void
    {
        $this->categories();
        $user = $this->applicant(null);
        $this->actingAs($user)->get('/pending-approval')->assertInertia(fn (Assert $page) => $page->where('sellerCategory.value', null)->where('sellerCategory.can_correct', true)->has('sellerCategory.choices', 14)->where('sellerCategory.issue', MasterCategoryService::ISSUE));
        $this->actingAs($this->admin())->get('/admin/kyc')->assertInertia(fn (Assert $page) => $page->where('applicants.data.0.shop_category', null)->where('applicants.data.0.review_issues', [MasterCategoryService::ISSUE]));
        $this->assertNull($user->shop->fresh()->root_category_id);
    }

    public function test_registration_requires_a_master_category_without_partial_accounts(): void
    {
        Storage::fake('local');
        $this->post('/register', [
            'name' => 'Bagoo Applicant', 'shop_name' => 'Bagoo Application Shop',
            'email' => 'category-applicant@bagoo.test', 'role' => 'seller',
            'birthday' => '2000-01-01', 'phone' => '+639171234567',
            'address' => 'Bagoo Test Street', 'city' => 'Makati',
            'password' => 'Password1234', 'password_confirmation' => 'Password1234',
            'id_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('root_category_id');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_shop_category_blocks_a_new_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->pendingKyc()->create(['role' => 'seller', 'birthday' => '2000-01-01']);
        $shop = Shop::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'root_category_id' => null]);
        $payload = $this->prepareKycReview($admin, $user);
        $this->post('/admin/kyc/'.$user->id.'/approve', $payload)->assertSessionHasErrors('review');
        $this->assertSame('pending_approval', $user->fresh()->kyc_status);
        $this->assertSame('pending', $shop->fresh()->status);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }
}
