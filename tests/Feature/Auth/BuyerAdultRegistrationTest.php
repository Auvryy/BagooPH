<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class BuyerAdultRegistrationTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 16:01:00', 'UTC'));
        Mail::fake();
    }

    private function application(mixed $birthday): array
    {
        return $this->registrationPolicies() + ['role' => 'buyer', 'first_name' => 'Ana', 'last_name' => 'Dela Cruz', 'sex' => 'Female',
            'email' => 'adult-buyer@bagoo.test', 'password' => 'BagooPassword2026!', 'password_confirmation' => 'BagooPassword2026!', 'birthday' => $birthday];
    }

    public static function invalidDates(): array
    {
        return [[null], [''], [' '], ['2008-10-16'], ['2013-10-15'], ['2026-10-15'], ['2027-01-01'], ['2000-02-30'], [['2000-01-01']]];
    }

    #[DataProvider('invalidDates')]
    public function test_signup_and_final_step_reject_missing_invalid_or_underage_dates(mixed $birthday): void
    {
        $data = $this->application($birthday);
        $this->postJson('/register', $data)->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->postJson('/register/validate', $data + ['step' => 2])->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
        Mail::assertNothingSent();
    }

    public function test_exact_eighteenth_birthday_is_accepted_in_the_philippine_calendar_and_age_is_derived(): void
    {
        $this->post('/register', $this->application('2008-10-15') + ['age' => 99])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $buyer = User::sole();
        $this->assertSame(18, $buyer->age);
        $this->assertSame('none', $buyer->kyc_status);
        $this->assertSame('buyer', $buyer->role);
    }

    public function test_google_completion_enforces_the_same_adult_boundary_without_consuming_the_pending_signup(): void
    {
        $pending = ['identity' => ['email' => 'google-adult@bagoo.test'], 'google_id' => 'adult-boundary', 'avatar' => null, 'nonce' => 'buyer-adult-nonce', 'expires_at' => now()->addMinutes(10)->timestamp];
        $data = $this->application('2008-10-16') + ['registration_nonce' => $pending['nonce']];
        $this->withSession(['registration.google' => $pending])->postJson('/auth/google/register', $data)->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $data['birthday'] = '2008-10-15';
        $this->post('/auth/google/register', $data)->assertRedirect(route('buyer.index', ['application' => 1]));
        $this->assertSame(18, User::sole()->age);
        $this->assertAuthenticated();
    }
}
