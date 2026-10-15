<?php

namespace Tests\Feature\Courier;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsPlacementRecord;
use App\Models\NotificationDelivery;
use App\Models\RiderCommand;
use App\Models\RiderCompanyConsent;
use App\Models\RiderCompanyRequest;
use App\Models\RiderCompanyRequestEvent;
use App\Models\User;
use App\Services\Courier\RiderCompanyService;
use App\Services\Logistics\RiderCompanyOfferService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\RiderAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RiderCompanyApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $rider;

    protected LogisticsCompany $company;

    protected LogisticsHub $hub;

    protected string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setMicrosecond(123456));
        $this->owner = $this->account('logistics');
        $this->rider = $this->account('courier', ['name' => 'Bagoo Rider', 'email' => 'contract-rider@bagoo.test',
            'phone' => '+639170000099', 'city' => 'Santa Cruz', 'province' => 'Laguna']);
        CourierProfile::factory()->create(['user_id' => $this->rider->id, 'is_available' => false,
            'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
        $this->company = LogisticsCompany::create(['user_id' => $this->owner->id, 'name' => 'Bagoo Company Network',
            'slug' => 'bagoo-company-network', 'code' => 'RC03', 'status' => 'active', 'is_active' => true,
            'contact_email' => 'company@bagoo.test', 'accreditation_details' => ['private' => 'kyc_documents/company.pdf']]);
        $this->hub = LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Bagoo Bayan Hub',
            'code' => 'RC03-BH', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz',
            'is_active' => true, 'address' => 'Private facility address', 'coverage_barangays' => ['Bagumbayan']]);
        app(RiderCompanyRequestService::class)->changeIntake($this->owner, [
            'enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
        $this->plain = $this->signIn($this->rider);
    }

    protected function account(string $role, array $values = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => 'approved',
            'birthday' => '1990-01-01', 'email_verified_at' => now(), 'password' => 'Password1234',
            'id_document_path' => 'kyc_documents/private-id.pdf', 'driver_license_path' => 'kyc_documents/private-license.pdf',
            'or_cr_path' => 'kyc_documents/private-vehicle.pdf', ...$values]);
    }

    protected function signIn(User $user): string
    {
        $plain = $this->postJson('/api/v1/auth/tokens', ['email' => strtoupper($user->email), 'password' => 'Password1234',
            'device_name' => 'Company contract check'])->assertOk()->json('data.token');
        $this->withToken($plain);

        return $plain;
    }

    protected function key(): string
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);

        return $key;
    }

    protected function submission(array $overrides = []): array
    {
        return ['company_id' => (string) $this->company->id, 'intake_version' => $this->company->fresh()->rider_intake_version,
            'sharing_acknowledged' => true, 'note' => 'Available for scheduled work in Santa Cruz.', ...$overrides];
    }

    protected function apply(): RiderCompanyRequest
    {
        $this->key();
        $id = $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertOk()->json('data.result.id');

        return RiderCompanyRequest::findOrFail($id);
    }

    protected function offer(?RiderCompanyRequest $application = null, array $overrides = []): array
    {
        $application ??= $this->apply();
        $result = $this->actingAs($this->owner)->postJson('/hub/rider-company-requests/'.$application->id.'/offers', [
            'expected_version' => $application->fresh()->version, 'request_token' => (string) Str::uuid(),
            'hub_id' => $this->hub->id, 'barangay' => 'Bagumbayan',
            'terms' => 'Report to the recorded Bayan Hub and arrange your daily schedule.', ...$overrides])->assertOk()->json('data');
        $this->withToken($this->plain);

        return ['id' => (string) $application->id, 'offer' => $result['offer']];
    }

    protected function acceptance(array $offer, array $overrides = []): array
    {
        return ['expected_version' => RiderCompanyRequest::findOrFail($offer['id'])->version,
            'offer_id' => $offer['offer']['id'], 'terms_hash' => $offer['offer']['terms_hash'], 'acknowledged' => true, ...$overrides];
    }

    private function capture(string $schema, TestResponse $response, string $variant = ''): TestResponse
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/rider-operations.openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($schema, $spec['components']['schemas']);
        if ($response->status() < 400 && $schema !== 'RiderAccountDiscoveryEnvelope') {
            $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        }
        foreach (['credential_fingerprint', 'kyc_documents/', 'source_hash', 'password', 'private-id.pdf'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        if (getenv('RIDER_COMPANY_CONTRACT_ARTIFACTS') === '1') {
            $directory = base_path('.codex/rc03/contract-samples');
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/'.$schema.($variant ? '--'.$variant : '').'.json',
                json_encode($response->json(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }

        return $response;
    }

    public function test_exercised_response_samples_cover_discovery_empty_pending_offered_accepted_replay_and_errors(): void
    {
        $this->capture('RiderAccountDiscoveryEnvelope', $this->getJson('/api/v1/rider/me')->assertOk());
        $this->capture('HomeEnvelope', $this->getJson('/api/v1/rider/home')->assertOk());
        $this->capture('CompanyListEnvelope', $this->getJson('/api/v1/rider/companies')->assertOk());
        $this->capture('CompanyEnvelope', $this->getJson('/api/v1/rider/companies/'.$this->company->id)->assertOk());
        $this->capture('CompanyApplicationListEnvelope', $this->getJson('/api/v1/rider/company-requests')->assertOk(), 'empty');
        $this->capture('CompanyAssignmentEnvelope', $this->getJson('/api/v1/rider/company-assignment')->assertOk(), 'unassigned');
        $key = $this->key();
        $saved = $this->capture('CompanyCommandEnvelope', $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertOk(), 'submitted');
        $id = $saved->json('data.result.id');
        $this->capture('CompanyApplicationListEnvelope', $this->getJson('/api/v1/rider/company-requests')->assertOk(), 'pending');
        $this->capture('CompanyApplicationEnvelope', $this->getJson('/api/v1/rider/company-requests/'.$id)->assertOk(), 'pending');
        $offer = $this->offer(RiderCompanyRequest::findOrFail($id));
        $this->capture('CompanyApplicationEnvelope', $this->getJson('/api/v1/rider/company-requests/'.$id)->assertOk(), 'offered');
        $key = $this->key();
        $input = $this->acceptance($offer);
        $this->capture('CompanyCommandEnvelope', $this->postJson('/api/v1/rider/company-requests/'.$id.'/accept', $input)->assertOk(), 'accepted');
        $this->capture('CompanyCommandEnvelope', $this->getJson('/api/v1/rider/company-commands/'.$key)->assertOk(), 'reconciled');
        $this->capture('CompanyCommandEnvelope', $this->postJson('/api/v1/rider/company-requests/'.$id.'/accept', $input)->assertOk(), 'replayed');
        $this->capture('CompanyApplicationEnvelope', $this->getJson('/api/v1/rider/company-requests/'.$id)->assertOk(), 'accepted');
        $this->capture('CompanyAssignmentEnvelope', $this->getJson('/api/v1/rider/company-assignment')->assertOk(), 'accepted');
        app(NotificationDeliveryService::class)->deliverPending();
        $notices = $this->capture('NotificationListEnvelope', $this->getJson('/api/v1/rider/notifications')->assertOk(), 'company');
        $kinds = array_column(array_filter(array_column($notices->json('data.items'), 'target')), 'kind');
        $this->assertContains('company_request', $kinds);
        $this->assertContains('company_assignment', $kinds);
        $this->company->update(['status' => 'inactive']);
        $this->capture('CompanyAssignmentEnvelope', $this->getJson('/api/v1/rider/company-assignment')->assertOk(), 'restricted-company');
        $this->capture('Error', $this->postJson('/api/v1/rider/company-requests/'.$id.'/accept', [...$input, 'terms_hash' => str_repeat('a', 64)])->assertConflict(), 'changed-intent');
        $this->capture('Error', $this->getJson('/api/v1/rider/companies?page=01')->assertUnprocessable(), 'validation');
        $this->capture('Error', $this->getJson('/api/v1/rider/company-commands/'.Str::uuid())->assertNotFound(), 'unknown-command');
    }

    public function test_approved_unassigned_account_discovers_directory_and_sharing_without_operational_placement(): void
    {
        $token = app(RiderAccountService::class)->findToken($this->plain);
        foreach (RiderAccountService::COMPANY_ABILITIES as $ability) {
            $this->assertTrue($token->can($ability));
        }
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('data.company_api_version', 1);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.company_api_version', 1)
            ->assertJsonPath('data.capabilities.company_joining', true)->assertJsonPath('data.eligibility.operational', false);
        $response = $this->get('/api/v1/rider/companies')->assertOk()->assertJsonPath('data.items.0.id', (string) $this->company->id)
            ->assertJsonPath('data.items.0.areas.0.city', 'Santa Cruz');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->getJson('/api/v1/rider/companies/'.$this->company->id)->assertOk()->assertJsonPath('data.intake_version', 1);
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.company', null)
            ->assertJsonPath('data.actions.apply', true)->assertJsonPath('data.provenance.kind', 'unassigned')
            ->assertJsonPath('data.sharing_summary.email', $this->rider->email);
        foreach (['accreditation', 'company@bagoo.test', 'kyc_documents', 'Private facility address', 'birthday', 'driver_license_path'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('rider_commands', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_real_native_request_web_offer_native_acceptance_and_original_result_reconciliation(): void
    {
        $key = $this->key();
        $body = $this->submission();
        $first = $this->postJson('/api/v1/rider/company-requests', $body)->assertOk()->assertJsonPath('data.replayed', false)->json('data');
        $id = $first['result']['id'];
        $this->getJson('/api/v1/rider/company-commands/'.$key)->assertOk()->assertJsonPath('data.result', $first['result']);
        $this->postJson('/api/v1/rider/company-requests', $body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.recorded_at', $first['recorded_at']);
        $this->postJson('/api/v1/rider/company-requests', [...$body, 'note' => 'A different work schedule.'])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->getJson('/api/v1/rider/company-requests')->assertOk()->assertJsonPath('data.items.0.status', 'pending');
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.actions.apply', false);
        $offer = $this->offer(RiderCompanyRequest::findOrFail($id));
        $this->getJson('/api/v1/rider/company-requests/'.$id)->assertOk()->assertJsonPath('data.actions.accept', true)
            ->assertJsonPath('data.offer.terms_hash', $offer['offer']['terms_hash']);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $acceptKey = $this->key();
        $accept = $this->acceptance($offer);
        $saved = $this->postJson('/api/v1/rider/company-requests/'.$id.'/accept', $accept)->assertOk()
            ->assertJsonPath('data.result.status', 'accepted')->json('data');
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.company.id', (string) $this->company->id)
            ->assertJsonPath('data.hub.id', (string) $this->hub->id)->assertJsonPath('data.provenance.kind', 'accepted_offer')
            ->assertJsonPath('data.on_duty', false)->assertJsonPath('data.eligibility.operational', true);
        $this->postJson('/api/v1/rider/company-requests/'.$id.'/accept', $accept)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.result', $saved['result'])->assertJsonPath('data.recorded_at', $saved['recorded_at']);
        $this->getJson('/api/v1/rider/company-commands/'.$acceptKey)->assertOk()->assertJsonPath('data.result.status', 'accepted');
        $this->getJson('/api/v1/rider/commands/'.$acceptKey)->assertNotFound();
        $this->assertDatabaseCount('rider_company_consents', 1);
        $this->assertDatabaseCount('logistics_placement_records', 1);
        $this->assertDatabaseCount('rider_commands', 2);
        $this->assertSame('courier', $this->rider->fresh()->role);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_outcomes_and_expiry_are_owned_and_allow_another_request(string $state): void
    {
        $application = $this->apply();
        $offer = in_array($state, ['declined', 'cancelled', 'expired'], true) ? $this->offer($application) : null;
        $this->key();
        if (in_array($state, ['withdrawn', 'declined'], true)) {
            $action = $state === 'withdrawn' ? 'withdraw' : 'decline';
            $this->postJson('/api/v1/rider/company-requests/'.$application->id.'/'.$action, [
                'expected_version' => $application->fresh()->version, 'reason' => 'My available work schedule has changed.'])->assertOk();
        } elseif ($state === 'expired') {
            $this->travelTo(Carbon::parse($offer['offer']['expires_at']));
            $this->plain = $this->signIn($this->rider);
            $this->getJson('/api/v1/rider/company-requests/'.$application->id)->assertOk()->assertJsonPath('data.status', 'offered')
                ->assertJsonPath('data.effective_status', 'expired')->assertJsonPath('data.actions.accept', false);
            $this->assertSame('offered', $application->fresh()->status);
            $this->key();
            $this->postJson('/api/v1/rider/company-requests/'.$application->id.'/accept', $this->acceptance($offer))->assertConflict();
        } else {
            app(RiderCompanyOfferService::class)->companyDecision($this->owner, $application->id, $state, [
                'expected_version' => $application->fresh()->version, 'request_token' => (string) Str::uuid(), 'reason' => 'The available work arrangement has changed.']);
        }
        $this->capture('CompanyApplicationEnvelope', $this->getJson('/api/v1/rider/company-requests/'.$application->id)
            ->assertOk()->assertJsonPath('data.effective_status', $state), $state);
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.actions.apply', true);
        $this->apply();
        $this->assertSame($state, $application->fresh()->status);
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public static function terminalStates(): array
    {
        return array_map(fn ($state) => [$state], ['withdrawn', 'declined', 'rejected', 'cancelled', 'expired']);
    }

    public function test_competing_decisions_replaced_terms_and_changed_sources_do_not_place(): void
    {
        $offer = $this->offer();
        $old = $this->acceptance($offer);
        $current = $this->offer(RiderCompanyRequest::findOrFail($offer['id']), ['terms' => 'A new arrangement at the same recorded facility.']);
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $old)->assertConflict();
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($current, ['terms_hash' => $old['terms_hash']]))->assertConflict();
        $this->hub->update(['is_active' => false]);
        $this->hub->update(['is_active' => true]);
        $this->getJson('/api/v1/rider/company-requests/'.$offer['id'])->assertOk()->assertJsonPath('data.actions.accept', false);
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($current))->assertConflict();
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/decline', ['expected_version' => 3])->assertOk();
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/withdraw', ['expected_version' => 3])->assertConflict();
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('rider_commands', 2);
    }

    #[DataProvider('invalidSubmission')]
    public function test_native_input_boundaries_reject_without_partial_journal(array $overrides, string $field): void
    {
        $this->key();
        $response = $this->postJson('/api/v1/rider/company-requests', $this->submission($overrides))->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey($field, $response->json('errors'));
        $this->assertDatabaseCount('rider_commands', 0);
        $this->assertDatabaseCount('rider_company_requests', 0);
    }

    public static function invalidSubmission(): array
    {
        return [
            [['company_id' => '01'], 'company_id'], [['company_id' => '1e0'], 'company_id'],
            [['company_id' => '9223372036854775808'], 'company_id'], [['company_id' => true], 'company_id'],
            [['company_id' => ['1']], 'company_id'], [['intake_version' => 1.5], 'intake_version'],
            [['intake_version' => ' 1'], 'intake_version'], [['sharing_acknowledged' => 'true'], 'sharing_acknowledged'],
            [['sharing_acknowledged' => 1], 'sharing_acknowledged'], [['sharing_acknowledged' => false], 'sharing_acknowledged'],
            [['note' => 'A'], 'note'], [['note' => str_repeat('a', 501)], 'note'],
            [['note' => "Work\tprivate"], 'note'], [['note' => '<script>bad</script>'], 'note'],
            [['note' => ['wrong']], 'note'], [['request_token' => 'hidden'], 'request_token'],
            [['hub_id' => 1], 'hub_id'], [['is_available' => true], 'is_available'],
            [['rider_id' => 1], 'rider_id'], [['status' => 'accepted'], 'status'],
        ];
    }

    public function test_text_normalization_limits_and_canonical_numeric_retries(): void
    {
        $key = $this->key();
        $body = $this->submission(['note' => '  Ｗork in Santa Cruz.  ']);
        $first = $this->postJson('/api/v1/rider/company-requests', $body)->assertOk()->json('data.result');
        $this->postJson('/api/v1/rider/company-requests', [...$body, 'company_id' => $this->company->id,
            'intake_version' => '1', 'note' => 'Work in Santa Cruz.'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/company-requests/'.$first['id'])->assertOk()->assertJsonPath('data.note', 'Work in Santa Cruz.');
        $this->withHeader('Idempotency-Key', strtoupper($key))->postJson('/api/v1/rider/company-requests', $body)->assertUnprocessable();
        $this->assertDatabaseCount('rider_commands', 1);
    }

    public function test_raw_fraction_encoding_missing_keys_bad_ids_and_long_optional_text_reject(): void
    {
        $this->key();
        $json = json_encode($this->submission(['intake_version' => 1.0]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->call('POST', '/api/v1/rider/company-requests', [], [], [], $this->transformHeadersToServerVars([
            ...$this->defaultHeaders, 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $json)->assertUnprocessable();
        $this->withoutHeader('Idempotency-Key')->postJson('/api/v1/rider/company-requests', $this->submission())->assertUnprocessable();
        foreach (['0', '01', '-1', '1e0', '9223372036854775808'] as $id) {
            $this->get('/api/v1/rider/company-requests/'.$id)->assertNotFound();
            $this->get('/api/v1/rider/companies/'.$id)->assertNotFound();
        }
        $this->getJson('/api/v1/rider/companies?search='.str_repeat('a', 101))->assertUnprocessable();
        $this->assertDatabaseCount('rider_commands', 0);
    }

    public function test_owned_notice_targets_require_company_read_authority_and_never_link_another_riders_request(): void
    {
        $offer = $this->offer();
        app(NotificationDeliveryService::class)->deliverPending();
        $notices = $this->getJson('/api/v1/rider/notifications')->assertOk()->json('data.items');
        $targets = array_values(array_filter(array_column($notices, 'target')));
        $this->assertContains(['kind' => 'company_request', 'id' => $offer['id'], 'href' => '/api/v1/rider/company-requests/'.$offer['id']], $targets);
        $token = app(RiderAccountService::class)->findToken($this->plain);
        $token->forceFill(['abilities' => ['rider:account', 'rider:operations:read']])->save();
        $notices = $this->getJson('/api/v1/rider/notifications')->assertOk()->json('data.items');
        $this->assertSame([], array_values(array_filter(array_column($notices, 'target'))));
    }

    public function test_a_company_only_session_reconciles_only_its_own_company_commands(): void
    {
        $application = $this->apply();
        $companyKey = $this->defaultHeaders['Idempotency-Key'];
        app(NotificationDeliveryService::class)->deliverPending();
        $notice = $this->getJson('/api/v1/rider/notifications')->assertOk()->json('data.items.0.id');
        $operational = $this->key();
        $this->postJson('/api/v1/rider/notifications/'.$notice.'/read', [])->assertOk();
        app(RiderAccountService::class)->findToken($this->plain)->forceFill(['abilities' => ['rider:account', ...RiderAccountService::COMPANY_ABILITIES]])->save();
        $this->getJson('/api/v1/rider/company-commands/'.$companyKey)->assertOk()->assertJsonPath('data.result.id', (string) $application->id);
        $this->getJson('/api/v1/rider/company-commands/'.$operational)->assertNotFound();
        $this->getJson('/api/v1/rider/commands/'.$companyKey)->assertForbidden();
    }

    public function test_offer_and_decision_wire_history_is_bounded_without_discarding_original_records(): void
    {
        $application = $this->apply();
        for ($i = 0; $i < 52; $i++) {
            app(RiderCompanyOfferService::class)->issue($this->owner, $application->id, [
                'expected_version' => $application->fresh()->version, 'request_token' => (string) Str::uuid(),
                'hub_id' => $this->hub->id, 'terms' => 'Work arrangement number '.($i + 1).' at this facility.']);
        }
        $response = $this->getJson('/api/v1/rider/company-requests/'.$application->id)->assertOk()->assertJsonCount(50, 'data.offers')
            ->assertJsonCount(50, 'data.decisions')->assertJsonPath('data.history_limits.offers_truncated', true)
            ->assertJsonPath('data.history_limits.decisions_truncated', true)->assertJsonPath('data.actions.accept', true);
        $this->assertSame(3, $response->json('data.offers.0.version'));
        $this->assertDatabaseCount('rider_company_offers', 52);
        $this->assertDatabaseCount('rider_company_request_events', 53);
    }

    public function test_competing_native_acceptance_cannot_share_one_fleet_vehicle(): void
    {
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id,
            'plate_number' => 'XYZ1234', 'vehicle_type' => 'motorcycle', 'status' => 'active']);
        $firstToken = $this->plain;
        $first = $this->offer(null, ['vehicle_id' => $vehicle->id]);
        $secondRider = $this->account('courier');
        CourierProfile::factory()->create(['user_id' => $secondRider->id, 'is_available' => false,
            'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
        $this->plain = $this->signIn($secondRider);
        $second = $this->offer(null, ['vehicle_id' => $vehicle->id]);
        $this->assertNull($vehicle->fresh()->assigned_driver_id);
        $this->withToken($firstToken);
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$first['id'].'/accept', $this->acceptance($first))->assertOk();
        $this->withToken($this->plain);
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$second['id'].'/accept', $this->acceptance($second))->assertConflict();
        $this->assertSame($this->rider->id, $vehicle->fresh()->assigned_driver_id);
        $this->assertNull($secondRider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('rider_company_consents', 1);
    }

    public function test_a_domain_uniqueness_race_returns_conflict_without_a_false_command_or_not_found(): void
    {
        $receipt = app(RiderCompanyRequestService::class)->submit($this->rider,
            [...$this->submission(), 'request_token' => (string) Str::uuid()]);
        $application = RiderCompanyRequest::findOrFail($receipt['id']);
        $duplicate = (array) DB::table('rider_company_requests')->where('id', $application->id)->sole();
        unset($duplicate['id']);
        $duplicate['reference'] = 'RC-'.Str::ulid();
        $duplicate['request_token'] = (string) Str::uuid();
        $this->partialMock(RiderCompanyRequestService::class)->shouldReceive('submit')->once()->andReturnUsing(
            fn () => DB::table('rider_company_requests')->insert($duplicate));
        $key = $this->key();
        $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertConflict()->assertJsonPath('code', 'OPERATION_CONFLICT');
        $this->getJson('/api/v1/rider/company-commands/'.$key)->assertNotFound()->assertJsonPath('code', 'COMMAND_UNKNOWN');
        $this->assertDatabaseCount('rider_company_requests', 1);
        $this->assertDatabaseCount('rider_commands', 0);
    }

    #[DataProvider('invalidAcceptance')]
    public function test_acceptance_requires_exact_typed_consent(array $overrides): void
    {
        $offer = $this->offer();
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($offer, $overrides))->assertUnprocessable();
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertDatabaseCount('rider_commands', 1);
    }

    public static function invalidAcceptance(): array
    {
        return [[['acknowledged' => 1]], [['acknowledged' => '1']], [['acknowledged' => false]],
            [['offer_id' => '01']], [['expected_version' => '2e0']], [['terms_hash' => str_repeat('Z', 64)]],
            [['company_id' => '1']], [['accepted_at' => '2026-10-01T00:00:00Z']]];
    }

    public function test_foreign_request_company_and_command_data_are_hidden(): void
    {
        $offer = $this->offer();
        $key = $this->defaultHeaders['Idempotency-Key'];
        $other = $this->account('courier');
        $this->signIn($other);
        $this->getJson('/api/v1/rider/company-requests/'.$offer['id'])->assertNotFound();
        $this->getJson('/api/v1/rider/company-commands/'.$key)->assertNotFound()->assertJsonPath('code', 'COMMAND_UNKNOWN');
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($offer))->assertNotFound();
        $this->getJson('/api/v1/rider/company-requests')->assertOk()->assertJsonCount(0, 'data.items');
        app(RiderCompanyRequestService::class)->changeIntake($this->owner, ['enabled' => false,
            'expected_version' => 1, 'request_token' => (string) Str::uuid()]);
        $this->getJson('/api/v1/rider/companies/'.$this->company->id)->assertNotFound();
        $this->getJson('/api/v1/rider/companies')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('rider_company_consents', 0);
    }

    public function test_old_read_only_company_only_and_revoked_tokens_retain_their_boundaries(): void
    {
        $original = app(RiderAccountService::class)->findToken($this->plain);
        $original->forceFill(['abilities' => ['rider:account', 'rider:operations:read']])->save();
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('data.company_api_version', 1);
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.capabilities.company_joining', false);
        $this->get('/api/v1/rider/companies')->assertForbidden()->assertJsonPath('code', 'ACCESS_DENIED');
        $this->assertSame(['rider:account', 'rider:operations:read'], $original->fresh()->abilities);
        $this->plain = $this->signIn($this->rider);
        $token = app(RiderAccountService::class)->findToken($this->plain);
        $token->forceFill(['abilities' => ['rider:account', 'rider:company:read']])->save();
        $this->getJson('/api/v1/rider/companies')->assertOk();
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.actions.apply', false);
        $this->key();
        $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertForbidden();
        $this->getJson('/api/v1/rider/home')->assertForbidden();
        $token->delete();
        $this->get('/api/v1/rider/companies')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
    }

    public function test_legacy_wildcard_permissions_do_not_silently_gain_company_access(): void
    {
        $token = app(RiderAccountService::class)->findToken($this->plain);
        $token->forceFill(['abilities' => ['rider:account', '*']])->save();
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.capabilities.company_joining', false);
        $this->getJson('/api/v1/rider/companies')->assertForbidden();
        $this->key();
        $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertForbidden();
        $token->forceFill(['abilities' => ['rider:account', '*', 'rider:company:read']])->save();
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.actions.apply', false);
        $this->postJson('/api/v1/rider/company-requests', $this->submission())->assertForbidden();
        $this->assertDatabaseCount('rider_company_requests', 0);
    }

    #[DataProvider('deniedAccounts')]
    public function test_wrong_roles_and_lost_account_eligibility_never_grant_company_access(array $values): void
    {
        if (isset($values['role'])) {
            $actor = $this->account($values['role']);
            $issued = $actor->createToken('Denied role check', ['rider:account', ...RiderAccountService::COMPANY_ABILITIES], now()->addHour());
            $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $actor->getAuthPassword())])->save();
            $this->withToken($issued->plainTextToken);
        } else {
            $this->rider->forceFill($values)->save();
        }
        $this->get('/api/v1/rider/companies')->assertForbidden()->assertJsonPath('code', 'ACCESS_DENIED');
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->assertDatabaseCount('rider_commands', 0);
    }

    public static function deniedAccounts(): array
    {
        return [[['role' => 'buyer']], [['role' => 'seller']], [['role' => 'logistics']], [['role' => 'admin']],
            [['status' => 'pending_approval']], [['kyc_status' => 'rejected']], [['status' => 'suspended']],
            [['closed_at' => '2026-10-15T00:00:00Z']], [['email_verified_at' => null]], [['birthday' => '2015-01-01']]];
    }

    public function test_pending_and_unverified_signins_receive_no_company_abilities_and_cookie_auth_is_insufficient(): void
    {
        foreach ([['status' => 'pending_approval'], ['email_verified_at' => null]] as $values) {
            $rider = $this->account('courier', $values);
            $plain = $this->signIn($rider);
            $this->assertSame([], array_intersect(RiderAccountService::COMPANY_ABILITIES, app(RiderAccountService::class)->findToken($plain)->abilities));
            $this->getJson('/api/v1/rider/companies')->assertForbidden();
        }
        $this->withoutHeader('Authorization')->actingAs($this->rider)->get('/api/v1/rider/companies')->assertUnauthorized();
    }

    public function test_expired_password_changed_and_missing_tokens_cannot_read_or_write(): void
    {
        $token = app(RiderAccountService::class)->findToken($this->plain);
        $token->forceFill(['expires_at' => now()])->save();
        $this->get('/api/v1/rider/companies')->assertUnauthorized();
        $this->plain = $this->signIn($this->rider);
        $this->rider->update(['password' => 'NewPassword123!']);
        $this->get('/api/v1/rider/companies')->assertUnauthorized();
        $this->withoutHeader('Authorization')->get('/api/v1/rider/company-assignment')->assertUnauthorized();
        $this->assertDatabaseCount('rider_commands', 0);
    }

    public function test_missing_company_schema_disables_only_this_feature(): void
    {
        Schema::drop('rider_company_scope_changes');
        $this->getJson('/api/v1/rider/me')->assertOk()->assertJsonMissingPath('data.company_api_version');
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.company_api_version', null)
            ->assertJsonPath('data.capabilities.company_joining', false)->assertJsonPath('data.capabilities.home', true);
        $this->get('/api/v1/rider/companies')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->plain = $this->signIn($this->rider);
        $this->assertSame([], array_intersect(RiderAccountService::COMPANY_ABILITIES, app(RiderAccountService::class)->findToken($this->plain)->abilities));
    }

    public function test_existing_assignment_and_later_company_suspension_preserve_saved_membership_without_invented_consent(): void
    {
        $this->rider->courierProfile->update(['logistics_company_id' => $this->company->id, 'assigned_hub_id' => $this->hub->id]);
        $this->plain = $this->signIn($this->rider);
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.provenance.kind', 'existing_assignment')
            ->assertJsonPath('data.provenance.accepted_at', null)->assertJsonPath('data.eligibility.operational', true);
        $this->company->update(['status' => 'inactive']);
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.company.id', (string) $this->company->id)
            ->assertJsonPath('data.eligibility.operational', false)->assertJsonPath('data.actions.apply', false);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('rider_company_requests', 0);
    }

    public function test_accepted_result_replays_after_company_suspension_without_restoring_readiness_and_expires_after_seven_days(): void
    {
        $offer = $this->offer();
        $key = $this->key();
        $input = $this->acceptance($offer);
        $result = $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $input)->assertOk()->json('data.result');
        $this->company->update(['status' => 'inactive']);
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $input)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.result', $result);
        $this->getJson('/api/v1/rider/company-assignment')->assertOk()->assertJsonPath('data.eligibility.operational', false)
            ->assertJsonPath('data.provenance.kind', 'accepted_offer');
        $this->travelTo(RiderCommand::where('idempotency_key', $key)->sole()->expires_at);
        $this->plain = $this->signIn($this->rider);
        $this->getJson('/api/v1/rider/company-commands/'.$key)->assertOk()->assertJsonPath('data.retry_expired', true)->assertJsonPath('data.result', $result);
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $input)->assertConflict()->assertJsonPath('code', 'COMMAND_EXPIRED');
        $this->assertDatabaseCount('rider_company_consents', 1);
        $this->assertDatabaseCount('logistics_placement_records', 1);
    }

    #[DataProvider('badPages')]
    public function test_bounded_list_fields_are_strict(array $query): void
    {
        $this->get('/api/v1/rider/companies?'.http_build_query($query))->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->get('/api/v1/rider/company-requests?'.http_build_query($query))->assertUnprocessable();
    }

    public static function badPages(): array
    {
        return [[['page' => '01']], [['page' => '1e0']], [['page' => '10001']], [['per_page' => '51']],
            [['per_page' => '0']], [['page' => ['1']]], [['company_id' => '1']]];
    }

    public function test_bounded_ordered_history_directory_area_summaries_and_literal_search(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $result = app(RiderCompanyRequestService::class)->submit($this->rider, [...$this->submission(), 'request_token' => (string) Str::uuid()]);
            app(RiderCompanyOfferService::class)->riderDecision($this->rider, $result['id'], 'withdrawn', [
                'expected_version' => 1, 'request_token' => (string) Str::uuid()]);
        }
        $this->getJson('/api/v1/rider/company-requests?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination', ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $first = $this->getJson('/api/v1/rider/company-requests?per_page=2')->assertOk()->json('data.items');
        $this->assertGreaterThan((int) $first[1]['id'], (int) $first[0]['id']);
        for ($i = 0; $i < 51; $i++) {
            LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Bagoo Area '.$i, 'code' => 'RC03-AREA-'.$i,
                'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Area '.$i, 'is_active' => true, 'address' => 'Private address']);
        }
        $this->getJson('/api/v1/rider/companies/'.$this->company->id)->assertOk()->assertJsonCount(50, 'data.areas')->assertJsonPath('data.areas_truncated', true);
        $this->getJson('/api/v1/rider/companies?search=Bagoo%25')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/rider/companies?search=Bagoo&province=Laguna')->assertOk()->assertJsonCount(1, 'data.items');
        $this->get('/api/v1/rider/companies/'.$this->company->id.'?hub_id=1')->assertUnprocessable();
    }

    #[DataProvider('rollbackWriters')]
    public function test_each_linked_native_writer_failure_rolls_back_every_enrollment_effect(string $stage): void
    {
        $accept = ! str_starts_with($stage, 'submit.');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id,
            'plate_number' => 'ABC1234', 'vehicle_type' => 'motorcycle', 'status' => 'active']);
        $offer = $accept ? $this->offer(null, ['vehicle_id' => $vehicle->id]) : null;
        $before = array_map(fn ($table) => DB::table($table)->count(), $tables = ['rider_company_requests', 'rider_company_request_events',
            'rider_company_consents', 'logistics_placement_records', 'notification_deliveries', 'rider_commands']);
        $events = ['submit.request' => ['created', RiderCompanyRequest::class], 'submit.event' => ['created', RiderCompanyRequestEvent::class],
            'submit.notice' => ['creating', NotificationDelivery::class], 'consent' => ['created', RiderCompanyConsent::class],
            'profile' => ['updated', CourierProfile::class], 'fleet' => ['updated', LogisticsFleet::class],
            'placement' => ['created', LogisticsPlacementRecord::class], 'decision' => ['created', RiderCompanyRequestEvent::class],
            'notice' => ['creating', NotificationDelivery::class]];
        if (str_ends_with($stage, 'journal')) {
            DB::listen(function ($query) {
                if (str_starts_with(strtolower($query->sql), 'update "rider_commands"')) {
                    throw new RuntimeException('Private injected journal failure.');
                }
            });
        } else {
            [$event, $model] = $events[$stage];
            Event::listen('eloquent.'.$event.': '.$model, fn () => throw new RuntimeException('Private injected enrollment failure.'));
        }
        $this->key();
        $response = $accept ? $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($offer))
            : $this->postJson('/api/v1/rider/company-requests', $this->submission());
        $response->assertStatus(500);
        $this->assertStringNotContainsString('Private injected', $response->getContent());
        foreach ($tables as $i => $table) {
            $this->assertSame($before[$i], DB::table($table)->count(), $table);
        }
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
        $this->assertNull($vehicle->fresh()->assigned_driver_id);
    }

    public static function rollbackWriters(): array
    {
        return array_map(fn ($stage) => [$stage], ['submit.request', 'submit.event', 'submit.notice', 'submit.journal',
            'consent', 'profile', 'fleet', 'placement', 'decision', 'notice', 'journal']);
    }

    #[DataProvider('lateAuthority')]
    public function test_late_token_or_account_loss_rolls_back_consent_placement_and_command(string $loss): void
    {
        $offer = $this->offer();
        Event::listen('eloquent.created: '.RiderCompanyRequestEvent::class, function ($event) use ($loss) {
            if ($event->action !== 'accepted') {
                return;
            }
            $token = app(RiderAccountService::class)->findToken($this->plain);
            match ($loss) {
                'revoked' => $token->delete(),
                'narrowed' => $token->forceFill(['abilities' => ['rider:account', 'rider:company:read']])->save(),
                'wildcard' => $token->forceFill(['abilities' => ['rider:account', '*']])->save(),
                'expired' => $token->forceFill(['expires_at' => now()])->save(),
                'password' => $this->rider->update(['password' => 'ChangedPassword123!']),
                'email' => $this->rider->update(['email_verified_at' => null]),
                'suspended' => $this->rider->update(['status' => 'suspended']),
            };
        });
        $this->key();
        $this->postJson('/api/v1/rider/company-requests/'.$offer['id'].'/accept', $this->acceptance($offer))
            ->assertStatus(in_array($loss, ['revoked', 'expired', 'password'], true) ? 401 : 403);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertDatabaseCount('rider_commands', 1);
        $this->assertNull($this->rider->courierProfile->fresh()->logistics_company_id);
    }

    public static function lateAuthority(): array
    {
        return array_map(fn ($loss) => [$loss], ['revoked', 'narrowed', 'wildcard', 'expired', 'password', 'email', 'suspended']);
    }

    public function test_no_accept_header_errors_are_private_correlated_and_rate_limited(): void
    {
        $this->key();
        $this->post('/api/v1/rider/company-requests', ['company_id' => $this->company->id])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->partialMock(RiderCompanyService::class)->shouldReceive('companies')->once()->andThrow(
            new QueryException('sqlite', 'select private_company_data', ['private-binding'], new \PDOException('private-database')));
        $response = $this->get('/api/v1/rider/companies')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        foreach (['private_company_data', 'private-binding', 'private-database', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        for ($i = 0; $i < 9; $i++) {
            $this->post('/api/v1/rider/company-requests', [])->assertUnprocessable();
        }
        $response = $this->post('/api/v1/rider/company-requests', [])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertSame((string) $response->json('cooldown'), $response->headers->get('Retry-After'));
        $this->assertFalse($response->headers->has('Location'));
    }
}
