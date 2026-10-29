<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\HandoverIssue;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\HandoverHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class NewEraHandoverRecoveryTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function localParcel(string $mode): Delivery
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $seller->shop;
        $product = $this->createE2EProduct($shop, ['price' => '1000.00']);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 1]], shipping: [
            'shipping_city' => $shop->city, 'shipping_postal_code' => '4030',
        ]);
        $parcel = $this->flowDelivery($order, $mode === 'hub_failed_return' ? 'out_for_delivery' : 'picked_up');
        if ($mode === 'hub_failed_return') {
            $this->reportFlowFailure($parcel);
        }

        return $parcel->fresh()->load('order');
    }

    private function reviewed(Delivery $parcel, User $holder, User $reviewer): HandoverIssue
    {
        $this->actingAs($holder)->postJson('/handover-issues', ['barcode' => $parcel->tracking_number, 'kind' => 'missed_scan',
            'reported_holder' => 'The original returning rider', 'reported_location' => 'The original local Bayan Hub',
            'notes' => 'The actual parcel is presented at its original local hub after a missed handover scan.',
            'evidence' => UploadedFile::fake()->image('local-handover.png'), 'request_token' => (string) Str::uuid()])->assertOk();
        $issue = HandoverIssue::where('active_delivery_id', $parcel->id)->sole();
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $this->postJson('/handover-issues/'.$issue->id.'/review', ['decision' => 'confirm', 'evidence_reviewed' => true,
            'notes' => 'Independent review confirms the original parcel, known rider and missed local handover scan.',
            'expected_version' => $view['version'], 'source_token' => $view['source_token'], 'request_token' => (string) Str::uuid()])->assertOk();

        return $issue->fresh()->load('delivery.order');
    }

    private function receiptPayload(HandoverIssue $issue, User $actor): array
    {
        $view = $this->actingAs($actor)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');

        return ['expected_version' => $view['version'], 'source_token' => $view['hub_recovery']['grant']['source_token'],
            'barcode' => $issue->delivery->tracking_number, 'possession_verified' => true,
            'notes' => 'The named actor physically checked this original parcel at its authorized local receiving hub.',
            'request_token' => (string) Str::uuid()];
    }

    public static function localSources(): array
    {
        return ['local pickup intake' => ['hub_intake'], 'local failed-attempt return' => ['hub_failed_return']];
    }

    #[DataProvider('localSources')]
    public function test_reviewed_local_handover_receipts_preserve_the_original_bypass_and_payment(string $mode): void
    {
        $parcel = $this->localParcel($mode);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $holder = User::findOrFail($mode === 'hub_failed_return' ? $parcel->assigned_rider_id : $parcel->courier_id);
        $reviewer = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $originalQuote = $parcel->order->shipping_quote;
        $originalPayment = $parcel->order->payment_status;
        $issue = $this->reviewed($parcel, $holder, $reviewer);
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $option = collect($view['hub_recovery']['options'])->firstWhere('mode', $mode);
        $this->assertNotNull($option, 'A saved single-hub route needs its original local recovery option.');
        $grant = $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', ['mode' => $mode, 'reference_id' => null,
            'holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true,
            'notes' => 'Independent review confirms the original rider and original local receiving hub.',
            'expected_version' => $view['version'], 'source_token' => $option['source_token'], 'request_token' => (string) Str::uuid()])->assertOk()->json('id');
        $acknowledgement = $this->receiptPayload($issue, $holder);
        $this->postJson('/handover-issues/recovery/'.$grant.'/acknowledge', $acknowledgement)->assertOk();
        $receipt = $this->receiptPayload($issue, $this->flowHandler($hub));
        $this->postJson('/handover-issues/recovery/'.$grant.'/receive', $receipt)->assertOk();
        $this->postJson('/handover-issues/recovery/'.$grant.'/receive', $receipt)->assertOk();
        $parcel->refresh()->load('order');
        $this->assertFalse(app(HandoverHoldService::class)->held($parcel));
        $this->assertSame($hub->id, $parcel->current_hub_id);
        $this->assertSame($mode === 'hub_failed_return' ? 'delivery_failed' : 'arrived_at_destination_hub', $parcel->status);
        $this->assertTrue($parcel->same_hub_bypass);
        $this->assertSame([$hub->id], $parcel->route_hub_ids);
        $this->assertNull($parcel->origin_mother_hub_id);
        $this->assertNull($parcel->destination_mother_hub_id);
        $this->assertSame($originalQuote, $parcel->order->shipping_quote);
        $this->assertSame($originalPayment, $parcel->order->payment_status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('logistics_manifests', 0);
        $this->assertDatabaseCount('handover_recovery_grants', 1);
    }

    public static function changedLocalSources(): array
    {
        return ['different destination child' => ['destination'], 'unfrozen extra hop' => ['path'],
            'different purchased quote' => ['quote'], 'mother binding added to a local route' => ['mother'],
            'terminal order' => ['terminal']];
    }

    #[DataProvider('changedLocalSources')]
    public function test_local_recovery_rejects_changed_routes_quotes_and_terminal_outcomes(string $change): void
    {
        $parcel = $this->localParcel('hub_intake');
        $holder = User::findOrFail($parcel->courier_id);
        $reviewer = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user;
        $issue = $this->reviewed($parcel, $holder, $reviewer);
        $view = $this->actingAs($reviewer)->get('/handover-issues/'.$issue->id)->assertOk()->inertiaProps('issue');
        $option = collect($view['hub_recovery']['options'])->firstWhere('mode', 'hub_intake');
        $this->assertNotNull($option);
        $this->flowNetwork($parcel->order->shop);
        $other = LogisticsHub::where('logistics_company_id', $parcel->logistics_company_id)->where('tier', 'local_bayan_hub')
            ->whereKeyNot($parcel->origin_bayan_hub_id)->firstOrFail();
        match ($change) {
            'destination' => $parcel->update(['destination_bayan_hub_id' => $other->id]),
            'path' => $parcel->update(['route_hub_ids' => [$parcel->origin_bayan_hub_id, $other->id]]),
            'quote' => $parcel->order->update(['shipping_quote' => [...$parcel->order->shipping_quote, 'base_fee_cents' => 9999]]),
            'mother' => $parcel->update(['origin_mother_hub_id' => LogisticsHub::where('tier', 'regional_mother_hub')->sole()->id]),
            'terminal' => $parcel->order->update(['status' => 'completed']),
        };
        $this->postJson('/handover-issues/'.$issue->id.'/authorize-hub-recovery', ['mode' => 'hub_intake',
            'holder_id' => $holder->id, 'holder_verified' => true, 'evidence_reviewed' => true,
            'notes' => 'This request must not reuse the stale original local handover authorization.',
            'expected_version' => $view['version'], 'source_token' => $option['source_token'],
            'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('handover_recovery_grants', 0);
        $this->assertTrue(app(HandoverHoldService::class)->held($parcel->fresh()));
        $this->assertNull($parcel->fresh()->current_hub_id);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('logistics_manifests', 0);
    }
}
