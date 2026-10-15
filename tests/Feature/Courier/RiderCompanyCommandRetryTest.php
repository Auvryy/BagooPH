<?php

namespace Tests\Feature\Courier;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Courier\RiderCommandService;
use App\Services\Logistics\RiderCompanyRequestService;
use App\Services\RiderAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiderCompanyCommandRetryTest extends TestCase
{
    public static function interruptions(): array
    {
        return [[1], [3]];
    }

    #[DataProvider('interruptions')]
    public function test_top_level_enrollment_retry_rolls_back_complete_attempts_and_has_a_bounded_failure(int $interruptions): void
    {
        // Use a fresh in-memory connection without an enclosing test transaction:
        // Laravel propagates nested deadlocks to the top-level transaction owner.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved',
            'email_verified_at' => now(), 'birthday' => '1990-01-01']);
        $rider = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved',
            'email_verified_at' => now(), 'birthday' => '1990-01-01']);
        CourierProfile::factory()->create(['user_id' => $rider->id, 'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null]);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Retry Network', 'slug' => 'bagoo-retry-network',
            'code' => 'RC03-RETRY', 'status' => 'active', 'is_active' => true]);
        LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Retry Hub', 'code' => 'RC03-RETRY-HUB',
            'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'is_active' => true, 'address' => 'Hub street']);
        $requests = app(RiderCompanyRequestService::class);
        $requests->changeIntake($owner, ['enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid()]);
        $plain = app(RiderAccountService::class)->session($rider, 'Retry verification')['token'];
        $rider->withAccessToken(app(RiderAccountService::class)->findToken($plain));
        $request = Request::create('/api/v1/rider/company-requests', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$plain);
        $request->headers->set('Idempotency-Key', (string) Str::uuid());
        $request->attributes->set('rider_company_ability', 'rider:company:write');
        $request->setUserResolver(fn () => $rider);
        $intent = ['company_id' => $company->id, 'intake_version' => 1, 'sharing_acknowledged' => true];
        $attempts = 0;
        try {
            $result = app(RiderCommandService::class)->execute($request, 'company.submit', 'company-'.$company->id, $intent,
                function () use ($requests, $rider, $intent, $request, $interruptions, &$attempts) {
                    $result = $requests->submit($rider, [...$intent, 'request_token' => $request->header('Idempotency-Key')]);
                    if (++$attempts <= $interruptions) {
                        throw new QueryException('sqlite', 'injected concurrency interruption', [], new \PDOException('deadlock detected', 40001));
                    }

                    return $result;
                });
            $this->assertSame(1, $interruptions);
            $this->assertSame('pending', $result['result']['status']);
            $this->assertSame(2, $attempts);
        } catch (QueryException $exception) {
            $this->assertSame(3, $interruptions);
            $this->assertSame(3, $attempts);
            $this->assertStringContainsString('deadlock detected', $exception->getMessage());
        }
        $saved = $interruptions === 1 ? 1 : 0;
        $this->assertDatabaseCount('rider_company_requests', $saved);
        $this->assertDatabaseCount('rider_company_request_events', $saved);
        $this->assertDatabaseCount('rider_commands', $saved);
        $this->assertDatabaseCount('notification_deliveries', 2 * $saved);
        $this->assertDatabaseCount('notifications', 2 * $saved);
        $this->assertDatabaseCount('rider_company_consents', 0);
        $this->assertNull($rider->courierProfile->fresh()->logistics_company_id);
        $this->assertSame(0, DB::transactionLevel());
    }
}
