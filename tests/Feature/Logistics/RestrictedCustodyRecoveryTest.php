<?php

namespace Tests\Feature\Logistics;

use App\Models\CustodyRecoveryGrant;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class RestrictedCustodyRecoveryTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function phases(): array
    {
        return ['pickup root' => ['picked_up', 'http://localhost'], 'pickup subdomain' => ['picked_up', 'http://courier.localhost'],
            'final mile root' => ['out_for_delivery', 'http://localhost'], 'final mile subdomain' => ['out_for_delivery', 'http://courier.localhost']];
    }

    private function restricted(string $phase = 'out_for_delivery'): array
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $phase);
        $rider = User::findOrFail(in_array($phase, ['picked_up', 'assigned_pickup'], true) ? $parcel->courier_id : $parcel->assigned_rider_id);
        $before = $parcel->getAttributes();
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $this->restrictFlowAccount($rider, 'suspend');
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $this->assertSame($custody, DeliveryCheckpoint::lastCustody($parcel->fresh()));
        $work = RestrictionAffectedWork::where('delivery_id', $parcel->id)->latest('id')->firstOrFail();

        return [$parcel->fresh(), $rider->fresh(), $work];
    }

    private function authorize(Delivery $parcel, RestrictionAffectedWork $work): CustodyRecoveryGrant
    {
        $owner = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $proposal = app(RestrictedCustodyRecoveryService::class)->proposal($owner, $work);
        $input = ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'reason' => 'Return this held parcel to its original receiving facility.'];
        $this->actingAs($owner)->postJson('http://localhost/custody-recovery/work/'.$work->id, $input)->assertOk();
        $grant = CustodyRecoveryGrant::sole();
        $before = $this->snapshot($parcel);
        $this->postJson('http://localhost/custody-recovery/work/'.$work->id, $input)->assertOk()->assertJsonPath('reference', $grant->reference);
        $this->assertSame($before, $this->snapshot($parcel));
        $this->postJson('http://localhost/custody-recovery/work/'.$work->id, array_replace($input, ['reason' => 'Different authorization reason']))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));

        return $grant;
    }

    private function input(Delivery $parcel): array
    {
        return ['barcode' => $parcel->tracking_number, 'notes' => 'The original parcel is being handed over at its receiving facility.', 'request_token' => (string) Str::uuid()];
    }

    private function snapshot(Delivery $parcel): array
    {
        $result = ['parcel' => $parcel->fresh()->getAttributes(), 'order' => $parcel->order->fresh()->getAttributes()];
        foreach (['custody_recovery_grants', 'custody_recovery_receipts', 'delivery_checkpoints', 'delivery_attempts', 'cod_custody_entries'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    #[DataProvider('phases')]
    public function test_owned_acknowledgement_and_actual_original_hub_receipt_restore_only_recorded_custody(string $phase, string $host): void
    {
        [$parcel, $rider, $work] = $this->restricted($phase);
        $stock = $parcel->order->items->first()->product->stock;
        $beforeParcel = $parcel->getAttributes();
        $beforeCustody = DeliveryCheckpoint::lastCustody($parcel);
        $grant = $this->authorize($parcel, $work);
        $this->assertSame($beforeParcel, $parcel->fresh()->getAttributes());
        $hub = LogisticsHub::findOrFail($grant->hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson('http://localhost/custody-recovery/'.$grant->id.'/receipt', $this->input($parcel))->assertConflict();
        $input = $this->input($parcel);
        $this->actingAs($rider)->postJson($host.'/custody-recovery/'.$grant->id.'/handover', $input)->assertOk();
        $this->assertSame($beforeParcel, $parcel->fresh()->getAttributes());
        $this->assertSame($beforeCustody, DeliveryCheckpoint::lastCustody($parcel->fresh()));
        $snapshot = $this->snapshot($parcel);
        $this->postJson($host.'/custody-recovery/'.$grant->id.'/handover', $input)->assertOk();
        $this->assertSame($snapshot, $this->snapshot($parcel));
        $receiptInput = $this->input($parcel);
        $this->actingAs($handler)->get('http://localhost/custody-recovery/receiving')->assertOk()->assertSee($grant->reference);
        $this->postJson('http://localhost/custody-recovery/'.$grant->id.'/receipt', $receiptInput)->assertOk();
        $this->assertSame($phase === 'picked_up' ? 'arrived_at_origin_hub' : 'arrived_at_destination_hub', $parcel->fresh()->status);
        $this->assertSame('at_sorting_center', $parcel->order->fresh()->status);
        $this->assertSame($hub->id, $parcel->fresh()->current_hub_id);
        $this->assertSame('hub', DeliveryCheckpoint::lastCustody($parcel->fresh())['kind']);
        $this->assertFalse(Delivery::riderHasActiveWork($rider->id));
        $this->assertSame('suspended', $rider->fresh()->status);
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $snapshot = $this->snapshot($parcel);
        $this->postJson('http://localhost/custody-recovery/'.$grant->id.'/receipt', $receiptInput)->assertOk();
        $this->assertSame($snapshot, $this->snapshot($parcel));
        $this->postJson('http://localhost/custody-recovery/'.$grant->id.'/receipt', array_replace($receiptInput, ['notes' => 'Conflicting handover']))->assertConflict();
        $this->assertSame($snapshot, $this->snapshot($parcel));
    }

    public function test_real_recovery_sign_in_requires_owned_authority_and_does_not_open_general_work_or_native_tokens(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $this->authorize($parcel, $work);
        $this->app['auth']->forgetGuards();
        $this->post('http://courier.localhost/custody-recovery/sign-in', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('custody-recovery.own'));
        $this->get('http://courier.localhost/custody-recovery')->assertOk();
        $this->postJson('http://localhost/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'password', 'device_name' => 'Recovery fixture'])->assertForbidden();
        $this->getJson('http://localhost/courier/deliveries')->assertForbidden();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertSame('suspended', $rider->fresh()->status);
    }

    public function test_foreign_manager_platform_receiver_and_unowned_rider_cannot_change_handover(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $service = app(RestrictedCustodyRecoveryService::class);
        $owner = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $proposal = $service->proposal($owner, $work);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->createApprovedUser('logistics'))->postJson('http://localhost/custody-recovery/work/'.$work->id,
            ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(), 'reason' => 'Wrong company review'])->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
        $grant = $this->authorize($parcel, $work);
        $before = $this->snapshot($parcel);
        foreach ([$this->createApprovedUser('courier'), $owner, $this->createApprovedUser('admin')] as $actor) {
            $this->actingAs($actor)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
        $this->actingAs($rider)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertOk();
        $before = $this->snapshot($parcel);
        foreach ([$owner, $this->createApprovedUser('admin'), $this->flowHandler(LogisticsHub::findOrFail($parcel->origin_mother_hub_id))] as $actor) {
            $this->actingAs($actor)->postJson('http://localhost/custody-recovery/'.$grant->id.'/receipt', $this->input($parcel))->assertForbidden();
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_wrong_waybill_expired_authority_and_changed_restriction_preserve_original_custody(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $grant = $this->authorize($parcel, $work);
        $before = $this->snapshot($parcel);
        $this->actingAs($rider)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', array_replace($this->input($parcel), ['barcode' => $parcel->order->order_number]))->assertJsonValidationErrors('barcode');
        $this->assertSame($before, $this->snapshot($parcel));
        $this->travelTo($grant->expires_at);
        $this->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->travelBack();
        $this->restrictFlowAccount($rider, 'deactivate');
        $before = $this->snapshot($parcel);
        $this->actingAs($rider)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_receipt_audit_failure_rolls_back_assignment_state_and_all_new_handover_evidence(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $grant = $this->authorize($parcel, $work);
        $this->actingAs($rider)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertOk();
        $before = $this->snapshot($parcel);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($checkpoint) {
            if ($checkpoint->checkpoint_type === 'restricted_custody_received') {
                throw new RuntimeException('Simulated recovery audit failure');
            }
        });
        $this->expectException(RuntimeException::class);
        try {
            app(RestrictedCustodyRecoveryService::class)->receive($this->flowHandler(LogisticsHub::findOrFail($grant->hub_id)), $grant, $this->input($parcel));
        } finally {
            $this->assertSame($before, $this->snapshot($parcel));
        }
    }

    public function test_recovery_authority_and_acknowledgement_reject_direct_sql_mutation(): void
    {
        [$parcel, $rider, $work] = $this->restricted();
        $grant = $this->authorize($parcel, $work);
        $this->actingAs($rider)->postJson('http://localhost/custody-recovery/'.$grant->id.'/handover', $this->input($parcel))->assertOk();
        $before = $this->snapshot($parcel);
        foreach (['custody_recovery_grants', 'custody_recovery_receipts'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    DB::transaction(fn () => $action === 'update' ? DB::table($table)->update(['created_at' => now()->subDay()]) : DB::table($table)->delete());
                    $this->fail('Recovery evidence changed.');
                } catch (Throwable $error) {
                    $this->assertStringContainsString('immutable', $error->getMessage());
                }
            }
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_suspended_account_without_authority_cannot_use_recovery_sign_in(): void
    {
        [$parcel, $rider] = $this->restricted();
        $before = $this->snapshot($parcel);
        $this->app['auth']->forgetGuards();
        $this->post('http://courier.localhost/custody-recovery/sign-in', ['email' => $rider->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_assignment_without_actual_pickup_cannot_be_presented_as_recoverable_custody(): void
    {
        [$parcel, , $work] = $this->restricted('assigned_pickup');
        $before = $this->snapshot($parcel);
        $owner = $parcel->company->user;
        $this->actingAs($owner)->postJson('http://localhost/custody-recovery/work/'.$work->id,
            ['source_token' => str_repeat('0', 64), 'request_token' => (string) Str::uuid(), 'reason' => 'This claim has no physical handover evidence.'])->assertConflict();
        $this->assertSame($before, $this->snapshot($parcel));
        $this->assertDatabaseCount('custody_recovery_grants', 0);
    }
}
