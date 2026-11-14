<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsManifestEvent;
use App\Services\Finance\ShipmentLossAccountingService;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossAccountingPrivacyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function retainedNotes(): array
    {
        return ['current writer' => [false], 'retained earlier writer' => [true]];
    }

    #[DataProvider('retainedNotes')]
    public function test_private_admin_accounting_details_are_retained_for_review_without_being_copied_to_floor_manifest_history(bool $earlierWriter): void
    {
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        [$observation, $admin] = $this->missing('wallet', false);
        $plan = app(ShipmentLossReview::class)->approve($admin, $observation, $this->approval($observation, $admin), UploadedFile::fake()->image('private-original-review.png'));
        app(ShipmentLossDispositionService::class)->record($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'The independent original operational investigation confirms this particular missing parcel.', 'disposition_confirmed' => true], UploadedFile::fake()->image('private-operational-evidence.png'));
        $privateReason = 'Private financial review: original company funding receipt and compensation investigation details remain restricted.';
        if ($earlierWriter) {
            // Reproduce the earlier writer at insertion; retained evidence is never rewritten.
            Event::listen('eloquent.creating: '.LogisticsManifestEvent::class, static function (LogisticsManifestEvent $event) use ($privateReason) {
                if ($event->event_type === 'loss-accounted') {
                    $event->reason = $privateReason;
                }
            });
        }
        $record = app(ShipmentLossAccountingService::class)->record($admin, $plan, ['expected_version' => 2, 'request_token' => (string) Str::uuid(),
            'reason' => $privateReason, 'loss_accounting_confirmed' => true]);
        $this->assertSame($privateReason, $record->reason);
        $this->assertSame($privateReason, $plan->events()->where('event_type', 'loss-accounted')->sole()->reason);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
        $this->actingAs($admin)->get('/shipment-finances/'.$plan->id, $headers)->assertOk()->assertSee($privateReason, escape: false);
        $handler = $this->flowHandler($observation->manifest->destinationHub);
        $floor = $this->actingAs($handler)->get('/hub/manifests/'.$observation->manifest_id, $headers)->assertOk();
        $this->assertStringNotContainsString($privateReason, $floor->getContent());
        $floor->assertJsonPath('props.selectedManifest.receiptCounts.confirmed_lost', 1);
        $this->assertSame($earlierWriter ? $privateReason : ShipmentLossAccountingService::FLOOR_REASON,
            $observation->manifest->events()->where('event_type', 'loss-accounted')->sole()->reason);
        $this->assertStringContainsString(ShipmentLossAccountingService::FLOOR_REASON, $floor->getContent());
    }
}
