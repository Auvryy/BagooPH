<?php

namespace Tests\Feature\Seeds;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderCompanyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_rider_keeps_original_membership_and_native_duty_without_synthetic_acceptance(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rider = User::where('email', 'rider@bagoo.test')->sole();
        $profile = $rider->courierProfile->getAttributes();
        $this->assertNotNull($profile['logistics_company_id']);
        $this->assertNotNull($profile['assigned_hub_id']);
        $plain = $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'Password1234',
            'device_name' => 'Seed compatibility check'])->assertOk()->json('data.token');
        $this->withToken($plain)->getJson('/api/v1/rider/company-assignment')->assertOk()
            ->assertJsonPath('data.company.id', (string) $profile['logistics_company_id'])
            ->assertJsonPath('data.hub.id', (string) $profile['assigned_hub_id'])
            ->assertJsonPath('data.provenance.kind', 'existing_assignment')->assertJsonPath('data.provenance.accepted_at', null);
        $this->assertSame($profile, $rider->courierProfile->fresh()->getAttributes());
        $this->getJson('/api/v1/rider/home')->assertOk()->assertJsonPath('data.eligibility.operational', true);
        $this->getJson('/api/v1/rider/pickup-jobs')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson('/api/v1/rider/duty', ['on_duty' => false])->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson('/api/v1/rider/duty', ['on_duty' => true])->assertOk();
        $this->assertSame($profile['logistics_company_id'], $rider->courierProfile->fresh()->logistics_company_id);
        $this->assertDatabaseCount('rider_company_requests', 0);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }
}
