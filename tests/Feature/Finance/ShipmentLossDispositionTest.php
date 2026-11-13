<?php

namespace Tests\Feature\Finance;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Wallet\WalletLossReserve;
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

class ShipmentLossDispositionTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function reviewed(string $mode = 'wallet', bool $return = false): array
    {
        [$source, $admin, $order, $case] = $this->missing($mode, $return);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('independent-loss-review.png'));

        return [$source, $plan, $admin, $order, $case];
    }

    private function dispositionInput(ShipmentFinancialPlan $plan, array $extra = []): array
    {
        return ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'The independent admin checked the original missing typed parcel and actual investigation evidence; no receipt or arrival is asserted.',
            'disposition_confirmed' => true, ...$extra];
    }

    private function physical(ShipmentFinancialPlan $plan): array
    {
        $order = $plan->order->fresh(['delivery']);
        $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
        $member = $plan->buyer_return_case_id ? $manifest->buyerReturnParcels()->sole() : $manifest->parcels()->whereKey($plan->snapshot['source']['member_id'])->firstOrFail();
        $case = $plan->buyer_return_case_id ? $member->parcel->returnCase : null;

        return [$order->getAttributes(), $order->delivery->getAttributes(), $order->delivery->checkpoints()->get()->map->getAttributes()->all(),
            $manifest->getAttributes(), $member->getAttributes(), $case?->getAttributes(), $case?->parcel->getAttributes(),
            LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists(), WalletTransaction::count(),
            DB::table('mother_bags')->orderBy('id')->get()->map(fn ($bag) => (array) $bag)->all()];
    }

    public static function sources(): array
    {
        return [['wallet', false], ['cod', false], ['wallet', true], ['cod', true]];
    }

    #[DataProvider('sources')]
    public function test_confirmed_loss_retains_the_exact_typed_member_without_receipt_arrival_custody_money_or_successful_sale_changes(string $mode, bool $return): void
    {
        [$source, $plan, $admin] = $this->reviewed($mode, $return);
        $before = $this->physical($plan);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('actual-disposition-evidence.png');
        $service = app(ShipmentLossDispositionService::class);
        $disposition = $service->record($admin, $plan, $input, $proof);
        $this->assertSame($disposition->id, $service->record($admin, $plan, $input, $proof)->id);
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertSame($before, $this->physical($plan));
        $this->assertSame($plan->snapshot, $disposition->financial_snapshot);
        $this->assertSame($plan->snapshot['source']['physical_state'], $disposition->physical_snapshot);
        $this->assertSame($return ? null : $plan->snapshot['source']['member_id'], $disposition->manifest_parcel_id);
        $this->assertSame($return ? $plan->snapshot['source']['member_id'] : null, $disposition->buyer_return_manifest_parcel_id);
        $this->assertSame($source->id, $disposition->loss_observation_id);
        $this->assertTrue(Storage::disk('local')->exists($disposition->proof_path));
        $this->assertFalse(Storage::disk('public')->exists($disposition->proof_path));
        $this->assertArrayNotHasKey('proof_path', $disposition->toArray());
        $this->assertArrayNotHasKey('financial_snapshot', $disposition->toArray());
        $this->assertDatabaseCount('shipment_loss_dispositions', 1);
        $event = $plan->events()->where('event_type', 'loss-disposition-recorded')->sole();
        $this->assertSame(0, $event->amount_cents);
        $this->assertNull($event->wallet_transaction_id);
        $this->assertTrue($event->context['no_physical_receipt']);
        $this->assertTrue($event->context['no_transport_arrival']);
        $this->assertSame('dispatched', $source->manifest->fresh()->status);
        $this->assertFalse($source->manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received'])->exists());
    }

    public function test_a_real_failed_delivery_rts_missing_member_has_its_own_disposition_without_finishing_the_reverse_route(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
        $manifest = LogisticsManifest::where('direction', 'return')->where('status', 'dispatched')->sole();
        $member = $manifest->parcels()->where('delivery_id', $parcel->id)->sole();
        $handler = $this->flowHandler($manifest->destinationHub);
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The original expected RTS parcel remains missing on this actual departed reverse transport.']))->assertOk();
        $source = $manifest->events()->where('event_type', 'report-discrepancy')->sole();
        $admin = $this->createApprovedUser('admin');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('actual-rts-investigation.png'));
        $before = $this->physical($plan);
        $disposition = app(ShipmentLossDispositionService::class)->record($admin, $plan, $this->dispositionInput($plan), UploadedFile::fake()->image('rts-loss-evidence.png'));
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertSame('return', $disposition->financial_snapshot['source']['direction']);
        $this->assertSame($before, $this->physical($plan));
        $this->assertSame('delivery_failed', $order->fresh()->status);
        $this->assertSame('return_in_transit', $parcel->fresh()->status);
    }

    public function test_current_admin_original_version_bounded_private_proof_and_fixed_server_identity_are_required(): void
    {
        [, $plan, $admin, $order] = $this->reviewed();
        $service = app(ShipmentLossDispositionService::class);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('actual-disposition.png');
        foreach ([$order->buyer, $order->shop->user, LogisticsCompany::findOrFail($plan->logistics_company_id)->user] as $other) {
            try {
                $service->record($other, $plan, $input, $proof);
                $this->fail('An operational source actor cannot impersonate an independent admin.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        foreach ([['reason' => 'short'], ['reason' => str_repeat('A', 1001)], ['expected_version' => '01'], ['disposition_confirmed' => false],
            ['manifest_parcel_id' => $plan->snapshot['source']['member_id']], ['received' => true], ['arrival_confirmed' => true], ['amount' => '1.00']] as $change) {
            try {
                $service->record($admin, $plan, [...$input, ...$change], $proof);
                $this->fail('Invalid loss evidence cannot alter its original server-owned authority.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach ([UploadedFile::fake()->create('invalid.txt', 1, 'text/plain'), UploadedFile::fake()->create('too-big.png', 5121, 'image/png')] as $invalid) {
            try {
                $service->record($admin, $plan, $input, $invalid);
                $this->fail('Loss evidence must contain a real bounded supported private file.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('proof', $error->errors());
            }
        }
        try {
            $service->record($admin, $plan, [...$input, 'expected_version' => 99], $proof);
            $this->fail('A stale original financial version cannot record a disposition.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        User::whereKey($admin->id)->update(['status' => 'suspended']);
        try {
            $service->record($admin, $plan, $input, $proof);
            $this->fail('A cached admin cannot bypass current account eligibility.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('shipment_loss_dispositions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-loss-dispositions'));
    }

    #[DataProvider('receiptScopes')]
    public function test_later_real_receipt_invalidates_the_disposition_source_but_exact_record_retry_keeps_its_history(bool $return): void
    {
        [$source, $plan, $admin] = $this->reviewed(return: $return);
        $service = app(ShipmentLossDispositionService::class);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('original-disposition.png');
        $disposition = $service->record($admin, $plan, $input, $proof);
        $manifest = $source->manifest->fresh();
        $handler = $this->flowHandler($manifest->destinationHub);
        if (! $return) {
            $this->openFlowMotherBags($manifest, $handler);
        }
        $this->actingAs($handler)->postJson('/hub/manifests/'.$manifest->id.'/receive',
            $this->flowManifestPayload($manifest, ['barcode' => $plan->snapshot['source']['barcode']]))->assertOk();
        $this->assertSame($disposition->id, $service->record($admin, $plan, $input, $proof)->id);
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('A retained disposition cannot substitute for a later actual receipt or changed holder.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $count = WalletTransaction::count();
        try {
            app(ShipmentLossWallet::class)->command($admin, $plan, $return ? 'approve-advance' : 'reserve-original', [
                'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
                'reason' => 'A later physical receipt must stop new credits until its original loss disposition is independently reconciled.',
                ...($return ? ['demo_advance_confirmed' => true, 'amount' => '1.00'] : ['original_reserve_confirmed' => true]),
            ]);
            $this->fail('A real receipt must block new loss money even when the original disposition remains retained.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($count, WalletTransaction::count());
    }

    public static function receiptScopes(): array
    {
        return [[false], [true]];
    }

    public function test_existing_original_wallet_reserve_and_new_disposition_remain_independent_and_later_funding_uses_the_same_approved_source(): void
    {
        [, $plan, $admin] = $this->reviewed();
        $wallet = app(ShipmentLossWallet::class);
        $wallet->command($admin, $plan, 'reserve-original', ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'Reserve only the original actual buyer credits before the separate operational disposition.', 'original_reserve_confirmed' => true]);
        $before = app(WalletLossReserve::class)->summary($plan);
        $count = WalletTransaction::count();
        app(ShipmentLossDispositionService::class)->record($admin, $plan, $this->dispositionInput($plan), UploadedFile::fake()->image('funded-source-disposition.png'));
        $this->assertSame($before, app(WalletLossReserve::class)->summary($plan));
        $this->assertSame($count, WalletTransaction::count());
        $wallet->command($admin, $plan, 'approve-advance', ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'amount' => SellerSettlementService::pesos($plan->snapshot['company_funding_due_cents']),
            'reason' => 'Independently approve only the remaining explicit demonstration funding against the same unchanged source.', 'demo_advance_confirmed' => true]);
        $wallet->command($admin, $plan, 'resolve-wallet', ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'reason' => 'The separate disposition is not a receipt; original balanced funding alone pays the approved loss recipients.', 'loss_settlement_confirmed' => true]);
        $this->assertTrue(app(WalletLossReserve::class)->summary($plan)['resolved']);
        $this->assertSame('dispatched', LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id'])->status);
        $this->assertDatabaseCount('shipment_loss_dispositions', 1);
    }

    public function test_disposition_identity_is_immutable_and_a_changed_retry_or_missing_private_proof_cannot_authorize_new_money(): void
    {
        [, $plan, $admin] = $this->reviewed();
        $service = app(ShipmentLossDispositionService::class);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('retained-disposition.png');
        $disposition = $service->record($admin, $plan, $input, $proof);
        foreach ([['reason' => 'Replace the original operational disposition reason.'], ['request_token' => (string) Str::uuid()]] as $change) {
            try {
                $service->record($admin, $plan, [...$input, ...$change], $proof);
                $this->fail('Only the exact original disposition request may replay its retained result.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        try {
            DB::table('shipment_loss_dispositions')->where('id', $disposition->id)->update(['manifest_parcel_id' => null]);
            $this->fail('Raw SQL cannot change an original typed loss disposition.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        try {
            DB::table('shipment_loss_dispositions')->where('id', $disposition->id)->delete();
            $this->fail('Raw SQL cannot delete retained operational loss evidence.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        Storage::disk('local')->put($disposition->proof_path, 'Changed bytes are not the retained private investigation.');
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('Changed or missing private disposition evidence cannot authorize new loss funding.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_even_a_zero_money_disposition_prevents_service_model_and_raw_sql_from_voiding_its_original_active_authority(): void
    {
        [, $plan, $admin] = $this->reviewed();
        app(ShipmentLossDispositionService::class)->record($admin, $plan, $this->dispositionInput($plan), UploadedFile::fake()->image('unfunded-disposition.png'));
        $before = $plan->fresh()->getAttributes();
        try {
            app(ShipmentLossReview::class)->voidUnfunded($admin, $plan, ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'unfunded_void_confirmed' => true,
                'reason' => 'An operational disposition cannot be erased merely because its separate financial funding is still pending.']);
            $this->fail('An independently recorded disposition retains its original active authority even without money.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        // Adversarial retained-event fixture: the older reason-only zero-money void rule must still be insufficient.
        DB::transaction(fn () => ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id,
            'event_type' => 'voided', 'sequence' => (int) $plan->events()->max('sequence') + 1, 'amount_cents' => 0,
            'reason' => 'A zero-money void label cannot replace the independently recorded physical loss authority.',
            'context' => ['no_money_moved' => true], 'request_token_hash' => hash('sha256', (string) Str::uuid()),
            'request_fingerprint' => hash('sha256', 'adversarial-zero-money-void'), 'created_at' => now()]));
        try {
            DB::transaction(fn () => $plan->update(['active_order_id' => null, 'active_return_case_id' => null]));
            $this->fail('The model cannot release a disposed claim against a zero-money void label.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        try {
            DB::table('shipment_financial_plans')->where('id', $plan->id)->update(['active_order_id' => null, 'active_return_case_id' => null]);
            $this->fail('The database cannot release a disposed claim against a zero-money void label.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('loss disposition', $error->getMessage());
        }
        $this->assertSame($before, $plan->fresh()->getAttributes());
        $this->assertDatabaseCount('shipment_loss_dispositions', 1);
    }

    public function test_missing_original_independent_review_proof_blocks_new_money_even_when_the_disposition_proof_is_intact(): void
    {
        [, $plan, $admin] = $this->reviewed();
        $service = app(ShipmentLossDispositionService::class);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('intact-disposition-proof.png');
        $disposition = $service->record($admin, $plan, $input, $proof);
        Storage::disk('local')->delete($plan->proof_path);
        $this->assertTrue(Storage::disk('local')->exists($disposition->proof_path));
        $this->assertSame($disposition->id, $service->record($admin, $plan, $input, $proof)->id);
        $count = WalletTransaction::count();
        try {
            app(ShipmentLossSource::class)->assertApproved($plan);
            $this->fail('A later disposition cannot replace missing original independent investigation evidence.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            app(ShipmentLossWallet::class)->command($admin, $plan, 'reserve-original', [
                'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
                'reason' => 'New money requires both the original independent investigation and any later operational disposition evidence.',
                'original_reserve_confirmed' => true,
            ]);
            $this->fail('A missing original review file cannot authorize new loss credits.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($count, WalletTransaction::count());
    }

    public function test_failed_disposition_history_rolls_back_its_record_and_private_file_then_allows_the_exact_retry(): void
    {
        [, $plan, $admin] = $this->reviewed();
        $before = $this->physical($plan);
        $input = $this->dispositionInput($plan);
        $proof = UploadedFile::fake()->image('rollback-disposition.png');
        $fail = true;
        Event::listen('eloquent.creating: '.ShipmentFinancialEvent::class, function (ShipmentFinancialEvent $event) use (&$fail) {
            if ($event->event_type === 'loss-disposition-recorded' && $fail) {
                $fail = false;
                throw new \RuntimeException('Simulated loss-disposition history persistence failure.');
            }
        });
        $service = app(ShipmentLossDispositionService::class);
        try {
            $service->record($admin, $plan, $input, $proof);
            $this->fail('An incomplete disposition must not leave a retained file or partial authority.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated loss-disposition history persistence failure.', $error->getMessage());
        }
        $this->assertDatabaseCount('shipment_loss_dispositions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-loss-dispositions'));
        $this->assertSame($before, $this->physical($plan));
        $disposition = $service->record($admin, $plan, $input, $proof);
        $this->assertSame($disposition->id, $service->record($admin, $plan, $input, $proof)->id);
        app(ShipmentLossSource::class)->assertApproved($plan);
        $this->assertDatabaseCount('shipment_loss_dispositions', 1);
    }
}
