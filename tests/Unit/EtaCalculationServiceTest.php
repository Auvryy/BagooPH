<?php

namespace Tests\Unit;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\SlaPause;
use App\Models\User;
use App\Services\Monitoring\EtaCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtaCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyAndHub(): array
    {
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'active']);
        $company = LogisticsCompany::create([
            'user_id' => $owner->id,
            'name' => 'Test Freight Co',
            'slug' => 'test-freight-co',
            'code' => 'TFC-ETA',
            'status' => 'approved',
            'base_shipping_cents' => 8000,
            'included_weight_grams' => 1000,
            'additional_kg_cents' => 2000,
        ]);
        $hub = LogisticsHub::create([
            'logistics_company_id' => $company->id,
            'name' => 'Los Banos Bayan Hub',
            'code' => 'BH-LBN-ETA',
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Los Banos',
            'address' => '10 Lopez Ave',
            'status' => 'approved',
        ]);

        return [$owner, $company, $hub];
    }

    public function test_pht_16_00_cutoff_adds_schedule_buffer_after_cutoff_and_zero_before(): void
    {
        [, $company, $hub] = $this->makeCompanyAndHub();
        $service = app(EtaCalculationService::class);

        $beforeCutoff = CarbonImmutable::create(2026, 11, 10, 15, 59, 59, 'Asia/Manila');
        $afterCutoff = CarbonImmutable::create(2026, 11, 10, 16, 0, 0, 'Asia/Manila');

        $before = $service->preview($company, [$hub->id], true, 'doorstep', $hub, $hub, $beforeCutoff);
        $after = $service->preview($company, [$hub->id], true, 'doorstep', $hub, $hub, $afterCutoff);

        $this->assertFalse($before['cutoff_applied']);
        $this->assertTrue($after['cutoff_applied']);
        $this->assertSame('on_track', $before['eta_status']);
        $this->assertSame(
            $beforeCutoff->addHours(24)->utc()->toIso8601String(),
            $before['estimated_min_at']
        );
        $this->assertSame(
            $beforeCutoff->addHours(84)->utc()->toIso8601String(),
            $before['estimated_max_at']
        );
        $this->assertSame(
            $afterCutoff->addHours(36)->utc()->toIso8601String(),
            $after['estimated_min_at']
        );
        $this->assertSame(
            $afterCutoff->addHours(108)->utc()->toIso8601String(),
            $after['estimated_max_at']
        );
        $this->assertStringContainsString('(PHT)', $before['label']);
    }

    public function test_topology_math_handles_same_hub_3_hub_4_hub_v1_fallback_and_self_pickup(): void
    {
        [, $company, $hub] = $this->makeCompanyAndHub();
        $service = app(EtaCalculationService::class);
        $ref = CarbonImmutable::create(2026, 11, 10, 10, 0, 0, 'Asia/Manila');

        $selfPickup = $service->preview($company, [$hub->id], true, 'hub_self_pickup', $hub, $hub, $ref);
        $doorstepLocal = $service->preview($company, [$hub->id], true, 'doorstep', $hub, $hub, $ref);
        $sameMother = $service->preview($company, [1, 2, 3], false, 'doorstep', $hub, $hub, $ref);
        $crossMother = $service->preview($company, [1, 2, 3, 4], false, 'doorstep', $hub, $hub, $ref);
        $legacyV1 = $service->preview($company, [], false, 'doorstep', $hub, $hub, $ref);

        $this->assertSame($ref->addHours(20)->utc()->toIso8601String(), $selfPickup['estimated_min_at']);
        $this->assertSame($ref->addHours(68)->utc()->toIso8601String(), $selfPickup['estimated_max_at']);

        $this->assertSame($ref->addHours(24)->utc()->toIso8601String(), $doorstepLocal['estimated_min_at']);
        $this->assertSame($ref->addHours(84)->utc()->toIso8601String(), $doorstepLocal['estimated_max_at']);

        $this->assertSame($ref->addHours(44)->utc()->toIso8601String(), $sameMother['estimated_min_at']);
        $this->assertSame($ref->addHours(120)->utc()->toIso8601String(), $sameMother['estimated_max_at']);

        $this->assertSame($ref->addHours(68)->utc()->toIso8601String(), $crossMother['estimated_min_at']);
        $this->assertSame($ref->addHours(156)->utc()->toIso8601String(), $crossMother['estimated_max_at']);

        $this->assertSame($ref->addHours(56)->utc()->toIso8601String(), $legacyV1['estimated_min_at']);
        $this->assertSame($ref->addHours(144)->utc()->toIso8601String(), $legacyV1['estimated_max_at']);
    }

    public function test_pause_shift_remaining_leg_recompute_and_overdue_revision(): void
    {
        [$owner, $company, $hub] = $this->makeCompanyAndHub();
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $ref = CarbonImmutable::create(2026, 11, 10, 9, 0, 0, 'Asia/Manila');
        $this->travelTo($ref);

        $order = Order::factory()->create([
            'user_id' => $buyer->id,
            'status' => 'placed',
            'delivery_type' => 'doorstep',
            'logistics_flow_version' => 2,
        ]);
        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $hub->id,
            'destination_bayan_hub_id' => $hub->id,
            'current_hub_id' => $hub->id,
            'route_hub_ids' => [$hub->id],
            'same_hub_bypass' => true,
            'delivery_type' => 'doorstep',
            'status' => 'unassigned',
        ]);

        $service = app(EtaCalculationService::class);
        $initial = $service->applyToDelivery($delivery, $order);
        $this->assertSame('same_hub_bypass', $initial->eta_basis['route_tier']);
        $this->assertSame('on_track', $initial->eta_basis['eta_status']);

        // Active typhoon Force-Majeure pause shifts preview + persisted recalculation
        $pause = SlaPause::create([
            'scope_type' => 'platform',
            'reason_code' => 'typhoon',
            'title' => 'Signal No. 3 Luzon Suspend',
            'status' => 'active',
            'starts_at' => $ref,
            'created_by_id' => $owner->id,
        ]);

        $pausedPreview = $service->preview($company, [$hub->id], true, 'doorstep', $hub, $hub, $ref);
        $this->assertSame('paused', $pausedPreview['eta_status']);
        $this->assertNotNull($pausedPreview['active_pause']);
        $this->assertSame($ref->addHours(48)->utc()->toIso8601String(), $pausedPreview['estimated_min_at']);

        // End pause, advance delivery to out_for_delivery AFTER original max ETA passed -> overdue + smaller remaining leg
        $pause->update(['status' => 'ended', 'ended_at' => $ref->addHours(4), 'resumed_at' => $ref->addHours(4)]);
        $lateNow = $initial->estimated_delivery_max_at->addHours(6);
        $this->travelTo($lateNow);

        $order->update(['status' => 'shipped']);
        $delivery->update(['status' => 'out_for_delivery', 'courier_id' => null]);

        $recomputed = $service->calculateForDelivery($delivery->fresh(), $lateNow, $order->fresh(), 14400);
        $this->assertSame('delayed', $recomputed['eta_basis']['eta_status']);
        $this->assertSame(1, $recomputed['eta_basis']['remaining_hours_min']);
        $this->assertSame(8, $recomputed['eta_basis']['remaining_hours_max']);
        $this->assertSame(14400, $recomputed['eta_basis']['paused_seconds_applied']);

        $delivery->forceFill($recomputed)->save();
        $presented = $service->present($delivery->fresh(), $order->fresh(), publicSafe: true);
        $this->assertSame('delayed', $presented['eta_status']);
        $this->assertSame('Delayed (Updated Forecast)', $presented['status_label']);
        $this->assertNotNull($presented['delay_notice']);
        $this->assertStringContainsString('(PHT)', $presented['label']);
    }
}
