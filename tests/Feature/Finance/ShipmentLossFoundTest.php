<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsManifest;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossCustody;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Logistics\HubParcelWorkstationService;
use App\Services\Orders\OrderTrackingService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossFoundTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function scopes(): array
    {
        return [[false], [true]];
    }

    private function reviewed(bool $return = false, string $mode = 'wallet'): array
    {
        [$source, $admin, $order, $case] = $this->missing($mode, $return);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('original-loss-review.png'));

        return [$source, $plan, $admin, $order, $case];
    }

    private function intakeInput(ShipmentFinancialPlan $plan, array $extra = []): array
    {
        return ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'barcode' => $plan->snapshot['source']['barcode'], 'reason' => 'The actual original parcel was found and placed in this named hub quarantine pending independent review.',
            'quarantine_confirmed' => true, ...$extra];
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    #[DataProvider('scopes')]
    public function test_actual_scanner_opens_a_private_floor_intake_without_any_money_or_customer_projection(bool $return): void
    {
        [$source, $plan, $admin, $order] = $this->reviewed($return);
        $hub = $source->manifest->destinationHub;
        $handler = $this->flowHandler($hub);
        $url = '/loss-found/'.$plan->id;
        $input = $this->intakeInput($plan);
        $this->actingAs($handler)->postJson('/hub/scan', ['hub_id' => $hub->id, 'mode' => 'inspect', 'barcode' => $input['barcode']])
            ->assertStatus(409)->assertJsonPath('recovery_url', $url.'?hub_id='.$hub->id);
        $view = $this->get($url.'?hub_id='.$hub->id, $this->inertia())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('component', 'Hub/LossFoundIntake')->assertJsonPath('props.intake.can_intake', true);
        $projection = $view->json('props.intake');
        $this->assertSame(['id', 'barcode', 'manifest_reference', 'scope', 'hub', 'version', 'can_intake', 'blocker', 'finding'], array_keys($projection));
        foreach (['buyer', 'seller', 'address', 'phone', 'funding', 'amount_cents', 'proof_path', 'snapshot'] as $key) {
            $this->assertArrayNotHasKey($key, $projection);
        }
        $proof = UploadedFile::fake()->image('actual-floor-intake.png');
        $this->postJson($url, [...$input, 'hub_id' => $hub->id, 'proof' => $proof])->assertCreated();
        $this->postJson($url, [...$input, 'hub_id' => $hub->id, 'proof' => $proof])->assertCreated();
        $this->get($url.'?hub_id='.$hub->id, $this->inertia())->assertOk()->assertJsonPath('props.intake.can_intake', false)
            ->assertJsonPath('props.intake.finding.at_selected_hub', true);
        $this->get($url.'/proof?hub_id='.$hub->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $financialProof = '/shipment-finances/'.$plan->id.'/finding/proof';
        $this->get($financialProof)->assertForbidden();
        $this->actingAs($admin)->get($financialProof)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        foreach ([$admin, $order->buyer, $order->shop->user] as $actor) {
            $this->actingAs($actor)->get($url.'?hub_id='.$hub->id)->assertForbidden();
            $this->get($url.'/proof?hub_id='.$hub->id)->assertForbidden();
            $this->postJson($url, [...$input, 'hub_id' => $hub->id, 'proof' => $proof])->assertForbidden();
        }
        $this->actingAs($order->buyer)->get($financialProof)->assertForbidden();
        $this->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.finding', null);
        $this->actingAs($admin)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()->assertJsonPath('props.loss.controls.finding.proof_url', $financialProof);
        $this->assertDatabaseCount('shipment_loss_findings', 1);
    }

    public function test_browser_validation_does_not_cache_private_reason_file_or_consent_and_unknown_failure_keeps_an_exact_retry(): void
    {
        [$source, $plan] = $this->reviewed();
        $hub = $source->manifest->destinationHub;
        $handler = $this->flowHandler($hub);
        $url = '/loss-found/'.$plan->id;
        $input = [...$this->intakeInput($plan), 'hub_id' => $hub->id];
        $this->actingAs($handler)->from($url.'?hub_id='.$hub->id)->post($url, [...$input, 'reason' => 'bad'])->assertRedirect()
            ->assertSessionHasErrors(['proof'])->assertSessionMissing('_old_input');
        $this->post($url, [...$input, 'reason' => 'bad', 'proof' => UploadedFile::fake()->image('actual-found.png')])->assertRedirect()
            ->assertSessionHasErrors(['reason'])->assertSessionMissing('_old_input');
        $enabled = true;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, function ($event) use (&$enabled) {
            if ($enabled && $event->event_type === 'loss-found-quarantined') {
                throw new \RuntimeException('Do not expose this internal failure text.');
            }
        });
        $proof = UploadedFile::fake()->image('actual-found.png');
        try {
            $this->postJson($url, [...$input, 'proof' => $proof])->assertStatus(503)
                ->assertJsonPath('message', 'Could not confirm this intake. Check the saved quarantine record before retrying the same update.');
        } finally {
            $enabled = false;
        }
        $this->assertDatabaseCount('shipment_loss_findings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-loss-findings'));
        $this->postJson($url, [...$input, 'proof' => $proof])->assertCreated();
        $this->assertDatabaseCount('shipment_loss_findings', 1);
    }

    public static function originalPayments(): array
    {
        return [[false, 'wallet'], [false, 'cod'], [true, 'wallet'], [true, 'cod']];
    }

    #[DataProvider('originalPayments')]
    public function test_actual_found_intake_retains_the_original_route_sale_transport_and_money_while_recording_quarantine_once(bool $return, string $mode): void
    {
        [$source, $plan, , $order, $case] = $this->reviewed($return, $mode);
        $manifest = $source->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);
        $member = $return ? $manifest->buyerReturnParcels()->sole() : $source->parcel;
        $before = [$order->fresh()->getAttributes(), $manifest->getAttributes(), $member->getAttributes(), WalletTransaction::count(),
            DB::table('mother_bags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
        $position = $case?->parcel->route_position;
        $oldStatus = $return ? $case->parcel->status : $order->delivery->status;
        $input = $this->intakeInput($plan);
        $proof = UploadedFile::fake()->image('actual-found.png');
        $service = app(ShipmentLossCustody::class);
        $finding = $service->intake($handler, $plan, $manifest->destinationHub, $input, $proof);
        $this->assertSame($finding->id, $service->intake($handler, $plan, $manifest->destinationHub, $input, $proof)->id);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $manifest->fresh()->getAttributes(), $member->fresh()->getAttributes(), WalletTransaction::count(),
            DB::table('mother_bags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()]);
        $this->assertTrue(LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists());
        $this->assertSame($plan->snapshot['source']['physical_state'], $finding->source_state);
        $this->assertSame($return ? null : $member->id, $finding->manifest_parcel_id);
        $this->assertSame($return ? $member->id : null, $finding->buyer_return_manifest_parcel_id);
        $parcel = $return ? $case->parcel->fresh() : $order->delivery->fresh();
        $custody = $return ? $parcel->custody : DeliveryCheckpoint::lastCustody($parcel);
        $this->assertSame($oldStatus, $parcel->status);
        $this->assertSame('loss_quarantine', $custody['kind']);
        $this->assertSame($finding->reference, $custody['finding_reference']);
        $this->assertSame($manifest->destination_hub_id, $parcel->current_hub_id);
        $this->assertSame($handler->id, $custody['user_id']);
        if ($return) {
            $this->assertSame($position, $parcel->route_position);
            $this->assertSame('in_transit', $case->fresh()->status);
            $this->assertSame(1, $case->events()->where('event_type', 'loss-found-quarantined')->count());
        } else {
            $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'loss_found_quarantine')->count());
            $tracking = app(OrderTrackingService::class)->present($order->fresh(['delivery.latestCustodyCheckpoint', 'delivery.checkpoints']));
            $this->assertSame('Found parcel on hold', $tracking['stage_label']);
            $this->assertSame('Logistics hub quarantine (actual finding recorded)', $tracking['custodian']);
            $guidance = app(HubParcelWorkstationService::class)->guidance($parcel->fresh(['order', 'latestCustodyCheckpoint', 'currentHub']),
                $manifest->destinationHub, $handler, collect(), '/hub');
            $this->assertSame('Found parcel in quarantine', $guidance['stage']);
            $this->assertSame('/loss-found/'.$plan->id.'?hub_id='.$manifest->destination_hub_id, $guidance['href']);
        }
        $this->assertSame(1, ShipmentLossFinding::count());
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-found-quarantined')->where('amount_cents', 0)->whereNull('wallet_transaction_id')->count());
        $this->assertFalse($manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received'])->exists());
        $this->assertTrue(Storage::disk('local')->exists($finding->proof_path));
        $this->assertFalse(Storage::disk('public')->exists($finding->proof_path));
        foreach (['proof_path', 'proof_hash', 'source_state', 'custody_after', 'request_token_hash'] as $key) {
            $this->assertArrayNotHasKey($key, $finding->toArray());
        }
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('Actual finding must hold new compensation until independent reconciliation.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            $service->assertMovable($parcel);
            $this->fail('Quarantine cannot use ordinary handling before financial reconciliation.');
        } catch (DomainException $error) {
            $this->assertSame(ShipmentLossCustody::HOLD, $error->getMessage());
        }
    }

    public function test_only_actual_named_floor_handler_exact_waybill_current_version_and_private_bounded_evidence_can_intake(): void
    {
        [$source, $plan, $admin, $order] = $this->reviewed();
        $hub = $source->manifest->destinationHub;
        $handler = $this->flowHandler($hub);
        $service = app(ShipmentLossCustody::class);
        $input = $this->intakeInput($plan);
        $proof = UploadedFile::fake()->image('found.png');
        foreach ([$admin, $order->buyer, $order->shop->user, $this->createApprovedUser('logistics')] as $actor) {
            try {
                $service->intake($actor, $plan, $hub, $input, $proof);
                $this->fail('Another role or unassigned logistics account cannot impersonate the actual holder.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        foreach ([['barcode' => $order->order_number], ['barcode' => 'BRT-ANOTHER-PARCEL'], ['expected_version' => 999]] as $change) {
            try {
                $service->intake($handler, $plan, $hub, [...$input, ...$change], $proof);
                $this->fail('Wrong actual barcode and stale authority cannot record intake.');
            } catch (HttpException $error) {
                $this->assertContains($error->getStatusCode(), [409, 422]);
            }
        }
        foreach ([['expected_version' => '01'], ['reason' => 'short'], ['reason' => str_repeat('A', 1001)],
            ['quarantine_confirmed' => false], ['arrival_confirmed' => true], ['custody' => ['kind' => 'hub']]] as $change) {
            try {
                $service->intake($handler, $plan, $hub, [...$input, ...$change], $proof);
                $this->fail('Malformed or client-asserted custody cannot record an actual intake.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach ([UploadedFile::fake()->create('empty.png', 0, 'image/png'), UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
            UploadedFile::fake()->createWithContent('pretend.png', 'not a real image')] as $badProof) {
            try {
                $service->intake($handler, $plan, $hub, $input, $badProof);
                $this->fail('Private evidence requires real bounded content.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('proof', $error->errors());
            }
        }
        $this->assertDatabaseCount('shipment_loss_findings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-loss-findings'));
        $found = $service->intake($handler, $plan, $hub, $input, $proof);
        foreach ([['request_token' => (string) Str::uuid(), 'expected_version' => 2], ['reason' => 'Changed reason with the same request identity.']] as $change) {
            try {
                $service->intake($handler, $plan, $hub, [...$input, ...$change], $proof);
                $this->fail('A second or changed intake cannot replace retained quarantine.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertSame($found->id, $service->intake($handler, $plan, $hub, $input, $proof)->id);
    }

    public function test_failed_parent_event_rolls_back_actual_custody_and_private_file_then_exact_retry_succeeds(): void
    {
        [$source, $plan, , $order] = $this->reviewed();
        $hub = $source->manifest->destinationHub;
        $handler = $this->flowHandler($hub);
        $input = $this->intakeInput($plan);
        $proof = UploadedFile::fake()->image('found.png');
        $before = [$order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->count(), $plan->events()->count()];
        $enabled = true;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, function ($event) use (&$enabled) {
            if ($enabled && $event->event_type === 'loss-found-quarantined') {
                throw new \RuntimeException('Simulated retained finding event failure.');
            }
        });
        try {
            app(ShipmentLossCustody::class)->intake($handler, $plan, $hub, $input, $proof);
            $this->fail('The actual failed event must roll back all custody changes.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated retained finding event failure.', $error->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertSame($before, [$order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->count(), $plan->events()->count()]);
        $this->assertDatabaseCount('shipment_loss_findings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-loss-findings'));
        $this->assertNotNull(app(ShipmentLossCustody::class)->intake($handler, $plan, $hub, $input, $proof)->id);
    }

    public function test_raw_database_cannot_rewrite_or_delete_an_actual_finding_and_financial_void_does_not_release_its_property(): void
    {
        [, $plan, $admin, $order] = $this->reviewed();
        $finding = $this->quarantineMissing($plan);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::table('shipment_loss_findings')->where('id', $finding->id)->{$operation}(...($operation === 'update' ? [['reason' => 'Erased actual custody.']] : []));
                $this->fail('Raw writes must retain actual found custody.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Retain the actual found parcel', $error->getMessage());
            }
        }
        app(ShipmentLossReview::class)->voidUnfunded($admin, $plan, ['expected_version' => 2, 'request_token' => (string) Str::uuid(),
            'reason' => 'The actual original parcel was found before any money or independent operational disposition was recorded.', 'unfunded_void_confirmed' => true]);
        $this->assertNull($plan->fresh()->active_order_id);
        $this->assertSame($plan->id, app(ShipmentLossCustody::class)->authority($order->delivery)->id);
        $this->assertSame('loss_quarantine', DeliveryCheckpoint::lastCustody($order->delivery)['kind']);
    }

    #[DataProvider('scopes')]
    public function test_an_approved_loss_cannot_resume_through_an_ordinary_manifest_receipt(bool $return): void
    {
        [$source, $admin, $order, $case] = $this->missing(buyerReturn: $return);
        app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('independent-loss.png'));
        $manifest = $source->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);
        if (! $return) {
            $this->openFlowMotherBags($manifest, $handler);
        }
        $before = $return ? $case->parcel->fresh()->state() : $order->delivery->fresh()->getAttributes();
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest,
            ['barcode' => $return ? $case->parcel->barcode : $order->delivery->tracking_number]))->assertStatus(409);
        $this->assertSame($before, $return ? $case->parcel->fresh()->state() : $order->delivery->fresh()->getAttributes());
        $this->assertFalse($manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received'])->exists());
    }
}
