<?php

namespace Tests\Feature\Logistics;

use App\Models\CodCustodyEntry;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsHub;
use App\Models\PickupClaim;
use App\Models\PickupClaimEvent;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Logistics\PickupClaimService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class SecureHubPickupTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return ['root' => ['/hub'], 'subdomain' => ['http://hub.localhost']];
    }

    private function arriving(bool $hours = true): Delivery
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
        $hub->update(['allows_self_pickup' => true]);
        if ($hours) {
            $this->actingAs($hub->company->user)->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00'])->assertOk();
        }
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);

        return $this->flowDelivery($order, 'arrived_at_destination_hub');
    }

    private function staged(string $base = '/hub'): Delivery
    {
        $parcel = $this->arriving();
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub));
        $prompt = $this->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk()->assertJsonPath('prompt.action', 'STAGE_FOR_PICKUP')->json('prompt');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'STAGE_FOR_PICKUP', 'expected_status' => $prompt['expected_status']];
        $this->postJson($base.'/scan', $payload)->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson($base.'/scan', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));

        return $parcel->fresh();
    }

    private function code(Delivery $parcel): string
    {
        $response = $this->actingAs($parcel->order->buyer)->postJson('http://localhost/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $code = $response->json('code');
        $this->assertTrue(Hash::check($code, PickupClaim::where('delivery_id', $parcel->id)->sole()->getRawOriginal('code_hash')));

        return $code;
    }

    private function payload(Delivery $parcel, string $code): array
    {
        return ['barcode' => $parcel->tracking_number, 'hub_id' => $parcel->destination_bayan_hub_id, 'claim_code' => $code,
            'buyer_id' => $parcel->order->buyer_id, 'recipient_name' => $parcel->order->buyer->name, 'identity_confirmed' => true,
            'request_token' => (string) Str::uuid(), 'cash_received' => (string) $parcel->order->total_amount, 'change_given' => '0', 'cash_confirmed' => true,
            'notes' => 'Buyer photo ID checked and actual cash counted at the counter.'];
    }

    private function snapshot(Delivery $parcel): array
    {
        $result = ['order' => $parcel->order->fresh()->getAttributes(), 'delivery' => $parcel->fresh()->getAttributes()];
        foreach (['pickup_claims', 'pickup_claim_events', 'cod_custody_entries', 'delivery_checkpoints', 'notifications', 'delivery_return_routes', 'delivery_return_events'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    #[DataProvider('portals')]
    public function test_actual_counter_collection_requires_sources_and_only_owning_buyer_completes(string $base): void
    {
        $parcel = $this->staged($base);
        $claim = PickupClaim::where('delivery_id', $parcel->id)->sole();
        $this->assertTrue($claim->expires_at->equalTo($claim->ready_at->timezone('Asia/Manila')->addDays(7)->utc()));
        $stock = $parcel->order->items->first()->product->stock;
        $payload = $this->payload($parcel, $this->code($parcel));
        $hub = LogisticsHub::findOrFail($claim->hub_id);
        $actor = $this->flowHandler($hub);
        $this->actingAs($actor)->get($base.'/counter')->assertOk()->assertDontSee('code_hash')->assertDontSee($payload['claim_code']);
        $this->postJson($base.'/release', $payload)->assertOk()->assertJsonPath('delivery.status', 'customer_collected');
        $cash = CodCustodyEntry::sole();
        $this->assertSame($actor->id, $cash->holder_user_id);
        $this->assertSame((int) round((float) $parcel->order->total_amount * 100), $cash->amount_cents);
        $this->assertSame('buyer', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $this->assertNull($parcel->fresh()->current_hub_id);
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $before = $this->snapshot($parcel);
        $this->postJson($base.'/release', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson($base.'/release', array_replace($payload, ['notes' => 'Changed evidence']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->actingAs($this->createApprovedUser('buyer'))->post('http://localhost/buyer/orders/'.$parcel->order_id.'/confirm')->assertForbidden();
        $this->actingAs($parcel->order->buyer)->get('http://localhost/buyer/orders/'.$parcel->order_id)->assertInertia(fn (Assert $page) => $page->where('canConfirmReceipt', true));
        $this->post(route('buyer.orders.confirm', $parcel->order_id))->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertSame('customer_collected', $parcel->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertNull($parcel->order->fresh()->commissionLedger);
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $this->actingAs($actor)->postJson($base.'/release', $payload)->assertOk();
        $this->assertSame(1, CodCustodyEntry::count());
    }

    public function test_missing_identity_code_cash_and_actual_waybill_never_release_or_consume(): void
    {
        $parcel = $this->staged();
        $payload = $this->payload($parcel, $this->code($parcel));
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id)));
        $before = $this->snapshot($parcel);
        foreach (['claim_code' => '', 'buyer_id' => 0, 'recipient_name' => 'Different Buyer', 'identity_confirmed' => false,
            'request_token' => '', 'cash_received' => '1', 'change_given' => '1', 'cash_confirmed' => false,
            'barcode' => $parcel->order->order_number, 'notes' => '<b>Checked</b>'] as $key => $value) {
            $response = $this->postJson('/hub/release', array_replace($payload, [$key => $value]));
            $this->assertContains($response->status(), [404, 409, 422], $key);
            $this->assertSame($before, $this->snapshot($parcel), $key);
        }
        $this->post('/hub/release', array_replace($payload, ['cash_confirmed' => false]))->assertSessionHasErrors('counter');
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/hub/release', $payload)->assertOk();
    }

    public function test_five_failed_codes_are_retained_and_locked_for_fifteen_minutes_with_original_retries(): void
    {
        $parcel = $this->staged();
        $valid = $this->payload($parcel, $this->code($parcel));
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id)));
        $invalid = array_replace($valid, ['claim_code' => 'INVALID-CODE']);
        $this->postJson('/hub/release', $invalid)->assertStatus(422);
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/release', $invalid)->assertStatus(422);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('/hub/release', array_replace($invalid, ['claim_code' => 'OTHER-CODE']))->assertConflict();
        for ($n = 0; $n < 4; $n++) {
            $this->postJson('/hub/release', array_replace($invalid, ['request_token' => (string) Str::uuid()]))->assertStatus(422);
        }
        $claim = PickupClaim::sole();
        $this->assertSame(5, $claim->failed_verifications);
        $this->assertSame(now()->addMinutes(15)->timestamp, $claim->locked_until->timestamp);
        $this->postJson('/hub/release', array_replace($valid, ['request_token' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame('ready_for_hub_pickup', $parcel->fresh()->status);
        $this->travel(15)->minutes();
        $this->postJson('/hub/release', array_replace($valid, ['request_token' => (string) Str::uuid()]))->assertOk();
        $this->assertSame(5, PickupClaimEvent::whereIn('event_type', ['invalid_code', 'verification_locked'])->count());
    }

    public function test_code_is_shown_once_to_owner_and_never_in_history_notices_or_public_tracking(): void
    {
        $parcel = $this->staged();
        $this->actingAs($this->createApprovedUser('buyer'))->postJson('http://localhost/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertForbidden();
        $this->actingAs($this->createApprovedUser('admin'))->postJson('http://localhost/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertForbidden();
        $code = $this->code($parcel);
        $this->postJson('http://localhost/buyer/orders/'.$parcel->order_id.'/pickup-code')->assertConflict();
        $this->get('http://localhost/buyer/orders/'.$parcel->order_id)->assertOk()->assertDontSee($code)->assertDontSee('code_hash');
        $this->getJson('/api/track/'.$parcel->tracking_number)->assertOk()->assertDontSee($code)->assertDontSee('code_hash');
        $this->assertStringNotContainsString($code, json_encode(DB::table('notifications')->get()));
        $this->assertStringNotContainsString($code, json_encode(DB::table('pickup_claim_events')->get()));
        $this->assertStringNotContainsString($code, json_encode(DB::table('delivery_checkpoints')->get()));
        $this->assertStringNotContainsString('code_hash', PickupClaim::sole()->toJson());
    }

    public function test_only_actual_destination_handler_can_release_and_company_manager_only_configures_hours(): void
    {
        $parcel = $this->staged();
        $payload = $this->payload($parcel, $this->code($parcel));
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        foreach ([$this->createApprovedUser('admin'), $hub->company->user, $this->flowHandler(LogisticsHub::findOrFail($parcel->origin_mother_hub_id))] as $actor) {
            $this->actingAs($actor)->postJson('/hub/release', $payload)->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => '08:00 to 17:00'])->assertForbidden();
        $this->actingAs($this->createApprovedUser('logistics'))->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => '08:00 to 17:00'])->assertForbidden();
    }

    public function test_actual_hours_are_required_and_status_only_staging_or_collection_is_barred(): void
    {
        $parcel = $this->arriving(false);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'STAGE_FOR_PICKUP', 'expected_status' => $parcel->status])->assertConflict();
        foreach (['ready_for_hub_pickup', 'customer_collected'] as $target) {
            try {
                app(OrderStateMachineService::class)->transition($parcel, $target, $handler, ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number]);
                $this->fail('A direct counter transition bypassed claim evidence.');
            } catch (DomainException) {
                $this->assertSame($before, $this->snapshot($parcel));
            }
        }
    }

    #[DataProvider('portals')]
    public function test_exact_reminders_and_expiry_do_not_fabricate_custody_and_actual_scan_starts_reverse_route(string $base): void
    {
        $parcel = $this->staged($base);
        $valid = $this->payload($parcel, $this->code($parcel));
        $claim = PickupClaim::sole();
        $physical = DeliveryCheckpoint::where('delivery_id', $parcel->id)->count();
        $this->travelTo($claim->ready_at->addDays(3));
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->travelTo($claim->ready_at->addDays(6));
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->assertSame(1, PickupClaimEvent::where('event_type', 'day_three')->count());
        $this->assertSame(1, PickupClaimEvent::where('event_type', 'day_six')->count());
        $this->travelTo($claim->expires_at);
        $hub = LogisticsHub::findOrFail($claim->hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson($base.'/release', $valid)->assertConflict();
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->artisan('pickup:process-due')->assertSuccessful();
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertSame($physical, DeliveryCheckpoint::where('delivery_id', $parcel->id)->count());
        $this->assertSame('ready_for_hub_pickup', $parcel->fresh()->status);
        $this->assertSame('hub', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $this->assertDatabaseCount('delivery_return_routes', 0);
        $prompt = $this->actingAs($this->flowHandler($hub))->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk()->assertJsonPath('prompt.action', 'START_EXPIRED_PICKUP_RETURN')->json('prompt');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'START_EXPIRED_PICKUP_RETURN', 'expected_status' => $prompt['expected_status'], 'claim_reference' => $prompt['claim_reference']];
        $this->postJson($base.'/scan', $payload)->assertOk();
        $before = $this->snapshot($parcel);
        $this->postJson($base.'/scan', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot($parcel));
        $route = DeliveryReturnRoute::sole();
        $this->assertFalse(Delivery::riderHasActiveWork($parcel->courier_id));
        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return', base: $base);
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return', base: $base);
        }
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm', 'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference])->assertOk();
        $this->actingAs($parcel->order->shop->user)->postJson('http://localhost/seller/orders/'.$parcel->order_id.'/return-receipt', ['barcode' => $parcel->tracking_number, 'route_reference' => $route->reference, 'notes' => 'Original unclaimed parcel received.', 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertSame(3, $parcel->order->shop->user->notifications()->count());
    }

    public function test_counter_audit_failure_rolls_back_consumption_cash_and_handoff(): void
    {
        $parcel = $this->staged();
        $payload = $this->payload($parcel, $this->code($parcel));
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($checkpoint) {
            if ($checkpoint->checkpoint_type === 'customer_collected') {
                throw new RuntimeException('Simulated counter audit failure');
            }
        });
        $this->expectException(RuntimeException::class);
        try {
            app(PickupClaimService::class)->release($parcel, $this->flowHandler($hub), $hub, $payload);
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_cash_claim_deadline_and_history_reject_direct_sql_changes(): void
    {
        $parcel = $this->staged();
        $payload = $this->payload($parcel, $this->code($parcel));
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id)))->postJson('/hub/release', $payload)->assertOk();
        $before = $this->snapshot($parcel);
        foreach (['pickup_claim_events', 'cod_custody_entries', 'notifications', 'pickup_claims'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    DB::transaction(fn () => $action === 'update' ? DB::table($table)->update(['created_at' => now()->subDay()]) : DB::table($table)->delete());
                    $this->fail('Retained evidence changed.');
                } catch (Throwable $error) {
                    $this->assertStringContainsString('retain', str_replace('immutable', 'retained', $error->getMessage()));
                }
            }
        }
        foreach (['expires_at' => now()->addDay(), 'code_hash' => 'replacement', 'consumed_at' => null] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('pickup_claims')->update([$column => $value]));
                $this->fail('Claim source changed.');
            } catch (Throwable $error) {
                $this->assertStringContainsString('retained', $error->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_only_owner_reads_notices_and_read_retries_retain_original_time_and_source(): void
    {
        $parcel = $this->staged();
        $owner = $parcel->order->buyer;
        $notice = $owner->notifications()->sole();
        $source = $notice->data;
        $this->actingAs($this->createApprovedUser('buyer'))->patchJson('http://localhost/buyer/order-notices/'.$notice->id.'/read')->assertNotFound();
        $this->actingAs($owner)->patchJson('http://localhost/buyer/order-notices/'.$notice->id.'/read')->assertOk();
        $read = $notice->fresh()->read_at->toIso8601String();
        $this->travel(1)->hours();
        $this->patchJson('http://localhost/buyer/order-notices/'.$notice->id.'/read')->assertOk();
        $this->assertSame($read, $notice->fresh()->read_at->toIso8601String());
        $this->assertSame($source, $notice->fresh()->data);
        $this->from('http://localhost/buyer/orders/'.$parcel->order_id)->patch('http://localhost/buyer/order-notices/'.$notice->id.'/read')->assertRedirect('http://localhost/buyer/orders/'.$parcel->order_id);
    }

    public function test_legacy_collected_label_has_no_buyer_completion_authority(): void
    {
        $parcel = $this->staged();
        // A legacy label is deliberately insufficient without the original counter writer.
        $parcel->update(['status' => 'customer_collected']);
        $parcel->order->update(['status' => 'delivered']);
        $this->actingAs($parcel->order->buyer)->post(route('buyer.orders.confirm', $parcel->order_id))->assertSessionHas('error');
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $this->assertFalse(app(PickupClaimService::class)->hasCollectionEvidence($parcel->fresh()));
    }

    public function test_expiry_crossed_during_code_verification_still_denies_handoff(): void
    {
        $parcel = $this->staged();
        $payload = $this->payload($parcel, $this->code($parcel));
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $claim = PickupClaim::sole();
        $this->travelTo($claim->expires_at->subSecond());
        $before = $this->snapshot($parcel);
        Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(function () use ($claim) {
            $this->travelTo($claim->expires_at);

            return true;
        });
        $this->expectException(DomainException::class);
        try {
            app(PickupClaimService::class)->release($parcel, $this->flowHandler($hub), $hub, $payload);
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }
}
