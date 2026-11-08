<?php

namespace Tests\Feature\Logistics;

use App\Models\User;
use App\Services\Courier\RiderCashService;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCodCollection;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class LogisticsSafetyAndFloatShieldTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithCodCollection, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_cash_float_shield_summary_is_passed_to_rider_workspace(): void
    {
        $this->withoutVite();
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($delivery->assigned_rider_id);

        $this->actingAs($rider)
            ->get('/courier/deliveries')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Courier/Deliveries')
                ->has('cashSummary', fn (AssertableInertia $summary) => $summary
                    ->where('max_float_cents', RiderCashService::MAX_UNREMITTED_CASH_CENTS)
                    ->where('warning_float_cents', RiderCashService::WARNING_UNREMITTED_CASH_CENTS)
                    ->has('held_cents')
                    ->where('is_locked', false)
                    ->where('is_warning', false)
                )
            );
    }

    public function test_cod_delivery_succeeds_when_under_twenty_thousand_float_limit(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($delivery->assigned_rider_id);

        // Under limit (e.g. ₱5,000)
        $this->mock(RiderCashService::class, function ($mock) use ($rider) {
            $mock->shouldReceive('heldCashCents')
                ->with(\Mockery::on(fn ($user) => $user->id === $rider->id))
                ->andReturn(500000);
        });

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $payload = array_merge($this->codCollectionInput($delivery), [
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->createWithContent('pod.png', $png),
        ]);

        $response = $this->actingAs($rider)
            ->patch('/courier/deliveries/'.$delivery->id.'/status', $payload);

        $response->assertSessionHasNoErrors();
        $this->assertSame(OrderStateMachineService::STATUS_DELIVERED, $delivery->fresh()->status);
    }

    public function test_cod_delivery_is_blocked_when_rider_reaches_twenty_thousand_cash_limit(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($delivery->assigned_rider_id);

        // At or over float limit (₱20,000)
        $this->mock(RiderCashService::class, function ($mock) use ($rider) {
            $mock->shouldReceive('heldCashCents')
                ->with(\Mockery::on(fn ($user) => $user->id === $rider->id))
                ->andReturn(2000000);
        });

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $payload = array_merge($this->codCollectionInput($delivery), [
            'status' => 'delivered',
            'proof_image_file' => UploadedFile::fake()->createWithContent('pod.png', $png),
        ]);

        $response = $this->actingAs($rider)
            ->patch('/courier/deliveries/'.$delivery->id.'/status', $payload);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('₱20,000 unremitted cash float limit', session('errors')->first('status'));
        $this->assertNotSame(OrderStateMachineService::STATUS_DELIVERED, $delivery->fresh()->status);
    }
}
