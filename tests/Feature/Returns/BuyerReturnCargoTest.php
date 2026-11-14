<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnCargoReading;
use App\Models\LogisticsHub;
use App\Services\Returns\BuyerReturnCargoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnCargoTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function reading(): array
    {
        return ['weight_kg' => '2.500', 'length_cm' => '20', 'width_cm' => '30', 'height_cm' => '40', 'confirmed' => true];
    }

    public function test_an_actual_hub_reading_is_retained_without_moving_the_return_or_changing_money(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $parcel = $case->parcel->fresh();
        $before = [$parcel->custody, $parcel->status, $parcel->route_position, $case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()];
        $handler = $this->flowHandler(LogisticsHub::findOrFail($parcel->current_hub_id));
        $input = $this->custodyPayload($case, 'measure', ['hub_id' => $parcel->current_hub_id, 'handling' => $this->reading()]);
        $this->actingAs($handler)->post('/buyer-returns/'.$case->id.'/custody', $input)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/custody', $input)->assertSessionHasNoErrors();
        $profile = app(BuyerReturnCargoService::class)->profile($parcel->fresh());
        $this->assertSame(2.5, $profile['weight_kg']);
        $this->assertSame(24000, $profile['volume_cm3']);
        $this->assertTrue($profile['baggable']);
        $this->assertSame($handler->id, $profile['actor_id']);
        $this->assertDatabaseCount('buyer_return_cargo_readings', 1);
        $this->assertSame($before, [$parcel->fresh()->custody, $parcel->fresh()->status, $parcel->fresh()->route_position, $case->order->fresh()->getAttributes(), $case->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->get('/buyer-returns/'.$case->id)->assertInertia(fn (AssertableInertia $page) => $page->where('returnCase.custody.handling.baggable', true));
        $this->actingAs($case->order->buyer)->get('/buyer-returns/'.$case->id)->assertInertia(fn (AssertableInertia $page) => $page->where('returnCase.custody.handling', null));
    }

    public static function invalid(): array
    {
        return [['zero side', ['length_cm' => '0']], ['too long', ['length_cm' => '10000.001']],
            ['negative', ['width_cm' => '-1']], ['missing', ['height_cm' => null]], ['unchecked', ['confirmed' => false]],
            ['not finite', ['weight_kg' => 'INF']], ['precision', ['height_cm' => '1.0001']], ['foreign field', ['fake' => '1']]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_or_unconfirmed_readings_do_not_leave_partial_evidence(string $label, array $change): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $parcel = $case->parcel->fresh();
        $events = $case->events()->count();
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($parcel->current_hub_id)))->post('/buyer-returns/'.$case->id.'/custody',
            $this->custodyPayload($case, 'measure', ['hub_id' => $parcel->current_hub_id, 'handling' => array_replace($this->reading(), $change)]))->assertSessionHasErrors();
        $this->assertDatabaseCount('buyer_return_cargo_readings', 0);
        $this->assertSame($events, $case->events()->count());
        $this->assertSame($parcel->getAttributes(), $parcel->fresh()->getAttributes());
    }

    public function test_corrections_append_actual_readings_before_loading_without_rewriting_the_intake_or_earlier_evidence(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $this->measureReturn($case, ['length_cm' => '60.001']);
        $this->assertFalse(app(BuyerReturnCargoService::class)->profile($case->parcel->fresh())['baggable']);
        $reading = BuyerReturnCargoReading::sole();
        $parcel = $case->parcel->fresh();
        $this->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'measure', ['hub_id' => $parcel->current_hub_id,
            'handling' => [...$this->reading(), 'weight_kg' => '3.500']]))->assertSessionHasNoErrors();
        $this->assertSame($reading->getAttributes(), $reading->fresh()->getAttributes());
        $profile = app(BuyerReturnCargoService::class)->profile($parcel->fresh());
        $this->assertTrue($profile['baggable']);
        $this->assertSame(3.5, $profile['weight_kg']);
        $this->assertSame(2500, $parcel->fresh()->weight_grams);
        $this->assertSame($reading->id, $profile['supersedes_reading_id']);
        $this->assertSame($parcel->getAttributes(), $parcel->fresh()->getAttributes());
        $this->assertDatabaseCount('buyer_return_cargo_readings', 2);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Retain actual return handling evidence.');
        DB::table('buyer_return_cargo_readings')->where('id', $reading->id)->update(['profile' => '{}']);
    }

    public function test_only_the_actual_source_handler_can_measure_before_loading(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $parcel = $case->parcel->fresh();
        $payload = $this->custodyPayload($case, 'measure', ['hub_id' => $parcel->current_hub_id, 'handling' => $this->reading()]);
        $this->actingAs($case->order->buyer)->post('/buyer-returns/'.$case->id.'/custody', $payload)->assertForbidden();
        $wrong = LogisticsHub::findOrFail($parcel->hub_ids[1]);
        $this->actingAs($this->flowHandler($wrong))->post('/buyer-returns/'.$case->id.'/custody', [...$payload, 'hub_id' => $wrong->id])->assertForbidden();
        $manifest = $this->prepareLeg($case);
        $source = $this->flowHandler($manifest->sourceHub);
        $this->actingAs($source)->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $parcel->barcode]))->assertOk();
        $this->post('/buyer-returns/'.$case->id.'/custody', [...$payload, 'request_token' => (string) Str::uuid(), 'expected_version' => $case->fresh()->version])->assertSessionHasErrors('custody');
        $this->assertDatabaseCount('buyer_return_cargo_readings', 0);
    }

    public function test_latest_actual_weight_blocks_a_small_vehicle_and_departure_retains_both_observations(): void
    {
        $case = $this->approvedReturn();
        $this->intake($case);
        $terms = $case->order->fresh()->shipping_quote;
        $this->measureReturn($case, ['weight_kg' => '3.500']);
        $manifest = $this->prepareLeg($case);
        $this->actingAs($this->flowHandler($manifest->sourceHub))->postJson('/hub/manifests/'.$manifest->id.'/load',
            $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
        $manifest->vehicle->update(['capacity_kg' => 3]);
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertConflict();
        $this->assertSame('draft', $manifest->fresh()->status);
        $manifest->vehicle->update(['capacity_kg' => 3.5]);
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $event = $manifest->events()->where('event_type', 'buyer-return-departed')->sole();
        $this->assertSame(3500, $event->payload['weight_grams']);
        $this->assertSame(2500, $event->payload['intake_weight_grams']);
        $this->assertSame(2500, $case->parcel->fresh()->weight_grams);
        $this->assertSame($terms, $case->order->fresh()->shipping_quote);
    }
}
