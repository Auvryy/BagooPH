<?php

namespace Tests\Feature\Logistics;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class StaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->seed(RoutingCheckoutSeeder::class);
    }

    private function invite(string $kind = 'operator'): User
    {
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $this->actingAs($hub->company->user)->post('/hub/company-setup/'.$hub->logistics_company_id.'/staff', [
            'kind' => $kind, 'hub_id' => $hub->id, 'first_name' => 'Maya', 'last_name' => 'Santos',
            'birthday' => '1995-04-12', 'email' => 'crew-qa@bagoo.test', 'phone' => '09175551831',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        return User::where('email', 'crew-qa@bagoo.test')->sole();
    }

    public function test_invitation_verifies_the_workers_email_and_assignment_before_their_own_password(): void
    {
        $user = $this->invite();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->canAccessPortal());
        Mail::assertSent(OtpVerificationMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->purpose === 'company_staff_activation');
        $code = Mail::sent(OtpVerificationMail::class)->last()->otpCode;
        $identity = ['email' => $user->email, 'kind' => 'operator'];
        $verified = $this->postJson('/work-account/verify', [...$identity, 'code' => $code])->assertOk();
        $verified->assertJsonPath('assignment.company', LogisticsHub::where('code', 'BH-LBN-01')->sole()->company->name);
        $payload = [...$identity, 'token' => $verified->json('token'), 'assignment_token' => $verified->json('assignment_token'),
            'password' => 'My-Own-Secret!4826', 'password_confirmation' => 'My-Own-Secret!4826', 'assignment_confirmed' => true];
        $this->postJson('/work-account/activate', $payload)->assertOk();
        $user->refresh();
        $this->assertTrue($user->canAccessPortal());
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertSame('logistics', $user->role);
        $this->assertDatabaseHas('company_staff_provisions', ['user_id' => $user->id]);
        $this->postJson('/work-account/activate', $payload)->assertUnprocessable();
    }

    public function test_wrong_expired_and_wrong_role_codes_cannot_activate_a_worker(): void
    {
        $user = $this->invite();
        $code = Mail::sent(OtpVerificationMail::class)->last()->otpCode;
        $this->postJson('/work-account/verify', ['email' => $user->email, 'kind' => 'operator', 'code' => '000000'])->assertUnprocessable();
        $this->assertSame(1, EmailOtp::where('user_id', $user->id)->sole()->attempts);
        $this->postJson('/work-account/verify', ['email' => $user->email, 'kind' => 'rider', 'code' => $code])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/work-account/verify', ['email' => $user->email, 'kind' => 'operator', 'code' => $code])->assertUnprocessable();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_changed_assignment_and_missing_consent_do_not_burn_the_setup_into_access(): void
    {
        $user = $this->invite();
        $code = Mail::sent(OtpVerificationMail::class)->last()->otpCode;
        $identity = ['email' => $user->email, 'kind' => 'operator'];
        $verified = $this->postJson('/work-account/verify', [...$identity, 'code' => $code])->assertOk();
        $payload = [...$identity, 'token' => $verified->json('token'), 'assignment_token' => $verified->json('assignment_token'),
            'password' => 'My-Own-Secret!4826', 'password_confirmation' => 'My-Own-Secret!4826'];
        $this->postJson('/work-account/activate', $payload)->assertUnprocessable()->assertJsonValidationErrors('assignment_confirmed');
        DB::table('company_staff_provisions')->where('user_id', $user->id)
            ->update(['hub_id' => LogisticsHub::where('code', 'BH-SCZ-01')->sole()->id]);
        $this->postJson('/work-account/activate', [...$payload, 'assignment_confirmed' => true])->assertUnprocessable();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_rider_accepts_the_company_and_hub_without_becoming_an_operator(): void
    {
        $user = $this->invite('rider');
        $identity = ['email' => $user->email, 'kind' => 'rider'];
        $verified = $this->postJson('/work-account/verify', [...$identity,
            'code' => Mail::sent(OtpVerificationMail::class)->last()->otpCode])->assertOk();
        $this->postJson('/work-account/activate', [...$identity, 'token' => $verified->json('token'),
            'assignment_token' => $verified->json('assignment_token'), 'assignment_confirmed' => true,
            'password' => 'Rider-Own-Secret!4826', 'password_confirmation' => 'Rider-Own-Secret!4826'])->assertOk();
        $this->assertSame('courier', $user->fresh()->role);
        $this->assertTrue($user->fresh()->canAccessPortal());
        $this->assertFalse($user->courierProfile->is_available);
        $this->assertDatabaseMissing('hub_handlers', ['user_id' => $user->id]);
        $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $user->email,
            'password' => 'Rider-Own-Secret!4826', 'device_name' => 'Wrong role'])->assertUnprocessable();
    }

    public function test_email_failure_keeps_one_pending_account_and_owner_can_retry(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.transport' => 'log']);
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $this->actingAs($hub->company->user)->post('/hub/company-setup/'.$hub->logistics_company_id.'/staff', [
            'kind' => 'operator', 'hub_id' => $hub->id, 'first_name' => 'Maya', 'last_name' => 'Santos',
            'birthday' => '1995-04-12', 'email' => 'crew-qa@bagoo.test', 'phone' => '09175551831',
        ])->assertSessionHasNoErrors()->assertSessionHas('error');
        $user = User::where('email', 'crew-qa@bagoo.test')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseMissing('email_otps', ['user_id' => $user->id]);
        config(['mail.default' => 'array', 'mail.mailers.array.transport' => 'array']);
        $url = '/hub/company-setup/'.$hub->logistics_company_id.'/staff/'.$user->id.'/temporary-password';
        $this->post($url, ['current_password' => 'Password1234'])->assertSessionHasNoErrors()->assertSessionHas('success');
        Mail::assertSent(OtpVerificationMail::class);
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertDatabaseHas('company_staff_login_resets', ['user_id' => $user->id, 'actor_id' => $hub->company->user_id]);
    }

    public function test_resending_revokes_verified_setup_tokens_and_rejects_weak_passwords(): void
    {
        $user = $this->invite();
        $identity = ['email' => $user->email, 'kind' => 'operator'];
        $verified = $this->postJson('/work-account/verify', [...$identity,
            'code' => Mail::sent(OtpVerificationMail::class)->last()->otpCode])->assertOk();
        $payload = [...$identity, 'token' => $verified->json('token'), 'assignment_token' => $verified->json('assignment_token'),
            'assignment_confirmed' => true, 'password' => 'weak', 'password_confirmation' => 'weak'];
        $this->postJson('/work-account/activate', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->travel(61)->seconds();
        $this->postJson('/work-account/send', $identity)->assertOk();
        $this->postJson('/work-account/activate', [...$payload, 'password' => 'Worker-Own-Secret!4826',
            'password_confirmation' => 'Worker-Own-Secret!4826'])->assertUnprocessable();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_native_operator_can_activate_then_sign_in_to_only_the_assigned_hub(): void
    {
        $user = $this->invite();
        $identity = ['email' => $user->email, 'kind' => 'operator'];
        $this->postJson('/api/v1/logistics/activation/send', $identity)->assertTooManyRequests();
        $verified = $this->postJson('/api/v1/logistics/activation/verify', [...$identity,
            'code' => Mail::sent(OtpVerificationMail::class)->last()->otpCode])->assertOk();
        $this->postJson('/api/v1/logistics/activation/complete', [...$identity,
            'token' => $verified->json('token'), 'assignment_token' => $verified->json('assignment_token'),
            'assignment_confirmed' => true, 'password' => 'Operator-Own-Secret!4826',
            'password_confirmation' => 'Operator-Own-Secret!4826'])->assertOk();
        $login = $this->postJson('/api/v1/logistics/auth/tokens', ['email' => $user->email,
            'password' => 'Operator-Own-Secret!4826', 'device_name' => 'Invited scanner'])->assertOk();
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $login->assertJsonPath('data.hubs.0.id', $hub->id)->assertJsonCount(1, 'data.hubs');
        $this->withToken($login->json('data.token'))->getJson('/api/v1/logistics/session')->assertOk();
        $this->assertDatabaseHas('account_login_sessions', ['user_id' => $user->id, 'channel' => 'logistics']);
    }
}
