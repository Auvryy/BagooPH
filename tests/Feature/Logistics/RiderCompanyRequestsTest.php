<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\RiderCompanyIntakeEvent;
use App\Models\RiderCompanyRequest;
use App\Models\RiderCompanyRequestEvent;
use App\Models\User;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderCompanyRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $rider;

    private LogisticsCompany $company;

    private LogisticsHub $hub;

    private RiderCompanyRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RiderCompanyRequestService::class);
        $this->owner = $this->account('logistics');
        $this->rider = $this->account('courier');
        $this->company = LogisticsCompany::create(['user_id' => $this->owner->id, 'name' => 'Bagoo Laguna Fleet',
            'slug' => 'bagoo-laguna-fleet', 'code' => 'RC01', 'status' => 'active', 'is_active' => true,
            'logo' => '/images/bagoo-logo.png', 'contact_email' => 'fleet@bagoo.test',
            'accreditation_details' => ['business_permit_path' => 'kyc_documents/private-permit.pdf']]);
        $this->hub = LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Bagoo Santa Cruz Hub',
            'code' => 'RC01-BH', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz',
            'is_active' => true, 'address' => 'Bagoo hub street']);
        CourierProfile::factory()->create(['user_id' => $this->rider->id, 'is_available' => false,
            'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
    }

    private function account(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved',
            'birthday' => '1990-01-01', 'email_verified_at' => now(), 'city' => 'Santa Cruz', 'province' => 'Laguna',
            'id_document_path' => 'kyc_documents/private-id.pdf', 'driver_license_path' => 'kyc_documents/private-licence.pdf',
            'or_cr_path' => 'kyc_documents/private-vehicle.pdf', ...$attributes]);
    }

    private function intake(bool $enabled = true, ?string $key = null): array
    {
        return $this->service->changeIntake($this->owner, ['enabled' => $enabled,
            'expected_version' => $this->company->fresh()->rider_intake_version, 'request_token' => $key ?? (string) Str::uuid()]);
    }

    private function submission(array $values = []): array
    {
        return ['company_id' => (string) $this->company->id, 'intake_version' => $this->company->fresh()->rider_intake_version,
            'sharing_acknowledged' => true, 'note' => 'Available on weekdays in Santa Cruz.', 'request_token' => (string) Str::uuid(), ...$values];
    }

    public function test_intake_is_closed_until_an_owned_recorded_decision_and_request_does_not_place_the_rider(): void
    {
        $this->actingAs($this->rider)->get('/courier/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialTab', 'assignment')->where('companyJoining.can_apply', true)->has('companyJoining.companies.data', 0));
        $this->intake();
        $before = $this->rider->courierProfile->getAttributes();
        $this->actingAs($this->rider)->get('/courier/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('companyJoining.companies.data', 1)->where('companyJoining.companies.data.0.name', 'Bagoo Laguna Fleet')
            ->where('companyJoining.companies.data.0.areas.0.city', 'Santa Cruz'));
        $result = $this->postJson('/courier/company-requests', $this->submission())->assertOk()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertDatabaseCount('rider_company_requests', 1);
        $this->assertDatabaseCount('rider_company_request_events', 1);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertSame('pending', RiderCompanyRequest::find($result['id'])->status);
        $this->assertSame($this->rider->id, RiderCompanyRequestEvent::first()->actor_id);
    }

    #[DataProvider('portals')]
    public function test_each_portal_has_owned_intake_submission_history_and_withdrawal(string $host, string $riderPrefix, string $hubPrefix): void
    {
        $riderBase = 'http://'.($riderPrefix === '' ? 'courier.localhost' : 'localhost').$riderPrefix;
        $hubBase = 'http://'.($hubPrefix === '' ? 'hub.localhost' : 'localhost').$hubPrefix;
        $this->actingAs($this->owner)->putJson($hubBase.'/rider-request-intake', ['enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()])->assertOk();
        $this->actingAs($this->rider)->postJson($riderBase.'/company-requests', $this->submission())->assertOk();
        $request = RiderCompanyRequest::first();
        $this->get($riderBase.'/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page->has('companyJoining.requests.data', 1)->where('companyJoining.can_apply', false));
        $this->actingAs($this->owner)->get($hubBase.'/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('riderIntake.enabled', true)->has('riderIntake.requests.data', 1)->where('riderIntake.requests.data.0.shared_profile.name', $this->rider->name));
        $this->actingAs($this->rider)->postJson($riderBase.'/company-requests/'.$request->id.'/withdraw', ['expected_version' => 1, 'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->get($riderBase.'/profile?tab=assignment')->assertOk()->assertInertia(fn (Assert $page) => $page->where('companyJoining.can_apply', true)->where('companyJoining.requests.data.0.status', 'withdrawn'));
    }

    public static function portals(): array
    {
        return [['localhost', '/courier', '/hub'], ['courier.localhost', '', '/hub'], ['hub.localhost', '/courier', '']];
    }

    public function test_intake_and_request_retries_retain_their_original_outcomes_and_changed_intent_conflicts(): void
    {
        $key = (string) Str::uuid();
        $intake = ['enabled' => true, 'expected_version' => 0, 'request_token' => $key];
        $this->actingAs($this->owner)->putJson('/hub/rider-request-intake', $intake)->assertOk();
        $this->putJson('/hub/rider-request-intake', $intake)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.version', 1);
        $this->putJson('/hub/rider-request-intake', [...$intake, 'enabled' => false])->assertConflict();
        $this->assertDatabaseCount('rider_company_intake_events', 1);
        $body = $this->submission();
        $first = $this->actingAs($this->rider)->postJson('/courier/company-requests', $body)->assertOk()->json('data');
        $this->postJson('/courier/company-requests', $body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.submitted_at', $first['submitted_at']);
        $this->postJson('/courier/company-requests', [...$body, 'note' => 'A changed note for this request.'])->assertConflict();
        $this->assertDatabaseCount('rider_company_requests', 1);
    }

    public function test_owned_withdrawal_after_intake_closure_replays_and_allows_a_new_request(): void
    {
        $this->intake();
        $body = $this->submission();
        $this->service->submit($this->rider, $body);
        $request = RiderCompanyRequest::first();
        $this->intake(false);
        $withdrawal = ['expected_version' => 1, 'request_token' => (string) Str::uuid()];
        $first = $this->actingAs($this->rider)->postJson('/courier/company-requests/'.$request->id.'/withdraw', $withdrawal)->assertOk()->json('data');
        $this->postJson('/courier/company-requests/'.$request->id.'/withdraw', $withdrawal)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.withdrawn_at', $first['withdrawn_at']);
        $this->postJson('/courier/company-requests', $body)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.replayed', true);
        $this->assertSame('withdrawn', $request->fresh()->status);
        $this->intake();
        $this->postJson('/courier/company-requests', $this->submission())->assertOk();
        $this->assertDatabaseCount('rider_company_requests', 2);
        $this->assertDatabaseCount('rider_company_request_events', 3);
    }

    public function test_intake_aba_and_stale_withdrawal_are_rejected_without_partial_writes(): void
    {
        $this->intake();
        $body = $this->submission();
        $this->intake(false);
        $this->intake();
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $body)->assertConflict();
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->postJson('/courier/company-requests', $this->submission())->assertOk();
        $request = RiderCompanyRequest::first();
        $this->postJson('/courier/company-requests/'.$request->id.'/withdraw', ['expected_version' => 2, 'request_token' => (string) Str::uuid()])->assertConflict();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_only_one_pending_request_is_allowed_across_companies_and_the_database_enforces_it(): void
    {
        $this->intake();
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission())->assertOk();
        $this->postJson('/courier/company-requests', $this->submission())->assertConflict();
        $request = RiderCompanyRequest::first();
        $values = $request->getAttributes();
        unset($values['id']);
        $values['reference'] = 'RC-'.Str::ulid();
        $values['request_token'] = (string) Str::uuid();
        try {
            DB::table('rider_company_requests')->insert($values);
            $this->fail('A second pending request was accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
        $this->assertDatabaseCount('rider_company_requests', 1);
    }

    #[DataProvider('badSubmissions')]
    public function test_malformed_control_and_managed_inputs_fail_without_records(string $field, mixed $value): void
    {
        $this->intake();
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->assertDatabaseCount('rider_company_request_events', 0);
    }

    public static function badSubmissions(): array
    {
        return [['company_id', true], ['company_id', 0], ['company_id', '01'], ['company_id', '1e2'], ['company_id', '9223372036854775808'],
            ['intake_version', -1], ['sharing_acknowledged', false], ['sharing_acknowledged', 'yes'], ['sharing_acknowledged', []],
            ['note', 'abcd'], ['note', str_repeat('a', 501)], ['note', "\0Valid note"], ['note', "Valid\t note"], ['note', '<script>test</script>'],
            ['note', "Hidden\u{200B} note"], ['note', 'javascript: example'], ['note', ['not' => 'text']], ['request_token', 'not-a-key'],
            ['rider_id', 1], ['status', 'accepted'], ['profile_snapshot', ['name' => 'Injected']], ['assigned_hub_id', 1], ['kyc_status', 'approved']];
    }

    #[DataProvider('notes')]
    public function test_optional_normalized_notes_and_inclusive_boundaries_persist(?string $note, ?string $expected): void
    {
        $this->intake();
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission(['note' => $note]))->assertOk()->assertJsonPath('data.note', $expected);
    }

    public static function notes(): array
    {
        return [[null, null], ['', null], ['   ', null], [' abcde ', 'abcde'], ['abcde', 'abcde'], [str_repeat('a', 500), str_repeat('a', 500)], ["Available\r\nweekdays", "Available\r\nweekdays"]];
    }

    #[DataProvider('lostAuthority')]
    public function test_lost_rider_authority_cannot_submit_or_replay(string $field, mixed $value): void
    {
        $this->intake();
        $body = $this->submission();
        $this->service->submit($this->rider, $body);
        $this->rider->forceFill([$field => $value, ...($field === 'closed_at' ? ['status' => 'inactive'] : [])])->save();
        try {
            $this->service->submit($this->rider, $body);
            $this->fail('Lost account authority replayed a command.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $response = $this->actingAs($this->rider)->postJson('/courier/company-requests', $body);
        if (in_array($field, ['kyc_status', 'birthday'], true) || ($field === 'status' && $value === 'inactive')) {
            $response->assertRedirect('/pending-approval');
        } else {
            $response->assertForbidden();
        }
        $this->assertDatabaseCount('rider_company_requests', 1);
    }

    public static function lostAuthority(): array
    {
        return [['status', 'suspended'], ['status', 'inactive'], ['kyc_status', 'pending_approval'], ['kyc_status', 'rejected'],
            ['birthday', '2020-01-01'], ['email_verified_at', null], ['closed_at', '2026-10-14 10:00:00']];
    }

    public function test_foreign_riders_handlers_and_admins_cannot_manage_other_peoples_requests_or_intake(): void
    {
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        $request = RiderCompanyRequest::first();
        $another = $this->account('courier');
        $this->actingAs($another)->postJson('/courier/company-requests/'.$request->id.'/withdraw', ['expected_version' => 1, 'request_token' => (string) Str::uuid()])->assertNotFound();
        foreach (['buyer', 'seller', 'logistics', 'admin'] as $role) {
            $actor = $this->account($role);
            $this->actingAs($actor)->postJson('/courier/company-requests', $this->submission())->assertForbidden();
            $this->putJson('/hub/rider-request-intake', ['enabled' => false, 'expected_version' => 1, 'request_token' => (string) Str::uuid()])->assertForbidden();
            $this->assertNull($this->service->companyWorkspace($actor));
        }
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertTrue($this->company->fresh()->rider_intake_enabled);
    }

    public function test_shared_history_is_frozen_allowlisted_and_does_not_expose_private_evidence_or_birth_date(): void
    {
        $this->intake();
        $result = $this->service->submit($this->rider, $this->submission());
        $originalName = $this->rider->name;
        $this->rider->forceFill(['name' => 'Bagoo Updated Rider'])->save();
        $workspace = $this->service->companyWorkspace($this->owner);
        $record = $workspace['requests']->items()[0];
        $this->assertSame($originalName, $record['shared_profile']['name']);
        $this->assertSame($result['shared_profile'], $record['shared_profile']);
        $this->assertSame(['name', 'email', 'phone', 'city', 'province', 'vehicle_type', 'approval_status'], array_keys($record['shared_profile']));
        $encoded = json_encode($workspace);
        foreach (['private-id', 'private-licence', 'private-vehicle', 'birthday', 'license_number', 'plate_number', 'password', 'accreditation_details'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $encoded);
        }
        $this->assertArrayNotHasKey('profile_snapshot', RiderCompanyRequest::first()->toArray());
    }

    public function test_immutable_history_and_original_submission_reject_database_mutation(): void
    {
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        foreach (['rider_company_intake_events', 'rider_company_request_events', 'rider_company_requests'] as $table) {
            try {
                DB::table($table)->delete();
                $this->fail('History deletion succeeded.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('history', $exception->getMessage());
            }
        }
        try {
            DB::table('rider_company_requests')->update(['note' => 'Rewritten original note']);
            $this->fail('The original submission was rewritten.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('original submission', $exception->getMessage());
        }
    }

    public function test_request_audit_failure_rolls_back_the_request_without_touching_assignment(): void
    {
        $this->intake();
        $before = $this->rider->courierProfile->getAttributes();
        RiderCompanyRequestEvent::creating(fn () => throw new RuntimeException('Injected event failure'));
        try {
            $this->service->submit($this->rider, $this->submission());
            $this->fail('An audit failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected event failure', $exception->getMessage());
        } finally {
            RiderCompanyRequestEvent::flushEventListeners();
            RiderCompanyRequestEvent::clearBootedModels();
        }
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->assertDatabaseCount('rider_company_request_events', 0);
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
    }

    public function test_directory_and_owned_history_have_stable_bounded_pagination_and_literal_search(): void
    {
        $this->intake();
        $this->actingAs($this->rider)->getJson('/courier/profile?company_page=0')->assertUnprocessable();
        $this->getJson('/courier/profile?company_page=1000001')->assertUnprocessable();
        $this->get('/courier/profile?tab=assignment&company_search=Bagoo&company_province=Laguna&company_page=999')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('companyJoining.companies.current_page', 1)->where('companyJoining.companies.per_page', 15)
            ->where('companyJoining.filters.company_search', 'Bagoo')->where('companyJoining.filters.company_province', 'Laguna'));
        $this->get('/courier/profile?company_search=Bagoo%25')->assertOk()->assertInertia(fn (Assert $page) => $page->has('companyJoining.companies.data', 0));
        $this->getJson('/courier/profile?company_province=Unconfigured')->assertUnprocessable();
    }

    private function anotherCompany(int $number): array
    {
        $owner = $this->account('logistics');
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => sprintf('Bagoo Fleet %02d', $number),
            'slug' => 'bagoo-fleet-'.$number, 'code' => 'RC'.$number, 'status' => 'active', 'is_active' => true]);
        LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Local Hub '.$number,
            'code' => 'RC-BH'.$number, 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz',
            'is_active' => true, 'address' => 'Bagoo hub street']);
        $this->service->changeIntake($owner, ['enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);

        return [$owner, $company->fresh()];
    }

    public function test_actual_directory_pages_retain_filters_and_separate_companies_cannot_compete_for_one_rider(): void
    {
        $this->intake();
        for ($number = 1; $number <= 16; $number++) {
            [, $company] = $this->anotherCompany($number);
        }
        $first = $this->service->riderWorkspace($this->rider, ['company_search' => 'Bagoo', 'company_province' => 'Laguna']);
        $second = $this->service->riderWorkspace($this->rider, ['company_search' => 'Bagoo', 'company_province' => 'Laguna', 'company_page' => 2]);
        $this->assertCount(15, $first['companies']->items());
        $this->assertCount(2, $second['companies']->items());
        $this->assertEmpty(array_intersect(array_column($first['companies']->items(), 'id'), array_column($second['companies']->items(), 'id')));
        $this->assertStringContainsString('company_province=Laguna', $first['companies']->nextPageUrl());
        $this->assertStringContainsString('company_search=Bagoo', $first['companies']->nextPageUrl());
        $this->service->submit($this->rider, $this->submission());
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission(['company_id' => $company->id, 'intake_version' => 1]))->assertConflict();
        $this->assertDatabaseCount('rider_company_requests', 1);
    }

    #[DataProvider('companyDrift')]
    public function test_directory_and_mutations_recheck_current_company_owner_hub_and_recorded_intake(string $target, string $field, mixed $value): void
    {
        $this->intake();
        $body = $this->submission();
        $model = match ($target) {
            'owner' => $this->owner, 'hub' => $this->hub, default => $this->company
        };
        $model->forceFill([$field => $value])->save();
        $workspace = $this->service->riderWorkspace($this->rider);
        $this->assertCount(0, $workspace['companies']->items());
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $body)->assertConflict();
        $this->assertDatabaseCount('rider_company_requests', 0);
    }

    public static function companyDrift(): array
    {
        return [['owner', 'status', 'suspended'], ['owner', 'kyc_status', 'rejected'], ['owner', 'email_verified_at', null],
            ['company', 'is_active', false], ['company', 'status', 'inactive'], ['hub', 'is_active', false],
            ['company', 'rider_intake_enabled', false], ['company', 'rider_intake_version', 20]];
    }

    public function test_company_administrators_read_only_their_requests_and_handlers_cannot_enable_intake(): void
    {
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        [$foreignOwner, $foreignCompany] = $this->anotherCompany(1);
        $this->assertCount(0, $this->service->companyWorkspace($foreignOwner)['requests']->items());
        $this->actingAs($foreignOwner)->get('/hub/network')->assertInertia(fn (Assert $page) => $page->has('riderIntake.requests.data', 0));
        $handler = $this->account('logistics');
        HubHandler::create(['user_id' => $handler->id, 'hub_id' => $this->hub->id, 'is_active' => true, 'role_title' => 'Hub handler']);
        $this->actingAs($handler)->get('/hub/network?hub_id='.$this->hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('riderIntake', null));
        $this->putJson('/hub/rider-request-intake', ['enabled' => false, 'expected_version' => 1, 'request_token' => (string) Str::uuid()])->assertForbidden();
        $this->assertTrue($foreignCompany->fresh()->rider_intake_enabled);
        $this->assertTrue($this->company->fresh()->rider_intake_enabled);
    }

    #[DataProvider('profileGaps')]
    public function test_existing_assignment_or_incomplete_profile_cannot_be_overwritten(string $field, mixed $value): void
    {
        $this->intake();
        $profile = $this->rider->courierProfile;
        if ($field === 'missing') {
            $profile->delete();
        } else {
            $profile->forceFill([$field => $value === 'company' ? $this->company->id : ($value === 'hub' ? $this->hub->id : $value)])->save();
        }
        $before = $profile->fresh()?->getAttributes();
        $workspace = $this->service->riderWorkspace($this->rider);
        $this->assertFalse($workspace['can_apply']);
        $this->assertNotEmpty($workspace['blocked_reason']);
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission())->assertConflict();
        $this->assertSame($before, $profile->fresh()?->getAttributes());
        $this->assertDatabaseCount('rider_company_requests', 0);
    }

    public static function profileGaps(): array
    {
        return [['logistics_company_id', 'company'], ['assigned_hub_id', 'hub'], ['vehicle_type', ''],
            ['license_number', ''], ['plate_number', ''], ['missing', null]];
    }

    public function test_withdrawal_remains_available_after_company_becomes_ineligible_and_key_reuse_conflicts(): void
    {
        $this->intake();
        $body = $this->submission();
        $this->service->submit($this->rider, $body);
        $request = RiderCompanyRequest::first();
        $this->company->forceFill(['is_active' => false])->save();
        $this->actingAs($this->rider)->postJson('/courier/company-requests/'.$request->id.'/withdraw', ['expected_version' => 1, 'request_token' => $body['request_token']])->assertConflict();
        $this->postJson('/courier/company-requests/'.$request->id.'/withdraw', ['expected_version' => 1, 'request_token' => (string) Str::uuid()])->assertOk();
        $this->assertSame('withdrawn', $request->fresh()->status);
        $this->assertCount(1, $this->service->riderWorkspace($this->rider)['requests']->items());
    }

    public function test_intake_and_withdrawal_audit_failures_roll_back_the_whole_command(): void
    {
        RiderCompanyIntakeEvent::creating(fn () => throw new RuntimeException('Intake audit failure'));
        try {
            $this->intake();
            $this->fail('Intake audit failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Intake audit failure', $exception->getMessage());
        } finally {
            RiderCompanyIntakeEvent::flushEventListeners();
            RiderCompanyIntakeEvent::clearBootedModels();
        }
        $this->assertFalse($this->company->fresh()->rider_intake_enabled);
        $this->assertSame(0, $this->company->fresh()->rider_intake_version);
        $this->assertDatabaseCount('rider_company_intake_events', 0);
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        $request = RiderCompanyRequest::first();
        RiderCompanyRequestEvent::creating(fn () => throw new RuntimeException('Withdrawal audit failure'));
        try {
            $this->service->withdraw($this->rider, $request->id, ['expected_version' => 1, 'request_token' => (string) Str::uuid()]);
            $this->fail('Withdrawal audit failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Withdrawal audit failure', $exception->getMessage());
        } finally {
            RiderCompanyRequestEvent::flushEventListeners();
            RiderCompanyRequestEvent::clearBootedModels();
        }
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNull($request->fresh()->withdrawn_at);
        $this->assertDatabaseCount('rider_company_request_events', 1);
    }

    public function test_postflight_loss_of_rider_authority_rolls_back_request_and_eligibility_change(): void
    {
        $this->intake();
        $riderId = $this->rider->id;
        RiderCompanyRequestEvent::created(fn () => DB::table('users')->where('id', $riderId)->update(['status' => 'suspended']));
        try {
            $this->service->submit($this->rider, $this->submission());
            $this->fail('Authority lost during saving was ignored.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            RiderCompanyRequestEvent::flushEventListeners();
            RiderCompanyRequestEvent::clearBootedModels();
        }
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->assertDatabaseCount('rider_company_request_events', 0);
        $this->assertSame('active', $this->rider->fresh()->status);
    }

    public function test_missing_migration_keeps_profiles_readable_and_rejects_writes_honestly(): void
    {
        Schema::drop('rider_company_request_events');
        $this->actingAs($this->rider)->get('/courier/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->where('companyJoining.available', false));
        $this->actingAs($this->owner)->get('/hub/network')->assertOk()->assertInertia(fn (Assert $page) => $page->where('riderIntake.available', false));
        $this->actingAs($this->rider)->postJson('/courier/company-requests', $this->submission())->assertStatus(503);
    }

    public function test_unevidenced_withdrawal_is_rejected_and_missing_history_never_returns_fake_success(): void
    {
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        $request = RiderCompanyRequest::first();
        try {
            DB::table('rider_company_requests')->where('id', $request->id)->update(['status' => 'withdrawn', 'version' => 2, 'withdrawn_at' => now()]);
            $this->fail('Withdrawal without a receipt succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('evidenced withdrawal', $exception->getMessage());
        }
        DB::unprepared('DROP TRIGGER rider_company_request_events_no_delete');
        DB::table('rider_company_request_events')->delete();
        $this->actingAs($this->rider)->getJson('/courier/profile?tab=assignment')->assertStatus(503);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_guest_write_is_rejected_and_signed_web_conflict_retains_a_field_error(): void
    {
        $this->postJson('/courier/company-requests', $this->submission())->assertUnauthorized();
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        $this->actingAs($this->rider)->from('/courier/profile?tab=assignment')->post('/courier/company-requests', $this->submission())
            ->assertRedirect('/courier/profile?tab=assignment')->assertSessionHasErrors('company_request');
    }

    public function test_a_saved_province_filter_keeps_history_readable_when_the_last_company_pauses(): void
    {
        $this->intake();
        $this->service->submit($this->rider, $this->submission());
        $this->intake(false);
        $this->actingAs($this->rider)->get('/courier/profile?tab=assignment&company_province=Laguna')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('companyJoining.filters.company_province', 'Laguna')->has('companyJoining.companies.data', 0)
            ->has('companyJoining.requests.data', 1));
    }
}
