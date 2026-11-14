<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\WholeTransportLossConfirmation;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Logistics\WholeTransportLossService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class SensitiveHistoryEncryptionTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private const ORIGINS = [
        'http://preview.example.test' => false,
        'http://localhost' => true,
        'http://hub.localhost' => true,
        'https://preview.example.test' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public function test_sensitive_financial_return_and_security_queues_encrypt_history_only_on_supported_origins(): void
    {
        $admin = $this->createApprovedUser('admin');
        $buyer = $this->createApprovedUser('buyer');

        $adminPages = [
            '/account/security' => 'Account/Security',
            '/return-liabilities' => 'Returns/Liabilities',
            '/return-liabilities?view=shipments' => 'Returns/Liabilities',
            '/return-liabilities?view=losses' => 'Finance/LossQueue',
            '/wallet/proceeds' => 'Finance/WalletProceeds',
            '/wallet/admin' => 'Admin/WalletCredits',
            '/seller-settlements' => 'Finance/Settlements',
            '/exceptions' => 'Governance/Exceptions',
            '/cash-handover' => 'Finance/Cod',
            '/financial-oversight' => 'Finance/Overview',
            '/handover-issues' => 'Handover/Index',
            '/delivery-earnings' => 'Finance/DeliveryEarnings',
        ];

        $this->actingAs($admin);
        foreach ($adminPages as $path => $component) {
            foreach (self::ORIGINS as $origin => $encrypted) {
                $this->get($origin.$path, $this->inertia())
                    ->assertOk()
                    ->assertJsonPath('component', $component)
                    ->assertJsonPath('encryptHistory', $encrypted);
            }
        }

        $this->actingAs($buyer);
        foreach (self::ORIGINS as $origin => $encrypted) {
            $this->get($origin.'/buyer-returns', $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Returns/Index')
                ->assertJsonPath('encryptHistory', $encrypted);
        }
    }

    public function test_sensitive_loss_return_intake_and_admin_whole_transport_manifest_pages_encrypt_history_by_origin(): void
    {
        [$source, $admin, $order, $case] = $this->missing('wallet', true);
        $manifest = LogisticsManifest::findOrFail($source->manifest_id);

        // Before an admin whole-transport-loss confirmation exists, ordinary manifest rendering does not encrypt history.
        $this->actingAs($admin)
            ->get('http://localhost/hub/manifests/'.$manifest->id, $this->inertia())
            ->assertOk()
            ->assertJsonPath('component', 'Hub/Manifests')
            ->assertJsonPath('encryptHistory', false);

        // Loss review page before approval
        foreach (self::ORIGINS as $origin => $encrypted) {
            $this->actingAs($admin)
                ->get($origin.'/shipment-finances/missing/'.$source->id.'/review', $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Finance/LossReview')
                ->assertJsonPath('encryptHistory', $encrypted);
        }

        $plan = app(ShipmentLossReview::class)->approve(
            $admin,
            $source,
            $this->approval($source, $admin, '0.00'),
            UploadedFile::fake()->image('loss-review-proof.png')
        );

        $handler = $this->flowHandler(LogisticsHub::findOrFail($source->hub_id));
        foreach (self::ORIGINS as $origin => $encrypted) {
            $this->actingAs($admin)
                ->get($origin.'/shipment-finances/'.$plan->id, $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Finance/LossOutcome')
                ->assertJsonPath('encryptHistory', $encrypted);

            $this->actingAs($handler)
                ->get($origin.'/loss-found/'.$plan->id.'?hub_id='.$source->hub_id, $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Hub/LossFoundIntake')
                ->assertJsonPath('encryptHistory', $encrypted);
        }

        // Buyer return show, liability detail, and return creation pages
        foreach (self::ORIGINS as $origin => $encrypted) {
            $this->actingAs($order->buyer)
                ->get($origin.'/buyer-returns/'.$case->id, $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Returns/Show')
                ->assertJsonPath('encryptHistory', $encrypted);

            $this->actingAs($admin)
                ->get($origin.'/return-liabilities/'.$case->id, $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Returns/Liability')
                ->assertJsonPath('encryptHistory', $encrypted);
        }

        // Manifest page encrypts history for admins when private whole-transport-loss investigation notes are exposed
        Storage::disk('local')->put('whole-transport-losses/evidence.png', 'proof-bytes');
        $proofPath = Storage::disk('local')->path('whole-transport-losses/evidence.png');
        $confirmation = WholeTransportLossConfirmation::create([
            'scope_kind' => 'whole_transport',
            'logistics_company_id' => $manifest->logistics_company_id,
            'trip_id' => null,
            'manifest_id' => $manifest->id,
            'actor_id' => $admin->id,
            'driver_id' => $manifest->driver_id,
            'vehicle_id' => $manifest->vehicle_id,
            'manifest_version' => $manifest->version,
            'trip_version' => null,
            'reason' => 'Private admin whole-transport investigation notes.',
            'proof_path' => 'whole-transport-losses/evidence.png',
            'proof_mime' => 'image/png',
            'proof_bytes' => Storage::disk('local')->size('whole-transport-losses/evidence.png'),
            'proof_hash' => hash_file('sha256', $proofPath),
            'request_token_hash' => hash('sha256', (string) Str::uuid()),
            'request_fingerprint' => hash('sha256', Str::random(16)),
            'created_at' => now(),
        ]);
        DB::table('whole_transport_loss_manifests')->insert([
            'whole_transport_loss_confirmation_id' => $confirmation->id,
            'manifest_id' => $manifest->id,
            'trip_id' => null,
            'whole_transport_key' => 'manifest:'.$manifest->id,
        ]);
        $manifest->events()->create([
            'manifest_parcel_id' => null,
            'actor_id' => $admin->id,
            'hub_id' => $manifest->destination_hub_id,
            'event_type' => 'whole-transport-loss',
            'barcode_scanned' => null,
            'reason' => WholeTransportLossService::FLOOR_REASON,
            'source_state' => $manifest->state(),
            'target_state' => $manifest->state(),
            'payload' => ['confirmation_reference' => $confirmation->reference, 'scope_kind' => 'whole_transport', 'trip_id' => null, 'lost_bag_ids' => []],
            'request_token' => (string) Str::uuid(),
            'request_fingerprint' => $confirmation->request_fingerprint,
        ]);

        foreach (self::ORIGINS as $origin => $encrypted) {
            $this->actingAs($admin)
                ->get($origin.'/hub/manifests/'.$manifest->id, $this->inertia())
                ->assertOk()
                ->assertJsonPath('component', 'Hub/Manifests')
                ->assertJsonPath('props.selectedManifest.wholeTransportLoss.record.reason', 'Private admin whole-transport investigation notes.')
                ->assertJsonPath('encryptHistory', $encrypted);
        }

        $this->actingAs($handler)
            ->get('http://localhost/hub/manifests/'.$manifest->id, $this->inertia())
            ->assertOk()
            ->assertJsonPath('component', 'Hub/Manifests')
            ->assertJsonPath('props.selectedManifest.wholeTransportLoss.record.reason', null)
            ->assertJsonPath('encryptHistory', false);
    }
}
