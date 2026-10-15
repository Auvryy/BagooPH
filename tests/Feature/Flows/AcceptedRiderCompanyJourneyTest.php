<?php

namespace Tests\Feature\Flows;

use App\Models\CodAccount;
use App\Models\CommissionLedger;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\RiderCompanyConsent;
use App\Models\RiderCompanyRequest;
use App\Models\User;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class AcceptedRiderCompanyJourneyTest extends TestCase
{
    use AssertsCommissionLedgers, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function acceptedRider(LogisticsCompany $company, LogisticsHub $hub): User
    {
        $rider = $this->createApprovedUser('courier', ['birthday' => '1990-01-01', 'email_verified_at' => now()]);
        $rider->courierProfile->update(['is_available' => false]);
        $requests = app(RiderCompanyRequestService::class);
        $offers = app(RiderCompanyOfferService::class);
        $owner = User::findOrFail($company->user_id);
        $request = $requests->submit($rider, ['company_id' => $company->id, 'intake_version' => $company->fresh()->rider_intake_version,
            'sharing_acknowledged' => true, 'request_token' => (string) Str::uuid()]);
        $offer = $offers->issue($owner, $request['id'], ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'hub_id' => $hub->id, 'terms' => 'Report to the recorded Bayan Hub and agree your daily working schedule.']);
        $offers->riderDecision($rider, $request['id'], 'accepted', ['expected_version' => 2, 'request_token' => (string) Str::uuid(),
            'offer_id' => $offer['offer']['id'], 'terms_hash' => $offer['offer']['terms_hash'], 'acknowledged' => true]);
        $this->assertFalse($rider->courierProfile->fresh()->is_available);
        $this->assertSame(0, DeliveryCheckpoint::where('actor_id', $rider->id)->count());
        app(CourierOperationsService::class)->setAvailability($rider, true);
        $this->assertTrue($rider->courierProfile->fresh()->is_available);

        return $rider->fresh();
    }

    public function test_newly_accepted_riders_follow_real_pickup_manifest_delivery_and_buyer_completion(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $order->delivery;
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        app(RiderCompanyRequestService::class)->changeIntake(User::findOrFail($company->user_id), [
            'enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $pickup = $this->acceptedRider($company, $origin);
        $final = $this->acceptedRider($company, $destination);
        $this->flowRider($origin, $pickup);
        $this->assertSame('ready_for_pickup', $order->fresh()->status);
        $this->assertSame('unassigned', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertDatabaseCount('rider_company_consents', 2);
        $delivered = $this->flowDelivery($order, 'delivered', $final);
        $this->assertSame($pickup->id, $delivered->courier_id);
        $this->assertSame($final->id, $delivered->assigned_rider_id);
        foreach (['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub', 'delivered'] as $checkpoint) {
            $this->assertTrue(DeliveryCheckpoint::where('delivery_id', $delivered->id)->where('checkpoint_type', $checkpoint)->exists());
        }
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $cashBefore = $cash->getAttributes();
        $this->assertGreaterThan(0, $cash->state['balances']['rider:'.$final->id]);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, CommissionLedger::where('order_id', $order->id)->count());
        app(LogisticsPlacementService::class)->placeCourier(User::findOrFail($company->user_id), $origin,
            $final, null, null, $destination->id);
        $this->assertSame($origin->id, $final->courierProfile->fresh()->assigned_hub_id);
        $this->assertFalse($final->courierProfile->fresh()->is_available);
        $this->assertSame($cashBefore, $cash->fresh()->getAttributes());
        $this->assertSame($destination->id, $cash->hub_id);
        $this->completeFlowOrder($order);
        $this->assertSame($cashBefore, $cash->fresh()->getAttributes());
        $this->assertSame(0, CommissionLedger::where('order_id', $order->id)->count());
        $this->settleFlowOrder($order);
        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
        foreach (RiderCompanyRequest::where('status', 'accepted')->get() as $request) {
            $history = app(RiderCompanyOfferService::class)->present($request);
            $this->assertSame('accepted', $history['status']);
            $this->assertNotNull($history['accepted_at']);
        }
        $this->assertSame(2, RiderCompanyConsent::count());
    }
}
