<?php

namespace Tests\Feature\Seller;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerListingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Exercise this migration outside a wrapping test transaction. Do not roll back unrelated migrations.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        parent::tearDown();
    }

    public function test_details_migration_round_trip_keeps_original_data_and_database_retention_guards(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $product = Product::factory()->create(['weight_kg' => '0.80']);
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id]);
        $guards = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', ['products', 'order_items'])->orderBy('name')->pluck('sql', 'name')->all();
        $this->assertNotEmpty($guards);
        $migration = require database_path('migrations/2026_10_20_020000_add_seller_listing_details.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'brand'));
        $this->assertSame($guards, DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', ['products', 'order_items'])->orderBy('name')->pluck('sql', 'name')->all());
        $migration->up();
        $this->assertTrue(Schema::hasColumn('products', 'brand'));
        $this->assertSame($guards, DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', ['products', 'order_items'])->orderBy('name')->pluck('sql', 'name')->all());
        $this->assertEquals(0.80, DB::table('products')->where('id', $product->id)->value('weight_kg'));
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'product_id' => $product->id]);
        try {
            DB::table('products')->where('id', $product->id)->delete();
            $this->fail('Purchased product history cannot be deleted after schema changes.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'product_id' => $product->id]);
    }

    public function test_schema_changes_refuse_an_unsafe_populated_sqlite_transaction_without_removing_data(): void
    {
        $migration = require database_path('migrations/2026_10_20_020000_add_seller_listing_details.php');
        DB::beginTransaction();
        try {
            $product = Product::factory()->create();
            $item = OrderItem::factory()->create(['product_id' => $product->id, 'shop_id' => $product->shop_id]);
            foreach (['down', 'up'] as $direction) {
                try {
                    $migration->$direction();
                    $this->fail('Unsafe nested SQLite schema changes must stop before changing data.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('outside an existing SQLite transaction', $exception->getMessage());
                }
            }
            $this->assertTrue(Schema::hasColumn('products', 'brand'));
            $this->assertDatabaseHas('products', ['id' => $product->id]);
            $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        } finally {
            DB::rollBack();
        }
    }
}
