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
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class AcceptedRiderCompanyJourneyTest extends TestCase
{
    use AssertsCommissionLedgers, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function acceptedRider(LogisticsCompany $company, LogisticsHub $hub, bool $native): User
    {
        $rider = $this->createApprovedUser('courier', ['birthday' => '1990-01-01', 'email_verified_at' => now()]);
        $rider->courierProfile->update(['is_available' => false]);
        $requests = app(RiderCompanyRequestService::class);
        $offers = app(RiderCompanyOfferService::class);
        $owner = User::findOrFail($company->user_id);
        $input = ['company_id' => $company->id, 'intake_version' => $company->fresh()->rider_intake_version, 'sharing_acknowledged' => true];
        if ($native) {
            $rider->update(['password' => 'Password1234']);
            $plain = $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'Password1234',
                'device_name' => 'Accepted rider journey'])->assertOk()->json('data.token');
            $this->withToken($plain)->withHeader('Idempotency-Key', (string) Str::uuid());
            $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.provenance.kind', 'unassigned');
            $request = $this->postJson('/api/v1/rider/company-requests', $input)->assertOk()->json('data.result');
        } else {
            $request = $requests->submit($rider, [...$input, 'request_token' => (string) Str::uuid()]);
        }
        $offer = $this->actingAs($owner)->postJson('/hub/rider-company-requests/'.$request['id'].'/offers', [
            'expected_version' => 1, 'request_token' => (string) Str::uuid(), 'hub_id' => $hub->id,
            'terms' => 'Report to the recorded Bayan Hub and agree your daily working schedule.'])->assertOk()->json('data');
        $accept = ['expected_version' => 2, 'offer_id' => $offer['offer']['id'], 'terms_hash' => $offer['offer']['terms_hash'], 'acknowledged' => true];
        if ($native) {
            $this->withToken($plain)->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/rider/company-requests/'.$request['id'].'/accept', $accept)->assertOk();
            $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.provenance.kind', 'accepted_offer')
                ->assertJsonPath('data.hub.id', (string) $hub->id)->assertJsonPath('data.on_duty', false);
        } else {
            $offers->riderDecision($rider, $request['id'], 'accepted', [...$accept, 'request_token' => (string) Str::uuid()]);
        }
        $this->assertFalse($rider->courierProfile->fresh()->is_available);
        $this->assertSame(0, DeliveryCheckpoint::where('actor_id', $rider->id)->count());
        if ($native) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson('/api/v1/rider/duty', ['on_duty' => true])->assertOk();
            $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.eligibility.operational', true);
        } else {
            app(CourierOperationsService::class)->setAvailability($rider, true);
        }
        $this->assertTrue($rider->courierProfile->fresh()->is_available);

        return $rider->fresh();
    }

    public static function channels(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('channels')]
    public function test_newly_accepted_riders_follow_real_pickup_manifest_delivery_and_buyer_completion(bool $native): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $order->delivery;
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        app(RiderCompanyRequestService::class)->changeIntake(User::findOrFail($company->user_id), [
            'enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $pickup = $this->acceptedRider($company, $origin, $native);
        $final = $this->acceptedRider($company, $destination, $native);
        $this->flowRider($origin, $pickup);
        $this->assertSame('ready_for_pickup', $order->fresh()->status);
        $this->assertSame('unassigned', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertDatabaseCount('rider_company_consents', 2);
        if ($native) {
            $foreign = LogisticsCompany::create(['user_id' => $this->createApprovedUser('logistics')->id, 'name' => 'Bagoo Other Company',
                'slug' => 'bagoo-other-journey-company', 'code' => 'RC03-OTHER', 'status' => 'active', 'is_active' => true]);
            $foreignHub = LogisticsHub::create(['logistics_company_id' => $foreign->id, 'name' => 'Bagoo Other Hub', 'code' => 'RC03-OTHER-HUB',
                'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'is_active' => true, 'address' => 'Other hub']);
            $foreignRider = $this->flowRider($foreignHub);
            $foreignToken = app(RiderAccountService::class)->session($foreignRider, 'Other company check')['token'];
            $this->withToken($foreignToken)->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', [
                    'expected_version' => app(RiderTaskService::class)->version($parcel->fresh())])->assertNotFound();
            $this->assertNull($parcel->fresh()->courier_id);
        }
        $delivered = $this->flowDelivery($order, 'delivered', $final);
        $this->assertSame($pickup->id, $delivered->courier_id);
        $this->assertSame($final->id, $delivered->assigned_rider_id);
        foreach (['arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub', 'delivered'] as $checkpoint) {
            $this->assertTrue(DeliveryCheckpoint::where('delivery_id', $delivered->id)->where('checkpoint_type', $checkpoint)->exists());
        }
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $cashBefore = $cash->getAttributes();
        $this->assertGreaterThan(0, $cash->state['balances']['rider:'.$final->id]);
        $this->assertSame('completed', $order->fresh()->status);
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
