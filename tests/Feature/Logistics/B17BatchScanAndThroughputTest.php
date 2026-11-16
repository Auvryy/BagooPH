<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\ScanBatchItem;
use App\Models\ScanBatchRequest;
use App\Models\ScanRequestLedger;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class B17BatchScanAndThroughputTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_direct_mode_single_scan_confirms_via_web_and_api_with_next_step_guidance(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller, ['city' => 'Calamba']);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']);
        $parcel = $this->flowDelivery($order, 'picked_up');

        $originHub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);
        $beforeCheckpoints = $parcel->checkpoints()->count();

        $token = (string) Str::uuid();
        $response = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $originHub->id,
            'direct_mode' => true,
            'pinned_action' => 'ANY_PERMITTED_PARCEL_STEP',
            'items' => [
                [
                    'barcode' => $parcel->tracking_number,
                    'request_token' => $token,
                    'client_scanned_at' => now()->toIso8601String(),
                ],
            ],
        ])->assertOk();

        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('direct_mode', true)
            ->assertJsonPath('summary.submitted', 1)
            ->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('summary.duplicate_replay', 0)
            ->assertJsonPath('summary.rejected', 0)
            ->assertJsonPath('items.0.outcome', 'confirmed')
            ->assertJsonPath('items.0.action_applied', 'RECEIVE_FROM_PICKUP_RIDER')
            ->assertJsonPath('items.0.status_after', 'arrived_at_origin_hub')
            ->assertJsonPath('scan_result.confirmed', true)
            ->assertJsonPath('scan_result.delivery.status', 'arrived_at_origin_hub');

        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertGreaterThan($beforeCheckpoints, $parcel->checkpoints()->count());

        // Move parcel to Mother Hub and test /api/v1/logistics/scan/batch + /logistics/scan/batch paths.
        $this->confirmFlowManifest($parcel, $originHub, true);
        $motherHub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $this->confirmFlowManifest($parcel, $motherHub, false);
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);

        $motherHandler = $this->flowHandler($motherHub);
        $motherHandler->update(['password' => 'Batch-Api!4832']);
        $apiToken = $this->postJson('/api/v1/logistics/auth/tokens', [
            'email' => $motherHandler->email,
            'password' => 'Batch-Api!4832',
            'device_name' => 'B17 Handheld Scanner',
        ])->assertOk()->json('data.token');

        $apiResponse = $this->withToken($apiToken)->postJson('/api/v1/logistics/scan/batch', [
            'hub_id' => $motherHub->id,
            'direct_mode' => true,
            'pinned_action' => 'SORT_TO_LINE_HAUL',
            'items' => [
                [
                    'barcode' => $parcel->tracking_number,
                    'request_token' => (string) Str::uuid(),
                ],
            ],
        ])->assertOk();

        $apiResponse
            ->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('items.0.action_applied', 'SORT_TO_LINE_HAUL')
            ->assertJsonPath('items.0.status_after', 'sorted_to_line_haul')
            ->assertJsonPath('scan_result.confirmed', true);

        $this->assertDatabaseHas('scan_batch_requests', [
            'batch_reference' => $apiResponse->json('batch_reference'),
            'source_channel' => 'api',
            'actor_id' => $motherHandler->id,
            'confirmed_count' => 1,
        ]);
    }

    public function test_idempotent_request_token_replay_and_payload_collision_protection(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $originHub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);
        $token = (string) Str::uuid();

        $payload = [
            'hub_id' => $originHub->id,
            'direct_mode' => false,
            'items' => [
                [
                    'barcode' => $parcel->tracking_number,
                    'request_token' => $token,
                    'notes' => 'Inbound chute A',
                ],
            ],
        ];

        $this->actingAs($handler)->postJson(route('hub.scan.batch'), $payload)
            ->assertOk()
            ->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('items.0.outcome', 'confirmed');

        $checkpointCountAfterFirst = $parcel->checkpoints()->count();

        // Exact retry replays cached ledger outcome without adding duplicate checkpoints.
        $this->actingAs($handler)->postJson(route('hub.scan.batch'), $payload)
            ->assertOk()
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.duplicate_replay', 1)
            ->assertJsonPath('summary.rejected', 0)
            ->assertJsonPath('items.0.outcome', 'duplicate_replay');

        $this->assertSame($checkpointCountAfterFirst, $parcel->checkpoints()->count());

        // Same token with a tampered/different payload is rejected cleanly.
        $collisionPayload = $payload;
        $collisionPayload['items'][0]['barcode'] = 'BGO-TAMPERED-999';

        $collision = $this->actingAs($handler)->postJson(route('hub.scan.batch'), $collisionPayload)->assertOk();
        $collision
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.duplicate_replay', 0)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('items.0.outcome', 'rejected');

        $this->assertStringContainsString('different scan payload', (string) $collision->json('items.0.error'));
    }

    public function test_partial_failure_isolation_commits_valid_items_and_rejects_bad_items(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller, ['city' => 'Calamba']);
        $buyer = $this->createApprovedUser('buyer');

        $p1 = $this->flowDelivery($this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']), 'picked_up');
        $p2 = $this->flowDelivery($this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']), 'picked_up');

        $originHub = LogisticsHub::findOrFail($p1->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);

        $hubDomain = 'http://hub.'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost').'/scan/batch';

        $response = $this->actingAs($handler)->postJson($hubDomain, [
            'hub_id' => $originHub->id,
            'direct_mode' => false,
            'items' => [
                ['barcode' => $p1->tracking_number, 'request_token' => (string) Str::uuid()],
                ['barcode' => 'BGO-DOES-NOT-EXIST-404', 'request_token' => (string) Str::uuid()],
                ['barcode' => $p2->tracking_number, 'request_token' => (string) Str::uuid()],
            ],
        ])->assertOk();

        $response
            ->assertJsonPath('summary.submitted', 3)
            ->assertJsonPath('summary.confirmed', 2)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('items.0.outcome', 'confirmed')
            ->assertJsonPath('items.1.outcome', 'rejected')
            ->assertJsonPath('items.2.outcome', 'confirmed');

        $this->assertSame('arrived_at_origin_hub', $p1->fresh()->status);
        $this->assertSame('arrived_at_origin_hub', $p2->fresh()->status);
        $this->assertSame(3, ScanBatchItem::count());
    }

    public function test_batch_safety_boundaries_reject_automation_tampering_non_parcel_docs_and_unauthorized_actors(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_mother_hub');
        $motherHub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $handler = $this->flowHandler($motherHub);
        $buyer = $parcel->order->buyer;
        $otherHub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $otherHandler = $this->flowHandler($otherHub);

        $batchCountBefore = ScanBatchRequest::count();

        // Prohibited automation and actor override flags fail at request validation (422).
        foreach ([
            ['continuous' => true],
            ['auto_advance' => true],
            ['pinned_action' => 'AUTO_PROGRESS'],
            ['actor_id' => $handler->id],
        ] as $forbidden) {
            $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
                'hub_id' => $motherHub->id,
                ...$forbidden,
                'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
            ])->assertUnprocessable();
        }

        $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [[
                'barcode' => $parcel->tracking_number,
                'request_token' => (string) Str::uuid(),
                'actor_id' => $handler->id,
            ]],
        ])->assertUnprocessable();

        $this->assertSame($batchCountBefore, ScanBatchRequest::count());

        // Non-logistics buyers and handlers outside the hub get 403.
        $this->actingAs($buyer)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertForbidden();

        $this->actingAs($otherHandler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertForbidden();

        // BAG-, RMB-, and BRT- references are isolated as rejected items inside batch mode.
        $nonParcels = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [
                ['barcode' => 'BAG-TEST-001', 'request_token' => (string) Str::uuid()],
                ['barcode' => 'RMB-TEST-002', 'request_token' => (string) Str::uuid()],
                ['barcode' => 'BRT-TEST-003', 'request_token' => (string) Str::uuid()],
            ],
        ])->assertOk();

        $nonParcels
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.rejected', 3);

        // Wrong pinned_action rejects cleanly without mutating parcel status.
        $wrongAction = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'pinned_action' => 'RECEIVE_FROM_PICKUP_RIDER',
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertOk();

        $wrongAction
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.rejected', 1);
        $this->assertStringContainsString('SORT_TO_LINE_HAUL', (string) $wrongAction->json('items.0.error'));
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);

        // Wrong Poka-Yoke location_barcode rejects with Mis-sort Error.
        $misSort = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [[
                'barcode' => $parcel->tracking_number,
                'request_token' => (string) Str::uuid(),
                'location_barcode' => 'LOC-WRONG-BIN-99',
            ]],
        ])->assertOk();

        $misSort
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.rejected', 1);
        $this->assertStringContainsString('Mis-sort Error', (string) $misSort->json('items.0.error'));
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);

        // Manifest-only stage cannot be confirmed through batch scanning.
        $this->confirmFlowScan($parcel, $motherHub, 'SORT_TO_LINE_HAUL');
        $manifestOnly = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertOk();

        $manifestOnly
            ->assertJsonPath('summary.confirmed', 0)
            ->assertJsonPath('summary.rejected', 1);
        $this->assertStringContainsString('Manifests or Mother Bags', (string) $manifestOnly->json('items.0.error'));
    }

    public function test_immutable_scan_batch_audit_tables_reject_updates_and_deletes(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $originHub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);

        $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $originHub->id,
            'direct_mode' => true,
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertOk()->assertJsonPath('summary.confirmed', 1);

        $batch = ScanBatchRequest::firstOrFail();
        $ledger = ScanRequestLedger::firstOrFail();
        $item = ScanBatchItem::firstOrFail();

        $this->assertNotNull($batch->finalized_at);

        foreach ([
            fn () => DB::table('scan_batch_requests')->where('id', $batch->id)->update(['confirmed_count' => 99]),
            fn () => DB::table('scan_batch_requests')->where('id', $batch->id)->delete(),
            fn () => DB::table('scan_request_ledgers')->where('id', $ledger->id)->update(['action_applied' => 'TAMPERED']),
            fn () => DB::table('scan_request_ledgers')->where('id', $ledger->id)->delete(),
            fn () => DB::table('scan_batch_items')->where('id', $item->id)->update(['outcome' => 'rejected']),
            fn () => DB::table('scan_batch_items')->where('id', $item->id)->delete(),
        ] as $tamper) {
            $threw = false;
            try {
                $tamper();
            } catch (QueryException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'Retained B17 scan audit rows must reject SQL UPDATE and DELETE.');
        }
    }

    public function test_batch_throughput_25_parcels_and_bounded_station_queries(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller, ['city' => 'Calamba']);
        $buyer = $this->createApprovedUser('buyer');

        $firstBatch = [];
        for ($i = 0; $i < 5; $i++) {
            $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']);
            $firstBatch[] = $this->flowDelivery($order, 'picked_up', $this->createApprovedUser('courier'));
        }

        $originHub = LogisticsHub::findOrFail($firstBatch[0]->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);

        $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $originHub->id,
            'direct_mode' => false,
            'pinned_action' => 'RECEIVE_FROM_PICKUP_RIDER',
            'items' => array_map(fn ($parcel) => [
                'barcode' => $parcel->tracking_number,
                'request_token' => (string) Str::uuid(),
            ], $firstBatch),
        ])->assertOk()->assertJsonPath('summary.confirmed', 5);

        $this->actingAs($handler);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/hub/deliveries?hub_id='.$originHub->id)->assertOk();
        $queriesAt5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $parcels = [];
        for ($i = 0; $i < 25; $i++) {
            $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']);
            $parcels[] = $this->flowDelivery($order, 'picked_up', $this->createApprovedUser('courier'));
        }

        $items = array_map(fn ($parcel) => [
            'barcode' => $parcel->tracking_number,
            'request_token' => (string) Str::uuid(),
        ], $parcels);

        $wallStart = hrtime(true);
        $response = $this->actingAs($handler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $originHub->id,
            'direct_mode' => false,
            'pinned_action' => 'RECEIVE_FROM_PICKUP_RIDER',
            'items' => $items,
        ])->assertOk();
        $wallMs = (int) round((hrtime(true) - $wallStart) / 1_000_000);

        $response
            ->assertJsonPath('summary.submitted', 25)
            ->assertJsonPath('summary.confirmed', 25)
            ->assertJsonPath('summary.rejected', 0);

        $elapsedMs = (int) $response->json('summary.elapsed_ms');
        $scansPerSec = (float) $response->json('summary.scans_per_sec');

        $this->assertGreaterThan(0, $elapsedMs);
        $this->assertGreaterThan(0.0, $scansPerSec);
        $this->assertLessThan(15000, $wallMs, '25-parcel batch confirmation should finish cleanly within budget.');

        foreach ($parcels as $parcel) {
            $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        }

        // Verify /hub/deliveries query count stays bounded and non-N+1 as queue grows from 5 to 30 parcels.
        $this->actingAs($handler);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/hub/deliveries?hub_id='.$originHub->id)->assertOk();
        $queriesAt30 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($queriesAt5 + 5, $queriesAt30, "Hub deliveries board queries grew from {$queriesAt5} (5 parcels) to {$queriesAt30} (30 parcels); per-parcel N+1 detected.");
        $this->assertLessThan(100, $queriesAt30, 'Hub deliveries board should keep query count bounded for 30 active parcels.');
    }

    public function test_full_b00_to_b17_acceptance_lifecycle_with_direct_scan_and_inline_open_mother_bag_pack(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller, ['city' => 'Calamba']);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->checkoutFlowOrder($buyer, $shop, [], 'placed', ['shipping_city' => 'Santa Cruz']);

        // 1. Seller fulfillment & Pickup Rider pickup.
        $parcel = $this->flowDelivery($order, 'picked_up');
        $originHub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $motherHub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $destinationHub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $originHandler = $this->flowHandler($originHub);
        $company = LogisticsCompany::findOrFail($originHub->logistics_company_id);
        $manager = User::findOrFail($company->user_id);

        // 2. Origin Hub uses B17 1-Scan Direct Confirm to intake parcel from pickup rider.
        $this->actingAs($originHandler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $originHub->id,
            'direct_mode' => true,
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertOk()->assertJsonPath('summary.confirmed', 1)->assertJsonPath('scan_result.delivery.status', 'arrived_at_origin_hub');

        // 3. Origin Hub uses B17 inline "Add to open Mother Bag" with outer-package reading & idempotent retry.
        $packToken = (string) Str::uuid();
        $packPayload = [
            'source_hub_id' => $originHub->id,
            'barcode' => $parcel->tracking_number,
            'request_token' => $packToken,
            'handling' => [
                'weight_kg' => 1.5,
                'length_cm' => 24,
                'width_cm' => 18,
                'height_cm' => 12,
                'confirmed' => true,
            ],
        ];
        $packResponse = $this->actingAs($originHandler)->postJson('/hub/mother-bags/pack', $packPayload)->assertOk();
        $bagId = (string) $packResponse->json('bag.id');
        $this->assertNotSame('', $bagId);
        $this->assertSame($bagId, (string) $this->actingAs($originHandler)->postJson('/hub/mother-bags/pack', $packPayload)->assertOk()->json('bag.id'));

        $bag = MotherBag::findOrFail($bagId);
        $this->assertSame('open', $bag->status);

        // 4. Create feeder manifest, seal bag, load bag, seal manifest & dispatch to Mother Hub.
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create([
            'logistics_company_id' => $company->id,
            'hub_id' => $originHub->id,
            'plate_number' => 'B17-0001',
            'vehicle_type' => 'l300_van',
            'model' => 'Bagoo Feeder Van',
            'capacity_kg' => 5000,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);
        $driver->courierProfile->update([
            'logistics_company_id' => $company->id,
            'assigned_hub_id' => $originHub->id,
            'vehicle_id' => $vehicle->id,
            'is_available' => true,
        ]);

        $this->actingAs($this->flowHandler($originHub))->get('/hub/manifests')->assertOk();
        $manifestResp = $this->postJson('/hub/manifests', [
            'source_hub_id' => $originHub->id,
            'destination_hub_id' => $motherHub->id,
            'direction' => 'outbound',
            'vehicle_id' => $vehicle->id,
            'creation_token' => session()->get('manifest_creation_token'),
        ])->assertCreated();
        $feederManifest = LogisticsManifest::findOrFail($manifestResp->json('manifest.id'));

        $this->actingAs($originHandler)->postJson("/hub/mother-bags/{$bag->id}/seal", [
            'version' => $bag->fresh()->version,
            'seal_number' => 'B17-SEAL-0001',
        ])->assertOk();
        $this->actingAs($originHandler)->postJson("/hub/mother-bags/{$bag->id}/load", [
            'version' => $bag->fresh()->version,
            'barcode' => $bag->reference,
            'seal_number' => 'B17-SEAL-0001',
            'manifest_id' => $feederManifest->id,
        ])->assertOk();
        $this->actingAs($this->flowHandler($originHub))->postJson("/hub/manifests/{$feederManifest->id}/seal", $this->flowManifestPayload($feederManifest))->assertOk();
        $this->actingAs($originHandler)->postJson("/hub/manifests/{$feederManifest->id}/dispatch", $this->flowManifestPayload($feederManifest))->assertOk();

        // 5. Mother Hub opens bag, receives parcel & closes manifest, then uses B17 Batch Queue scan to sort to line haul.
        $motherHandler = $this->flowHandler($motherHub);
        $this->openFlowMotherBags($feederManifest->fresh(), $motherHandler);
        $this->actingAs($motherHandler)->postJson("/hub/manifests/{$feederManifest->id}/receive", $this->flowManifestPayload($feederManifest, ['barcode' => $parcel->tracking_number]))->assertOk();
        $this->actingAs($manager)->postJson("/hub/manifests/{$feederManifest->id}/close", $this->flowManifestPayload($feederManifest))->assertOk();

        $this->actingAs($motherHandler)->postJson(route('hub.scan.batch'), [
            'hub_id' => $motherHub->id,
            'direct_mode' => false,
            'pinned_action' => 'SORT_TO_LINE_HAUL',
            'items' => [['barcode' => $parcel->tracking_number, 'request_token' => (string) Str::uuid()]],
        ])->assertOk()->assertJsonPath('summary.confirmed', 1)->assertJsonPath('items.0.status_after', 'sorted_to_line_haul');

        // 6. Dispatch from Mother Hub to Destination Bayan Hub & receive there.
        if ($parcel->fresh()->origin_mother_hub_id !== $parcel->fresh()->destination_mother_hub_id) {
            $this->confirmFlowManifest($parcel->fresh(), $motherHub, true);
            $motherHub = LogisticsHub::findOrFail($parcel->fresh()->destination_mother_hub_id);
            $this->confirmFlowManifest($parcel->fresh(), $motherHub, false);
            $this->confirmFlowScan($parcel->fresh(), $motherHub, 'SORT_TO_LINE_HAUL');
        }
        $this->confirmFlowManifest($parcel->fresh(), $motherHub, true);
        $this->confirmFlowManifest($parcel->fresh(), $destinationHub, false);
        $this->assertSame('arrived_at_destination_hub', $parcel->fresh()->status);

        // 7. Destination sorting -> Final-mile rider -> POD delivery -> COD settlement -> Seller payout.
        $destHandler = $this->flowHandler($destinationHub);
        $this->actingAs($destHandler)->postJson(route('hub.sort'), [
            'delivery_id' => $parcel->id,
            'barangay' => $order->destination_barangay,
        ])->assertOk();

        $finalRider = $this->flowRider($destinationHub);
        $this->actingAs($destHandler)->postJson(route('hub.assignRider', $parcel), [
            'rider_id' => $finalRider->id,
        ])->assertOk();

        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $parcel), [
            'status' => 'out_for_delivery',
            'barcode' => $parcel->tracking_number,
        ])->assertSessionHas('success');

        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $parcel), [
            ...$this->codCollectionInput($parcel->fresh()),
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->image('b17-pod.jpg'),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('success');

        $this->settleFlowOrder($order->fresh());

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('delivered', $parcel->fresh()->status);
        $this->assertGreaterThanOrEqual(2, ScanBatchRequest::count());
        $this->assertSame(2, ScanRequestLedger::count());
        $this->assertGreaterThanOrEqual(1, DeliveryCheckpoint::where('delivery_id', $parcel->id)->count());
    }
}
