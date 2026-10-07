<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class CustodyEvidenceTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function pickupPortals(): array
    {
        return ['root' => ['/courier'], 'subdomain' => ['http://courier.localhost']];
    }

    #[DataProvider('pickupPortals')]
    public function test_pickup_requires_the_actual_submitted_waybill(string $prefix): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = $this->snapshot($delivery);
        $this->actingAs(User::findOrFail($delivery->courier_id))->patchJson($prefix.'/deliveries/'.$delivery->id.'/status', ['status' => 'picked_up'])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function invalidWaybills(): array
    {
        return ['wrong parcel' => ['BGO-WRONG'], 'control' => ["\tBGO-123\n"], 'lookalike' => ['ＢＧＯ-123'],
            'hidden' => ["BGO-12\u{200B}3"], 'markup' => ['<b>BGO-123</b>'], 'array' => [['BGO-123']], 'overlong' => [str_repeat('A', 256)]];
    }

    #[DataProvider('invalidWaybills')]
    public function test_invalid_pickup_scan_preserves_parcel_and_history(mixed $barcode): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = $this->snapshot($delivery);
        $this->actingAs(User::findOrFail($delivery->courier_id))->patchJson(route('courier.updateStatus', $delivery), ['status' => 'picked_up', 'barcode' => $barcode])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    #[DataProvider('pickupPortals')]
    public function test_matching_pickup_records_the_actual_scan_and_one_custody_event(string $prefix): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $rider = User::findOrFail($delivery->courier_id);
        $this->actingAs($rider)->patchJson($prefix.'/deliveries/'.$delivery->id.'/status', ['status' => 'picked_up', 'barcode' => '  '.strtolower($delivery->tracking_number).'  '])
            ->assertRedirect()->assertSessionHas('success');
        $record = $delivery->checkpoints()->where('checkpoint_type', 'picked_up')->sole();
        $this->assertNotEmpty($record->record_reference);
        $this->assertSame('courier', $record->actor_role);
        $this->assertSame($delivery->tracking_number, $record->barcode_scanned);
        $this->assertSame('submitted', $record->scan_provenance);
        $this->assertSame('assigned_pickup', $record->source_state['delivery_status']);
        $this->assertSame('picked_up', $record->target_state['delivery_status']);
        $this->assertSame(['kind' => 'courier', 'user_id' => $rider->id], $record->custody_after);
        $alias = $delivery->checkpoints()->where('checkpoint_type', 'courier_pickup')->sole();
        $this->assertSame($record->id, $alias->source_checkpoint_id);
        $before = $this->snapshot($delivery);
        $this->patchJson($prefix.'/deliveries/'.$delivery->id.'/status', ['status' => 'picked_up', 'barcode' => $delivery->tracking_number])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function forbiddenHistoryChanges(): array
    {
        return array_map(fn ($operation) => [$operation], ['model update', 'model delete', 'query update', 'query delete', 'raw update', 'raw delete', 'timestamp', 'parcel delete', 'actor delete', 'hub delete', 'order delete', 'company delete', 'shop delete', 'product delete', 'item delete']);
    }

    #[DataProvider('forbiddenHistoryChanges')]
    public function test_custody_history_and_its_references_are_retained(string $operation): void
    {
        // A retained-history fixture tests persistence protection, not an operational handoff writer.
        $actor = User::factory()->courier()->create();
        $owner = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo History Network', 'slug' => 'history-network', 'code' => 'HISTORY', 'status' => 'active', 'is_active' => true]);
        $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'History Bayan Hub', 'code' => 'HISTORY-BH', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => '100 Hub Road', 'is_active' => true]);
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'product_id' => $product->id]);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $company->id]);
        $record = DeliveryCheckpoint::record($delivery, 'legacy_record', actor: $actor, hub: $hub);
        $before = $record->fresh()->getRawOriginal();
        $error = null;
        try {
            match ($operation) {
                'model update' => $record->update(['notes' => 'Replacement']),
                'model delete' => $record->delete(),
                'query update' => DeliveryCheckpoint::whereKey($record->id)->update(['notes' => 'Replacement']),
                'query delete' => DeliveryCheckpoint::whereKey($record->id)->delete(),
                'raw update' => DB::table('delivery_checkpoints')->where('id', $record->id)->update(['notes' => 'Replacement']),
                'raw delete' => DB::table('delivery_checkpoints')->where('id', $record->id)->delete(),
                'timestamp' => DB::table('delivery_checkpoints')->where('id', $record->id)->update(['created_at' => '2020-01-01 00:00:00']),
                'parcel delete' => DB::table('deliveries')->where('id', $delivery->id)->delete(),
                'actor delete' => DB::table('users')->where('id', $actor->id)->delete(),
                'hub delete' => DB::table('logistics_hubs')->where('id', $hub->id)->delete(),
                'order delete' => DB::table('orders')->where('id', $order->id)->delete(),
                'company delete' => DB::table('logistics_companies')->where('id', $company->id)->delete(),
                'shop delete' => DB::table('shops')->where('id', $shop->id)->delete(),
                'product delete' => DB::table('products')->where('id', $product->id)->delete(),
                'item delete' => DB::table('order_items')->where('id', $item->id)->delete(),
            };
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'Custody history writes and reference deletion must fail.');
        $this->assertStringContainsString('Custody', $error->getMessage());
        $this->assertSame($before, $record->fresh()?->getRawOriginal());
        $this->assertNotNull($delivery->fresh());
        $this->assertNotNull($actor->fresh());
        $this->assertNotNull($hub->fresh());
        $this->assertNotNull($company->fresh());
        $this->assertNotNull($order->fresh());
        $this->assertNotNull($shop->fresh());
        $this->assertNotNull($product->fresh());
        $this->assertNotNull($item->fresh());
    }

    public function test_form_method_override_retains_controls_for_rejection(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = $this->snapshot($delivery);
        $this->actingAs(User::findOrFail($delivery->courier_id))->post(route('courier.updateStatus', $delivery), ['_method' => 'patch', 'status' => 'picked_up', 'barcode' => "\t".$delivery->tracking_number."\n"])
            ->assertSessionHasErrors('barcode');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function hubPortals(): array
    {
        return ['root' => ['/hub'], 'subdomain' => ['http://hub.localhost']];
    }

    #[DataProvider('hubPortals')]
    public function test_origin_scan_keeps_submitted_order_alias_and_custody_evidence(string $prefix): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $pickup = $delivery->courier_id;
        $before = $this->snapshot($delivery);
        $this->actingAs($handler)->postJson($prefix.'/scan', ['barcode' => $order->order_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk();
        $this->assertSame($before, $this->snapshot($delivery));
        $payload = ['barcode' => ' '.strtolower($order->order_number).' ', 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => ' receive_from_pickup_rider ', 'expected_status' => ' PICKED_UP '];
        $this->postJson($prefix.'/scan', $payload)->assertOk()->assertJsonPath('confirmed', true);
        $record = $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_origin_hub')->sole();
        $this->assertSame($order->order_number, $record->barcode_scanned);
        $this->assertSame($handler->id, $record->scanned_by_id);
        $this->assertSame('logistics', $record->actor_role);
        $this->assertSame(['kind' => 'courier', 'user_id' => $pickup], $record->custody_before);
        $this->assertSame(['kind' => 'hub', 'hub_id' => $hub->id], $record->custody_after);
        $this->assertSame('picked_up', $record->source_state['delivery_status']);
        $this->assertSame('arrived_at_origin_hub', $record->target_state['delivery_status']);
        $this->assertSame('at_sorting_center', $record->target_state['order_status']);
        $before = $this->snapshot($delivery);
        $this->postJson($prefix.'/scan', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_direct_pickup_service_cannot_fill_a_missing_or_foreign_scan(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $rider = User::findOrFail($delivery->courier_id);
        $before = $this->snapshot($delivery);
        foreach ([null, 'BGO-WRONG'] as $barcode) {
            try {
                app(OrderStateMachineService::class)->transition($delivery, 'picked_up', $rider, ['barcode' => $barcode]);
                $this->fail('An actual matching scan is required.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('barcode', $error->errors());
            }
            $this->assertSame($before, $this->snapshot($delivery));
        }
    }

    public function test_failed_checkpoint_write_rolls_back_the_pickup(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = $this->snapshot($delivery);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($record) {
            if ($record->checkpoint_type === 'picked_up') {
                throw new RuntimeException('Custody storage failed');
            }
        });
        try {
            app(OrderStateMachineService::class)->transition($delivery, 'picked_up', User::findOrFail($delivery->courier_id), ['barcode' => $delivery->tracking_number]);
            $this->fail('Evidence failure must roll back pickup.');
        } catch (RuntimeException $error) {
            $this->assertSame('Custody storage failed', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_recorded_checkpoint_refuses_migration_rollback_and_retains_its_guard(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = $this->snapshot($delivery);
        $migration = require database_path('migrations/2026_10_07_100000_retain_custody_checkpoint_evidence.php');
        try {
            $migration->down();
            $this->fail('History cannot lose its guard through rollback.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        try {
            DB::table('delivery_checkpoints')->where('delivery_id', $delivery->id)->update(['notes' => 'Tampered after rollback']);
            $this->fail('History remains immutable.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Custody evidence is immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_additive_migration_preserves_legacy_records_without_invented_facts(): void
    {
        $migration = require database_path('migrations/2026_10_07_100000_retain_custody_checkpoint_evidence.php');
        $migration->down();
        $delivery = Delivery::factory()->create();
        $actor = User::factory()->courier()->create();
        $id = DB::table('delivery_checkpoints')->insertGetId(['delivery_id' => $delivery->id, 'checkpoint_type' => 'legacy_record',
            'scanned_by_id' => $actor->id, 'barcode_scanned' => $delivery->tracking_number, 'notes' => 'Original history', 'created_at' => '2025-01-01 08:00:00', 'updated_at' => '2025-01-01 08:00:00']);
        $before = (array) DB::table('delivery_checkpoints')->where('id', $id)->first();
        $migration->up();
        $after = (array) DB::table('delivery_checkpoints')->where('id', $id)->first();
        $this->assertSame($before, array_intersect_key($after, $before));
        foreach (['record_reference', 'actor_role', 'scan_provenance', 'source_state', 'target_state', 'custody_before', 'custody_after', 'source_checkpoint_id'] as $field) {
            $this->assertNull($after[$field]);
        }
    }

    public function test_ambiguous_legacy_order_alias_cannot_scan_the_wrong_parcel(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        // This initial legacy fixture deliberately collides across the two lookup namespaces.
        $other = Delivery::factory()->create(['tracking_number' => $order->order_number]);
        $before = $this->snapshot($delivery);
        $otherBefore = $other->fresh()->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), ['barcode' => $order->order_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
    }

    public function test_another_authorized_handler_cannot_claim_the_original_scan_as_a_retry(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $other = $this->createApprovedUser('logistics');
        HubHandler::create(['user_id' => $other->id, 'logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id, 'is_active' => true]);
        $before = $this->snapshot($delivery);
        $this->actingAs($other)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_buyer_confirmation_records_commercial_completion_without_inventing_a_scan_or_handoff(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $custody = DeliveryCheckpoint::lastCustody($delivery);
        $this->completeFlowOrder($order);
        $record = $delivery->checkpoints()->where('checkpoint_type', 'buyer_completed')->sole();
        $this->assertSame($order->buyer_id, $record->scanned_by_id);
        $this->assertSame('buyer', $record->actor_role);
        $this->assertNull($record->barcode_scanned);
        $this->assertSame('not_scanned', $record->scan_provenance);
        $this->assertSame('delivered', $record->source_state['order_status']);
        $this->assertSame('completed', $record->target_state['order_status']);
        $this->assertSame('delivered', $record->target_state['delivery_status']);
        $this->assertSame($custody, $record->custody_before);
        $this->assertSame($custody, $record->custody_after);
        $before = $this->snapshot($delivery);
        $this->completeFlowOrder($order);
        $this->assertSame($before, $this->snapshot($delivery));
    }

    private function snapshot(Delivery $delivery): array
    {
        return [$delivery->fresh()->getRawOriginal(), $delivery->order()->firstOrFail()->getRawOriginal(), $delivery->checkpoints()->orderBy('id')->get()->toArray()];
    }
}
