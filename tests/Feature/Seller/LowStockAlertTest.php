<?php

namespace Tests\Feature\Seller;

use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Commerce\InventoryService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->shop = Shop::factory()->approved()->create();
        $this->product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'stock' => 10]);
        $this->actingAs($this->shop->user);
    }

    public function test_manual_stock_crossing_records_one_owned_alert_until_replenished(): void
    {
        $url = '/seller/products/'.$this->product->id.'/stock';
        $this->patch($url, ['mode' => 'set', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->patch($url, ['mode' => 'set', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->patch($url, ['mode' => 'set', 'quantity' => 3])->assertSessionHasNoErrors();
        $this->patch($url, ['mode' => 'set', 'quantity' => 0])->assertSessionHasNoErrors();
        $notice = NotificationDelivery::where('type', 'inventory-event')->sole();
        $this->assertSame($this->shop->user_id, $notice->recipient_id);
        $this->assertSame('inventory-event', $notice->type);
        $this->assertSame('inventory:'.$this->product->id.':1', $notice->source_key);
        $this->assertSame('seller-products', $notice->data['target']);
        app(NotificationDeliveryService::class)->deliver($notice->id);
        $unread = app(NotificationCenterService::class)->summary($this->shop->user)['unread'];
        $this->assertGreaterThanOrEqual(1, $unread);
        app(NotificationDeliveryService::class)->deliver($notice->id);
        $this->assertSame($unread, app(NotificationCenterService::class)->summary($this->shop->user)['unread']);
        $databaseNotice = $this->shop->user->notifications()->where('type', 'inventory-event')->sole();
        $this->assertSame('/seller/products', app(NotificationCenterService::class)->present($databaseNotice, $this->shop->user)['href']);
        $this->patch($url, ['mode' => 'add', 'quantity' => 9])->assertSessionHasNoErrors();
        $this->assertFalse($this->product->fresh()->low_stock_active);
        $this->patch($url, ['mode' => 'set', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->assertSame(2, NotificationDelivery::where('type', 'inventory-event')->count());
        $this->assertDatabaseHas('notification_deliveries', ['source_key' => 'inventory:'.$this->product->id.':2']);
    }

    public function test_checkout_inventory_and_restoration_share_the_same_crossing_and_reset(): void
    {
        $inventory = app(InventoryService::class);
        DB::transaction(fn () => $inventory->decrement($this->product, 5, null, null));
        $this->assertSame(1, NotificationDelivery::where('type', 'inventory-event')->count());
        DB::transaction(fn () => $inventory->decrement($this->product, 1, null, null));
        $this->assertSame(1, NotificationDelivery::where('type', 'inventory-event')->count());
        DB::transaction(fn () => $inventory->restore($this->product, 6, null));
        $this->assertFalse($this->product->fresh()->low_stock_active);
        DB::transaction(fn () => $inventory->decrement($this->product, 5, null, null));
        $this->assertSame(2, NotificationDelivery::where('type', 'inventory-event')->count());
        $this->assertSame(2, $this->product->fresh()->low_stock_cycle);
    }

    public function test_failed_stock_transaction_rolls_back_the_alert_and_its_crossing_identity(): void
    {
        try {
            DB::transaction(function () {
                app(InventoryService::class)->decrement($this->product, 5, null, null);
                $this->assertSame(1, NotificationDelivery::where('type', 'inventory-event')->count());
                throw new RuntimeException('Controlled transaction failure.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled transaction failure.', $exception->getMessage());
        }
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(0, $this->product->fresh()->low_stock_cycle);
        $this->assertSame(0, NotificationDelivery::where('type', 'inventory-event')->count());
        $this->product->fresh()->update(['stock' => 5]);
        $this->assertSame(1, NotificationDelivery::where('type', 'inventory-event')->count());
        $this->assertSame('inventory:'.$this->product->id.':1', NotificationDelivery::where('type', 'inventory-event')->sole()->source_key);
    }

    public function test_new_low_stock_drafts_and_archived_stock_edits_do_not_emit_operational_alerts(): void
    {
        Product::factory()->create(['shop_id' => $this->shop->id, 'stock' => 2]);
        $this->product->update(['status' => 'draft', 'stock' => 2]);
        $this->product->update(['status' => 'archived', 'stock' => 10]);
        $this->product->update(['stock' => 1]);
        $this->assertSame(0, NotificationDelivery::where('type', 'inventory-event')->count());
    }
}
