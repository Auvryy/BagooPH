<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsPlacementRecord;
use App\Models\NotificationDelivery;
use App\Models\RiderCompanyConsent;
use App\Models\RiderCompanyOffer;
use App\Models\RiderCompanyRequest;
use App\Models\RiderCompanyRequestEvent;
use App\Models\User;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\ResourceRestrictionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderCompanyOffersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $rider;

    private LogisticsCompany $company;

    private LogisticsHub $hub;

    private LogisticsFleet $vehicle;

    private RiderCompanyRequest $request;

    private RiderCompanyOfferService $offers;

    private RiderCompanyRequestService $requests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setMicrosecond(123456));
        $this->requests = app(RiderCompanyRequestService::class);
        $this->offers = app(RiderCompanyOfferService::class);
        $this->owner = $this->account('logistics');
        $this->rider = $this->account('courier');
        $this->company = LogisticsCompany::create(['user_id' => $this->owner->id, 'name' => 'Bagoo Company Offers',
            'slug' => 'bagoo-company-offers', 'code' => 'RC02', 'status' => 'active', 'is_active' => true]);
        $this->hub = LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Bagoo Bayan Hub',
            'code' => 'RC02-BH', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz',
            'is_active' => true, 'address' => 'Bagoo hub street', 'coverage_barangays' => ['Poblacion']]);
        CourierProfile::factory()->create(['user_id' => $this->rider->id, 'is_available' => false,
            'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
        $this->vehicle = LogisticsFleet::create(['logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id,
            'plate_number' => 'RC02-001', 'vehicle_type' => 'motorcycle', 'status' => 'active']);
        $this->requests->changeIntake($this->owner, ['enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
        $this->request = $this->apply();
    }

    private function account(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved',
            'birthday' => '1990-01-01', 'email_verified_at' => now()]);
    }

    private function foreignCompany(): LogisticsCompany
    {
        return LogisticsCompany::create(['user_id' => $this->account('logistics')->id, 'name' => 'Bagoo Other Network',
            'slug' => 'bagoo-other-network', 'code' => 'RC02-FOREIGN', 'status' => 'active', 'is_active' => true]);
    }

    private function apply(?User $rider = null): RiderCompanyRequest
    {
        $receipt = $this->requests->submit($rider ?? $this->rider, ['company_id' => $this->company->id,
            'intake_version' => $this->company->fresh()->rider_intake_version, 'sharing_acknowledged' => true,
            'request_token' => (string) Str::uuid()]);

        return RiderCompanyRequest::findOrFail($receipt['id']);
    }

    private function offerInput(array $overrides = []): array
    {
        return ['expected_version' => $this->request->fresh()->version, 'request_token' => (string) Str::uuid(),
            'hub_id' => $this->hub->id, 'vehicle_id' => $this->vehicle->id, 'barangay' => 'Poblacion',
            'terms' => 'Collect parcels at the assigned Bayan Hub. Discuss your duty schedule with the company.', ...$overrides];
    }

    private function issue(array $overrides = []): RiderCompanyOffer
    {
        $receipt = $this->offers->issue($this->owner, $this->request->id, $this->offerInput($overrides));

        return RiderCompanyOffer::findOrFail($receipt['offer']['id']);
    }

    private function acceptance(RiderCompanyOffer $offer, array $overrides = []): array
    {
        return ['expected_version' => $this->request->fresh()->version, 'request_token' => (string) Str::uuid(),
            'offer_id' => $offer->id, 'terms_hash' => $offer->terms_hash, 'acknowledged' => true, ...$overrides];
    }

    private function denied(callable $operation, int $status = 409): void
    {
        try {
            $operation();
            $this->fail('Unauthorized or stale assignment succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    public static function portals(): array
    {
        return [['http://localhost/courier', 'http://localhost/hub'], ['http://courier.localhost', 'http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_request_offer_and_explicit_acceptance_save_one_exact_off_duty_placement(string $riderBase, string $hubBase): void
    {
        $before = $this->rider->courierProfile->getAttributes();
        $offerInput = $this->offerInput();
        $issued = $this->actingAs($this->owner)->postJson($hubBase.'/rider-company-requests/'.$this->request->id.'/offers', $offerInput)
            ->assertOk()->assertJsonPath('data.status', 'offered')->json('data');
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertNull($this->vehicle->fresh()->assigned_driver_id);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $offer = RiderCompanyOffer::findOrFail($issued['offer']['id']);
        $this->assertTrue($offer->expires_at->equalTo($offer->issued_at->addDays(7)));
        $this->actingAs($this->rider)->get($riderBase.'/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('companyJoining.requests.data.0.can_accept', true)->where('companyJoining.requests.data.0.offer.terms', $offerInput['terms']));
        $input = $this->acceptance($offer);
        $first = $this->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $input)->assertOk()->json('data');
        $this->travel(1)->hours();
        $retry = $this->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $input)->assertOk()->assertJsonPath('data.replayed', true)->json('data');
        $this->assertSame($first['recorded_at'], $retry['recorded_at']);
        $profile = $this->rider->courierProfile->fresh();
        $this->assertSame($this->company->id, $profile->logistics_company_id);
        $this->assertSame($this->hub->id, $profile->assigned_hub_id);
        $this->assertSame($this->vehicle->id, $profile->vehicle_id);
        $this->assertFalse($profile->is_available);
        $this->assertSame($this->rider->id, $this->vehicle->fresh()->assigned_driver_id);
        $this->assertSame('approved', $this->rider->fresh()->kyc_status);
        $this->assertDatabaseCount('rider_company_consents', 1);
        $record = LogisticsPlacementRecord::sole();
        $consent = RiderCompanyConsent::sole();
        $this->assertSame($consent->id, $record->rider_company_consent_id);
        $this->assertTrue($record->recorded_at->equalTo($consent->accepted_at));
        $this->assertSame($offer->terms_hash, $consent->terms_hash);
        $this->assertSame('accepted', $this->offers->present($this->request->fresh())['status']);
        $this->get($riderBase.'/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignment.company', $this->company->name)->where('assignment.hub', $this->hub->name)
            ->where('vehicle.plate_number', $this->vehicle->plate_number)->where('isOnline', false)
            ->where('companyJoining.requests.data.0.status', 'accepted'));
        $this->actingAs($this->owner)->get($hubBase.'/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('riderIntake.roster.total', 1)->where('riderIntake.roster.data.0.hub_id', (string) $this->hub->id)
            ->where('riderIntake.roster.data.0.vehicle_id', (string) $this->vehicle->id)
            ->where('riderIntake.roster.data.0.on_duty', false)->where('riderIntake.roster.data.0.provenance', 'Accepted offer'));
    }

    #[DataProvider('portals')]
    public function test_existing_mother_handler_issues_an_offer_and_rider_accepts_without_company_owner_login(string $riderBase, string $hubBase): void
    {
        $motherHub = LogisticsHub::create(['logistics_company_id' => $this->company->id,
            'name' => 'Bagoo Mother Hub', 'code' => 'RC02-MOTHER', 'tier' => 'regional_mother_hub',
            'province' => 'Laguna', 'city_municipality' => 'Calamba', 'is_active' => true, 'address' => '100 Mother Hub Road']);
        $manager = $this->account('logistics');
        HubHandler::create(['user_id' => $manager->id, 'hub_id' => $motherHub->id,
            'logistics_company_id' => $this->company->id, 'is_active' => true]);
        $this->assertNotSame($this->company->user_id, $manager->id);
        $issued = $this->actingAs($manager)->postJson($hubBase.'/rider-company-requests/'.$this->request->id.'/offers', $this->offerInput())
            ->assertOk()->assertJsonPath('data.status', 'offered')->json('data');
        $offer = RiderCompanyOffer::findOrFail($issued['offer']['id']);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $input = $this->acceptance($offer);
        $this->actingAs($this->rider)->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $input)->assertOk();
        $this->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($this->company->id, $this->rider->courierProfile->fresh()->logistics_company_id);
        $this->assertSame($this->hub->id, $this->rider->courierProfile->fresh()->assigned_hub_id);
        $this->assertSame($this->vehicle->id, $this->rider->courierProfile->fresh()->vehicle_id);
        $this->assertSame($this->rider->id, $this->vehicle->fresh()->assigned_driver_id);
        $this->assertFalse($this->rider->courierProfile->fresh()->is_available);
        $this->assertDatabaseCount('rider_company_consents', 1);
        $this->assertDatabaseCount('logistics_placement_records', 1);
        $this->assertSame($manager->id, LogisticsPlacementRecord::sole()->actor_id);
        $this->assertSame($this->owner->id, $this->company->fresh()->user_id);
    }

    #[DataProvider('portals')]
    public function test_old_email_placement_cannot_create_membership_without_consent(string $riderBase, string $hubBase): void
    {
        $this->actingAs($this->owner)->postJson($hubBase.'/placements', ['kind' => 'courier', 'email' => $this->rider->email,
            'hub_id' => $this->hub->id])->assertConflict();
        $this->denied(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider));
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_replacement_retains_superseded_terms_and_changed_offer_or_key_intent_conflicts(): void
    {
        $input = $this->offerInput();
        $first = $this->offers->issue($this->owner, $this->request->id, $input);
        $old = RiderCompanyOffer::findOrFail($first['offer']['id']);
        $oldAcceptance = $this->acceptance($old);
        $this->travel(1)->days();
        $retry = $this->offers->issue($this->owner, $this->request->id, $input);
        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['offer']['expires_at'], $retry['offer']['expires_at']);
        $this->denied(fn () => $this->offers->issue($this->owner, $this->request->id, [...$input, 'terms' => 'Changed working terms.']));
        $new = $this->issue(['terms' => 'Use the revised duty arrangement at this same hub.']);
        $this->assertSame(2, $new->version);
        $this->assertSame($input['terms'], $old->fresh()->terms);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $oldAcceptance));
        $current = $this->offers->present($this->request->fresh());
        $this->assertSame('superseded', $current['offers'][0]['state']);
        $this->assertSame($new->id, (int) $current['offer']['id']);
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_expiry_reads_do_not_write_and_new_request_reconciles_expiry_without_a_worker(): void
    {
        $offer = $this->issue();
        $input = $this->acceptance($offer);
        $this->travelTo($offer->expires_at->subMicrosecond());
        $this->assertTrue($this->offers->present($this->request->fresh())['can_accept']);
        $this->travelTo($offer->expires_at);
        $before = RiderCompanyRequestEvent::count();
        $this->assertSame('expired', $this->offers->present($this->request->fresh())['effective_status']);
        $this->assertTrue($this->requests->riderWorkspace($this->rider)['can_apply']);
        $this->assertSame($before, RiderCompanyRequestEvent::count());
        $this->assertSame('offered', $this->request->fresh()->status);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input));
        $new = $this->apply();
        $this->assertNotSame($new->id, $this->request->id);
        $event = $this->request->events()->where('action', 'expired')->sole();
        $this->assertNull($event->actor_id);
        $this->assertTrue($event->recorded_at->equalTo(now()));
        $this->assertSame(0, $this->offers->expireDue());
    }

    public static function staleSources(): array
    {
        return array_map(fn ($source) => [$source], ['intake', 'owner', 'rider', 'identity', 'company', 'hub', 'fleet', 'vehicle', 'placement']);
    }

    #[DataProvider('staleSources')]
    public function test_changed_sources_and_disable_reenable_cycles_do_not_revive_an_offer(string $source): void
    {
        $offer = $this->issue();
        $input = $this->acceptance($offer);
        match ($source) {
            'intake' => $this->requests->changeIntake($this->owner, ['enabled' => false, 'expected_version' => 1, 'request_token' => (string) Str::uuid()]),
            'owner' => $this->owner->forceFill(['restriction_version' => $this->owner->restriction_version + 2])->save(),
            'rider' => $this->rider->forceFill(['restriction_version' => $this->rider->restriction_version + 2])->save(),
            'identity' => $this->rider->forceFill(['identity_version' => 1])->save(),
            'company' => $this->company->forceFill(['restriction_version' => $this->company->restriction_version + 2])->save(),
            'hub' => $this->hub->forceFill(['restriction_version' => $this->hub->restriction_version + 2])->save(),
            'fleet' => $this->vehicle->forceFill(['restriction_version' => $this->vehicle->restriction_version + 2])->save(),
            'vehicle' => $this->rider->courierProfile->update(['license_number' => 'REVIEWED-NEW']),
            'placement' => LogisticsPlacementRecord::create(['logistics_company_id' => $this->company->id, 'actor_id' => $this->owner->id,
                'user_id' => $this->rider->id, 'kind' => 'courier', 'before_state' => null, 'after_state' => [], 'recorded_at' => now()]),
        };
        if ($source === 'intake') {
            $this->requests->changeIntake($this->owner, ['enabled' => true, 'expected_version' => 2, 'request_token' => (string) Str::uuid()]);
        }
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input));
        $this->assertFalse($this->offers->present($this->request->fresh())['can_accept']);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_contact_and_photo_edits_preserve_the_offer_and_personal_vehicle_acceptance(): void
    {
        $offer = $this->issue(['vehicle_id' => null, 'barangay' => null]);
        $this->rider->update(['phone' => '09171234567', 'avatar' => 'avatars/ordinary.png']);
        $this->owner->update(['phone' => '09171234568']);
        $this->company->update(['contact_phone' => '09171234569']);
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer));
        $this->assertNull($this->rider->courierProfile->fresh()->vehicle_id);
        $this->assertNull($this->vehicle->fresh()->assigned_driver_id);
    }

    public static function failurePoints(): array
    {
        return [[RiderCompanyConsent::class, 'created'], [LogisticsFleet::class, 'updated'],
            [CourierProfile::class, 'updated'], [LogisticsPlacementRecord::class, 'created'],
            [RiderCompanyRequestEvent::class, 'created'], [NotificationDelivery::class, 'creating']];
    }

    #[DataProvider('failurePoints')]
    public function test_acceptance_rolls_back_every_required_write(string $model, string $stage): void
    {
        $offer = $this->issue();
        $profileBefore = $this->rider->courierProfile->getAttributes();
        $requestBefore = $this->request->fresh()->getAttributes();
        $outbox = NotificationDelivery::count();
        $eventName = 'eloquent.'.$stage.': '.$model;
        Event::listen($eventName, fn () => throw new RuntimeException('Required assignment write failed.'));
        try {
            $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer));
            $this->fail('Injected failure did not interrupt acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Required assignment write failed.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $this->assertSame($profileBefore, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertSame($requestBefore, $this->request->fresh()->getAttributes());
        $this->assertNull($this->vehicle->fresh()->assigned_driver_id);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertDatabaseCount('rider_company_request_events', 2);
        $this->assertSame($outbox, NotificationDelivery::count());
    }

    public function test_competing_offers_do_not_reserve_a_fleet_vehicle_and_only_one_can_accept_it(): void
    {
        $first = $this->issue();
        $firstRequest = $this->request;
        $secondRider = $this->account('courier');
        CourierProfile::factory()->create(['user_id' => $secondRider->id, 'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
        $this->request = $this->apply($secondRider);
        $second = $this->issue();
        $secondInput = $this->acceptance($second);
        $this->assertNull($this->vehicle->fresh()->assigned_driver_id);
        $this->request = $firstRequest;
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($first));
        $this->denied(fn () => $this->offers->riderDecision($secondRider, $second->rider_company_request_id, 'accepted', $secondInput));
        $this->assertNull($secondRider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('rider_company_consents', 1);
    }

    public static function terminalDecisions(): array
    {
        return [['rejected', false], ['cancelled', true], ['declined', true], ['withdrawn', true], ['withdrawn', false]];
    }

    #[DataProvider('terminalDecisions')]
    public function test_terminal_decisions_retain_reason_history_and_require_a_new_request(string $action, bool $offered): void
    {
        if ($offered) {
            $this->issue();
        }
        $input = ['expected_version' => $this->request->fresh()->version, 'request_token' => (string) Str::uuid(), 'reason' => 'The requested working arrangement is unavailable.'];
        $actor = in_array($action, ['rejected', 'cancelled'], true) ? $this->owner : $this->rider;
        $command = fn () => $actor === $this->owner ? $this->offers->companyDecision($actor, $this->request->id, $action, $input)
            : $this->offers->riderDecision($actor, $this->request->id, $action, $input);
        $first = $command();
        $this->assertSame($action, $first['status']);
        $this->assertTrue($command()['replayed']);
        $this->assertSame($input['reason'], $this->offers->present($this->request->fresh())['decisions'][array_key_last($this->offers->present($this->request->fresh())['decisions'])]['reason']);
        $this->denied(fn () => $this->issue());
        $this->assertNotSame($this->request->id, $this->apply()->id);
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_foreign_company_handler_and_other_rider_cannot_review_or_accept(): void
    {
        $handler = $this->account('logistics');
        HubHandler::create(['user_id' => $handler->id, 'logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id, 'is_active' => true]);
        $this->denied(fn () => $this->offers->issue($handler, $this->request->id, $this->offerInput()), 403);
        $offer = $this->issue();
        $this->denied(fn () => $this->offers->riderDecision($this->account('courier'), $this->request->id, 'accepted', $this->acceptance($offer)), 404);
        $this->assertNull($this->requests->companyWorkspace($handler));
    }

    public function test_owned_notices_have_current_links_and_no_documents_or_terms(): void
    {
        $this->issue();
        $pending = NotificationDelivery::where('recipient_id', $this->rider->id)->where('source_key', 'like', 'rider-company:%')->get();
        $this->assertCount(2, $pending);
        foreach ($pending as $delivery) {
            $this->assertStringNotContainsString('terms', json_encode($delivery->data));
            $this->assertStringNotContainsString('kyc_documents', json_encode($delivery->data));
        }
        $notice = new DatabaseNotification(['id' => (string) Str::uuid(), 'type' => 'governance-event',
            'data' => $pending->last()->data, 'created_at' => now()]);
        $this->assertSame('/courier/profile?tab=assignment', app(NotificationCenterService::class)->present($notice, $this->rider)['href']);
        $this->assertNull(app(NotificationCenterService::class)->present($notice, $this->account('courier'))['href']);
    }

    public function test_offers_consents_and_placement_history_are_immutable_at_database_level(): void
    {
        $offer = $this->issue();
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer));
        foreach (['rider_company_offers', 'rider_company_consents', 'rider_company_request_events', 'rider_company_scope_changes', 'logistics_placement_records'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(fn () => $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['id' => 99]));
                    $this->fail('Retained history was changed.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('immutable', $exception->getMessage());
                }
            }
        }
    }

    public static function invalidOfferFields(): array
    {
        return [['terms', ''], ['terms', 'abcd'], ['terms', str_repeat('a', 2001)], ['terms', '<b>working</b>'],
            ['terms', "Valid\twords"], ['terms', ['working arrangement']], ['hub_id', true], ['hub_id', '1e0'],
            ['hub_id', '-1'], ['hub_id', '01'], ['vehicle_id', '1.0'], ['expected_version', '１'],
            ['accepted', true], ['expires_at', '2030-01-01'], ['reason', 'Unrequested field']];
    }

    #[DataProvider('invalidOfferFields')]
    public function test_direct_offer_field_validation_rejects_invalid_and_server_owned_values(string $field, mixed $value): void
    {
        $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$this->request->id.'/offers', $this->offerInput([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rider_company_offers', 0);
        $this->assertSame('pending', $this->request->fresh()->status);
    }

    public static function termBoundaries(): array
    {
        return [['abcde'], [str_repeat('a', 2000)], [str_repeat('𐐀', 2000)], ["Discuss weekdays\r\nand assigned pickups."]];
    }

    #[DataProvider('termBoundaries')]
    public function test_offer_terms_accept_real_minimum_maximum_unicode_and_multiline_text(string $terms): void
    {
        $offer = $this->issue(['terms' => $terms]);
        $this->assertSame($terms, $offer->terms);
        $this->assertSame(hash('sha256', $terms), $offer->terms_hash);
    }

    #[DataProvider('portals')]
    public function test_each_portal_rejects_false_acknowledgement_wrong_hash_and_foreign_requests(string $riderBase, string $hubBase): void
    {
        $offer = $this->issue();
        foreach ([false, null, 'true', [], 'yes'] as $ack) {
            $this->actingAs($this->rider)->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $this->acceptance($offer, ['acknowledged' => $ack]))
                ->assertUnprocessable()->assertJsonValidationErrors('acknowledged');
        }
        $this->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $this->acceptance($offer, ['terms_hash' => str_repeat('0', 64)]))->assertConflict();
        $this->actingAs($this->account('courier'))->postJson($riderBase.'/company-requests/'.$this->request->id.'/accept', $this->acceptance($offer))->assertNotFound();
        $foreign = $this->account('logistics');
        LogisticsCompany::create(['user_id' => $foreign->id, 'name' => 'Bagoo Other Company', 'slug' => 'bagoo-other', 'code' => 'RC02-OTHER', 'status' => 'active', 'is_active' => true]);
        $this->actingAs($foreign)->postJson($hubBase.'/rider-company-requests/'.$this->request->id.'/offers', $this->offerInput())->assertNotFound();
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public static function unavailableSources(): array
    {
        return [['hub'], ['fleet'], ['busy'], ['rider_status'], ['rider_approval'], ['owner_status'], ['company'], ['foreign_hub'], ['foreign_fleet'], ['barangay']];
    }

    #[DataProvider('unavailableSources')]
    public function test_acceptance_rechecks_current_resources_and_accounts(string $source): void
    {
        $offer = $this->issue();
        $input = $this->acceptance($offer);
        match ($source) {
            'hub' => $this->hub->update(['is_active' => false]),
            'fleet' => $this->vehicle->update(['status' => 'maintenance']),
            'busy' => $this->vehicle->update(['assigned_driver_id' => $this->account('courier')->id]),
            'rider_status' => $this->rider->update(['status' => 'suspended']),
            'rider_approval' => $this->rider->update(['kyc_status' => 'rejected']),
            'owner_status' => $this->owner->update(['status' => 'inactive']),
            'company' => $this->company->update(['is_active' => false]),
            'foreign_hub' => $this->hub->update(['logistics_company_id' => $this->foreignCompany()->id]),
            'foreign_fleet' => $this->vehicle->update(['logistics_company_id' => $this->foreignCompany()->id]),
            'barangay' => $this->hub->update(['coverage_barangays' => ['Other barangay']]),
        };
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input), in_array($source, ['rider_status', 'rider_approval'], true) ? 403 : 409);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
    }

    #[DataProvider('portals')]
    public function test_decision_reasons_have_boundaries_and_rejected_input_is_retained(string $riderBase, string $hubBase): void
    {
        $url = $hubBase.'/rider-company-requests/'.$this->request->id.'/reject';
        $valid = ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'reason' => 'Short'];
        foreach (['', '    ', 'abcd', str_repeat('a', 501), '<script>reject</script>', ['not text']] as $reason) {
            $this->actingAs($this->owner)->postJson($url, [...$valid, 'reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->from($hubBase.'/network')->post($url, [...$valid, 'reason' => 'abcd'])->assertRedirect($hubBase.'/network')->assertSessionHasErrors('reason')->assertSessionHasInput('reason', 'abcd');
        $this->postJson($url, [...$valid, 'reason' => str_repeat('a', 500)])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_actual_restriction_and_reactivation_cycle_stales_the_offer_without_rewriting_custody(): void
    {
        $offer = $this->issue();
        $admin = $this->account('admin');
        $service = app(ResourceRestrictionService::class);
        foreach (['deactivate', 'reactivate'] as $action) {
            $current = $service->presentation($admin, 'hub', $this->hub->id);
            $service->decide($admin, 'hub', $this->hub->id, ['action' => $action, 'source_token' => $current['source_token'],
                'reason' => 'Review the company facility before using it.', 'affected_work_confirmed' => true]);
        }
        $this->assertTrue($this->hub->fresh()->is_active);
        $this->assertSame(2, $this->hub->fresh()->restriction_version);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer)));
        $new = $this->issue();
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($new));
        $this->assertSame(1, RiderCompanyConsent::count());
    }

    public function test_current_company_roster_is_paginated_private_and_keeps_existing_provenance_honest(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $user = $this->account('courier');
            CourierProfile::factory()->create(['user_id' => $user->id, 'logistics_company_id' => $this->company->id,
                'assigned_hub_id' => $this->hub->id, 'assigned_barangay' => 'Poblacion', 'vehicle_id' => null]);
        }
        $foreign = $this->account('courier');
        CourierProfile::factory()->create(['user_id' => $foreign->id, 'logistics_company_id' => $this->foreignCompany()->id]);
        $page = $this->requests->companyWorkspace($this->owner);
        $this->assertSame(16, $page['roster']->total());
        $this->assertCount(15, $page['roster']->items());
        $this->assertSame('Existing assignment', $page['roster']->items()[0]['provenance']);
        $this->assertSame((string) $this->hub->id, $page['roster']->items()[0]['hub_id']);
        $this->assertSame('Poblacion', $page['roster']->items()[0]['barangay']);
        $this->assertCount(1, $this->requests->companyWorkspace($this->owner, ['rider_roster_page' => 2])['roster']->items());
        $this->assertStringNotContainsString($foreign->email, json_encode($page));
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_notice_delivery_outage_keeps_acceptance_and_retries_without_duplicate_membership(): void
    {
        $offer = $this->issue();
        $before = DB::table('notifications')->count();
        DB::unprepared("CREATE TRIGGER rc02_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Temporary notice outage'); END");
        $input = $this->acceptance($offer);
        $first = $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input);
        $this->assertSame('accepted', $this->request->fresh()->status);
        $this->assertDatabaseCount('rider_company_consents', 1);
        $this->assertSame($before, DB::table('notifications')->count());
        $this->assertSame(3, NotificationDelivery::whereNull('delivered_at')->count());
        DB::unprepared('DROP TRIGGER rc02_notice_outage');
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertSame($before + 3, DB::table('notifications')->count());
        $retry = $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input);
        $this->assertSame($first['recorded_at'], $retry['recorded_at']);
        $this->assertDatabaseCount('logistics_placement_records', 1);
    }

    public function test_expiry_command_is_bounded_idempotent_and_records_its_real_execution_time(): void
    {
        $offer = $this->issue();
        $this->travelTo($offer->expires_at->addHours(2));
        foreach (['0', '1001', 'abc', '1.5'] as $limit) {
            $this->artisan('rider-companies:expire-offers', ['--limit' => $limit])->assertExitCode(2);
        }
        $this->artisan('rider-companies:expire-offers')->assertSuccessful();
        $this->artisan('rider-companies:expire-offers')->assertSuccessful();
        $event = $this->request->events()->where('action', 'expired')->sole();
        $this->assertTrue($event->recorded_at->equalTo(now()));
        $this->assertNull($event->actor_id);
    }

    public function test_source_hash_is_independent_of_json_object_key_order(): void
    {
        $eventName = 'eloquent.creating: '.RiderCompanyOffer::class;
        Event::listen($eventName, function ($offer) {
            $offer->sources = Arr::sortRecursive($offer->sources);
        });
        try {
            $offer = $this->issue();
        } finally {
            Event::forget($eventName);
        }
        $this->assertTrue($this->offers->present($this->request->fresh())['can_accept']);
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer));
        $this->assertSame('accepted', $this->request->fresh()->status);
    }

    public function test_changed_acceptance_key_intent_and_a_fabricated_consent_model_cannot_place_again(): void
    {
        $offer = $this->issue();
        $fake = new RiderCompanyConsent(['id' => 999, 'offer_id' => $offer->id, 'rider_id' => $this->rider->id, 'acknowledged' => true]);
        $this->denied(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $this->vehicle->id, 'Poblacion', null, $fake));
        $input = $this->acceptance($offer);
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $input);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', [...$input, 'terms_hash' => str_repeat('0', 64)]));
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', [...$input, 'request_token' => (string) Str::uuid()]));
        $this->assertDatabaseCount('logistics_placement_records', 1);
        $this->assertDatabaseCount('rider_company_consents', 1);
    }

    public function test_held_parcel_custody_conflicts_without_a_partial_membership(): void
    {
        $offer = $this->issue();
        $parcel = Delivery::factory()->create(['courier_id' => $this->rider->id, 'status' => 'picked_up']);
        $before = $parcel->fresh()->getAttributes();
        $this->actingAs($this->rider)->postJson('/courier/company-requests/'.$this->request->id.'/accept', $this->acceptance($offer))->assertConflict();
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
    }

    public static function restoredSources(): array
    {
        return [['account'], ['company'], ['hub'], ['fleet'], ['profile']];
    }

    #[DataProvider('restoredSources')]
    public function test_a_critical_source_cannot_change_back_to_revive_an_old_offer(string $kind): void
    {
        $offer = $this->issue();
        [$model, $field, $temporary] = match ($kind) {
            'account' => [$this->rider, 'name', 'A reviewed temporary name'],
            'company' => [$this->company, 'name', 'Bagoo Revised Company'],
            'hub' => [$this->hub, 'coverage_barangays', ['Another area']],
            'fleet' => [$this->vehicle, 'plate_number', 'RC02-CHANGED'],
            'profile' => [$this->rider->courierProfile, 'license_number', 'REVIEWED-TEMPORARY'],
        };
        $original = $model->$field;
        $model->update([$field => $temporary]);
        $model->update([$field => $original]);
        $this->assertSame($original, $model->fresh()->$field);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer)));
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_open_offer_blocks_another_pending_request_at_the_database_boundary(): void
    {
        $this->issue();
        $row = (array) DB::table('rider_company_requests')->where('id', $this->request->id)->first();
        unset($row['id']);
        $row = [...$row, 'reference' => 'RC-'.Str::ulid(), 'request_token' => (string) Str::uuid(),
            'status' => 'pending', 'version' => 1, 'current_offer_id' => null];
        try {
            DB::transaction(fn () => DB::table('rider_company_requests')->insert($row));
            $this->fail('A second open request was inserted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('rider_company_requests', 1);
        $this->assertSame('offered', $this->request->fresh()->status);
    }

    public function test_idempotency_is_actor_scoped_and_a_rider_key_cannot_be_reused_for_acceptance(): void
    {
        $offer = $this->issue(['request_token' => $this->request->request_token]);
        $this->denied(fn () => $this->offers->riderDecision($this->rider, $this->request->id, 'accepted',
            $this->acceptance($offer, ['request_token' => $this->request->request_token])));
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->offers->riderDecision($this->rider, $this->request->id, 'accepted', $this->acceptance($offer));
        $this->assertDatabaseCount('rider_company_consents', 1);
    }

    public static function unauthorizedDecisionActors(): array
    {
        return [['bayan'], ['foreign_mother'], ['suspended_mother']];
    }

    #[DataProvider('unauthorizedDecisionActors')]
    public function test_database_guard_rejects_forged_decisions_from_non_managers(string $kind): void
    {
        $actor = $this->account('logistics');
        $hub = $this->hub;
        if ($kind !== 'bayan') {
            $company = $kind === 'foreign_mother' ? $this->foreignCompany() : $this->company;
            $hub = LogisticsHub::create(['logistics_company_id' => $company->id,
                'name' => 'Decision Mother Hub', 'code' => 'RC02-GUARD-MH', 'tier' => 'regional_mother_hub',
                'province' => 'Laguna', 'city_municipality' => 'Calamba', 'is_active' => true, 'address' => '100 Guard Hub Road']);
        }
        HubHandler::create(['user_id' => $actor->id, 'hub_id' => $hub->id,
            'logistics_company_id' => $hub->logistics_company_id, 'is_active' => true]);
        if ($kind === 'suspended_mother') {
            $actor->update(['status' => 'suspended']);
        }
        $before = $this->request->getRawOriginal();
        try {
            DB::transaction(function () use ($actor) {
                RiderCompanyRequestEvent::create(['rider_company_request_id' => $this->request->id,
                    'actor_id' => $actor->id, 'request_token' => (string) Str::uuid(),
                    'fingerprint' => str_repeat('b', 64), 'action' => 'rejected', 'request_version' => 2,
                    'result' => [], 'reason' => 'Review this rider application.', 'recorded_at' => now()]);
                DB::table('rider_company_requests')->where('id', $this->request->id)->update(['status' => 'rejected', 'version' => 2]);
            });
            $this->fail('A forged non-manager decision bypassed the database guard.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('authorized decision receipt', $exception->getMessage());
        }
        $this->assertSame($before, $this->request->fresh()->getRawOriginal());
        $this->assertSame(0, RiderCompanyRequestEvent::where('actor_id', $actor->id)->count());
    }
}
