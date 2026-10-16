<?php

namespace Tests\Feature\Buyer;

use App\Models\Message;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CataloguePrefetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->withHeaders([
            'X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion() ?? '',
            'Purpose' => 'prefetch', 'X-Bagoo-Catalogue' => '1',
        ]);
    }

    public function test_reviewed_browsing_routes_prepare_without_creating_bags_orders_or_changing_stock(): void
    {
        $shop = Shop::factory()->approved()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock' => 5]);
        foreach (['/' => 'Buyer/Home', '/buyer' => 'Buyer/Home', '/buyer/search' => 'Buyer/Search', '/catalog' => 'Buyer/Search', '/buyer/product/'.$product->slug => 'Buyer/ProductDetail'] as $path => $component) {
            $this->get($path)->assertOk()->assertJsonPath('component', $component)
                ->assertJsonPath('props.cataloguePrefetchEnabled', true)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_background_requests_preserve_flash_and_validation_feedback_for_the_next_real_visit(): void
    {
        $this->withSession(['success' => 'Your changes were saved.', 'errors' => new ViewErrorBag,
            '_flash' => ['old' => ['success', 'errors'], 'new' => []]])
            ->get('/buyer/search')->assertStatus(409)->assertSessionHas('success', 'Your changes were saved.')
            ->assertSessionHas('errors');
        $this->get('/buyer/search')->assertStatus(409)->assertSessionHas('success', 'Your changes were saved.');
        $this->withHeader('Purpose', '')->get('/buyer/search')->assertOk()
            ->assertJsonPath('props.flash.success', 'Your changes were saved.');
        $this->get('/buyer/search')->assertOk()->assertJsonPath('props.flash.success', null);
    }

    public function test_unreviewed_message_reads_are_rejected_before_acknowledging_incoming_messages(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $seller = User::factory()->create(['role' => 'seller']);
        $message = Message::create(['sender_id' => $seller->id, 'receiver_id' => $buyer->id, 'message' => 'Your order is ready.', 'is_read' => false]);
        $this->actingAs($buyer)->get('/chat/messages/'.$seller->id)->assertStatus(409);
        $this->assertFalse($message->fresh()->is_read);
    }

    public function test_prefetch_marker_cannot_submit_a_bag_mutation_and_disabled_prefetch_keeps_normal_browsing(): void
    {
        $this->post('/cart', ['product_id' => 1, 'quantity' => 1])->assertStatus(409);
        $this->assertDatabaseCount('carts', 0);
        config(['catalogue.prefetch_enabled' => false]);
        $this->get('/buyer/search')->assertStatus(409);
        $this->withHeader('Purpose', '')->get('/buyer/search')->assertOk()->assertJsonPath('props.cataloguePrefetchEnabled', false);
    }

    public static function workerRoles(): array
    {
        return array_map(fn ($role) => [$role], ['seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('workerRoles')]
    public function test_other_portal_accounts_cannot_opt_into_buyer_prefetch(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($user)->get('/buyer/search')->assertStatus(409);
    }

}
