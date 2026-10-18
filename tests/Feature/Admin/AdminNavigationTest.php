<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\ProductModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '']);
    }

    public static function portals(): array
    {
        return [['/admin'], ['http://admin.localhost']];
    }

    #[DataProvider('portals')]
    public function test_directory_paging_keeps_filters_and_tied_rows_stable_without_private_account_payloads(string $base): void
    {
        $admin = User::factory()->admin()->create();
        $buyers = User::factory()->buyer()->count(32)->create(['name' => 'Maria Directory', 'created_at' => now()]);
        User::factory()->seller()->create(['name' => 'Maria Directory']);
        $this->actingAs($admin);
        $first = $this->get($base.'/users?search=Maria&role=buyer&page=1')->assertOk();
        $second = $this->get($base.'/users?search=Maria&role=buyer&page=2')->assertOk();
        $this->assertSame(32, $second->json('props.users.total'));
        $this->assertSame(['search' => 'Maria', 'role' => 'buyer'], $second->json('props.filters'));
        $expected = $buyers->pluck('id')->reverse()->values();
        $this->assertSame($expected->slice(0, 15)->all(), array_column($first->json('props.users.data'), 'id'));
        $this->assertSame($expected->slice(15, 15)->values()->all(), array_column($second->json('props.users.data'), 'id'));
        foreach ($second->json('props.users.data') as $user) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role', 'phone', 'city', 'status', 'kyc_status'], array_keys($user));
        }
        parse_str(parse_url($second->json('props.users.prev_page_url'), PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Maria', 'role' => 'buyer', 'page' => '1'], $query);
        $last = $this->get($base.'/users?search=Maria&role=buyer&page=1000000')->assertOk();
        $this->assertSame(3, $last->json('props.users.current_page'));
        $this->assertSame($expected->slice(30)->values()->all(), array_column($last->json('props.users.data'), 'id'));
        $this->get($base.'/users?search=NoMatchingPerson')->assertOk()->assertJsonPath('props.users.total', 0)
            ->assertJsonPath('props.users.current_page', 1);
    }

    #[DataProvider('portals')]
    public function test_focused_context_reads_skip_directory_work_and_selected_rows_remain_current(string $base): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create(['name' => 'Directory Buyer']);
        $this->actingAs($admin);
        DB::enableQueryLog();
        $response = $this->get($base.'/users?search=Directory', [
            'X-Inertia-Partial-Component' => 'Admin/Users', 'X-Inertia-Partial-Data' => 'auth,filters,activityBaseUrl,flash,errors',
        ])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertArrayNotHasKey('users', $response->json('props'));
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, '"name" like') || str_contains($sql, 'from "shops"')));
        $buyer->update(['status' => 'suspended']);
        $this->get($base.'/users?search=Directory', ['X-Inertia-Partial-Data' => 'auth,users,filters,activityBaseUrl,flash,errors'])
            ->assertOk()->assertJsonPath('props.users.data.0.id', $buyer->id)->assertJsonPath('props.users.data.0.status', 'suspended');
    }

    #[DataProvider('portals')]
    public function test_product_directory_skips_unrequested_work_and_never_uses_an_old_moderation_decision(string $base): void
    {
        $admin = User::factory()->admin()->create();
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($admin);
        DB::enableQueryLog();
        $response = $this->get($base.'/products', [
            'X-Inertia-Partial-Component' => 'Admin/Products', 'X-Inertia-Partial-Data' => 'auth,moderationBaseUrl',
        ])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertArrayNotHasKey('products', $response->json('props'));
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'from "products"') || str_contains($sql, 'from "shops"')));
        $this->flushHeaders()->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '']);
        $first = $this->get($base.'/products')->assertOk()->assertJsonPath('props.products.data.0.compliance_restricted', false);
        $this->assertSame(['id', 'name'], array_keys($first->json('props.products.data.0.shop')));
        $this->assertArrayNotHasKey('description', $first->json('props.products.data.0'));
        $service = app(ProductModerationService::class);
        $stale = ['action' => 'remove', 'reason' => 'Review the listing description.',
            'source_token' => app(AccountRestrictionService::class)->token($service->state($product->fresh()))];
        $reviewer = User::factory()->admin()->create();
        $service->decide($reviewer, $product, $stale);
        $this->get($base.'/products')->assertOk()->assertJsonPath('props.products.data.0.compliance_restricted', true);
        $this->postJson($base.'/products/'.$product->id.'/moderation', $stale)->assertConflict();
        $this->assertDatabaseCount('product_moderation_decisions', 1);
    }

    #[DataProvider('portals')]
    public function test_access_is_checked_again_after_revocation_sign_out_or_another_account(string $base): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get($base.'/users')->assertOk();
        $admin->update(['status' => 'suspended']);
        $this->getJson($base.'/users')->assertForbidden();
        $this->getJson($base.'/products')->assertUnauthorized();
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($base.'/users')->assertForbidden();
            $this->getJson($base.'/products')->assertForbidden();
        }
        $this->actingAs(User::factory()->admin()->create())->getJson($base.'/users')->assertOk();
    }

    #[DataProvider('portals')]
    public function test_speculation_is_rejected_without_consuming_feedback_and_disable_retains_fresh_navigation(string $base): void
    {
        $this->actingAs(User::factory()->admin()->create());
        session()->flash('success', 'The review was saved.');
        foreach (['dashboard', 'users', 'products', 'kyc', 'shops', 'advertisements', 'cod'] as $path) {
            $this->get($base.'/'.$path, ['Purpose' => 'prefetch', 'X-Bagoo-Catalogue' => '1'])->assertStatus(409);
        }
        $this->assertDatabaseCount('kyc_decisions', 0);
        $this->assertDatabaseCount('product_moderation_decisions', 0);
        $this->get($base.'/users')->assertOk()->assertJsonPath('props.flash.success', 'The review was saved.');
        config(['catalogue.prefetch_enabled' => false]);
        $this->get($base.'/products')->assertOk()->assertJsonPath('props.cataloguePrefetchEnabled', false);
    }

    #[DataProvider('portals')]
    public function test_invalid_search_role_and_page_boundaries_are_actionable_and_leave_valid_navigation_available(string $base): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['0', '-1', '1.5', '01', '1e2', '1000001', '١', ['1']] as $page) {
            foreach (['users', 'products'] as $list) {
                $this->getJson($base.'/'.$list.'?'.http_build_query(['page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
            }
        }
        foreach ([['search' => str_repeat('a', 101)], ['search' => ['bad']], ['role' => 'owner'], ['role' => ['buyer']]] as $input) {
            $this->getJson($base.'/users?'.http_build_query($input))->assertUnprocessable()->assertJsonValidationErrors(array_keys($input));
        }
        $this->get($base.'/users?'.http_build_query(['search' => '  '.str_repeat('a', 100).'  ', 'role' => 'buyer']))
            ->assertOk()->assertJsonPath('props.filters.search', str_repeat('a', 100));
        $this->get($base.'/users?page=1')->assertOk();
    }
}
