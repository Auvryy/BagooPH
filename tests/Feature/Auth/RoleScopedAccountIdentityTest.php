<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RoleScopedAccountIdentityTest extends TestCase
{
    use RefreshDatabase, WithRegistrationPolicies;

    private function accounts(): array
    {
        $users = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            $users[$role] = User::factory()->create(['email' => 'shared@bagoo.test', 'role' => $role,
                'password' => ucfirst($role).'Password1!', 'status' => 'active', 'kyc_status' => 'approved', 'birthday' => '1990-01-01']);
        }

        return $users;
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_each_role_signs_in_to_its_own_account_with_a_shared_email(string $role): void
    {
        $users = $this->accounts();
        $this->post('/login', ['email' => ' SHARED@BAGOO.TEST ', 'role' => $role, 'password' => ucfirst($role).'Password1!'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($users[$role]);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('account_emails', 5);
        foreach ($users as $accountRole => $user) {
            $this->assertSame($accountRole, $user->fresh()->role);
        }
    }

    public function test_another_roles_password_cannot_authenticate_and_the_error_is_plain(): void
    {
        $this->accounts();
        $this->post('/login', ['email' => 'shared@bagoo.test', 'role' => 'buyer', 'password' => 'SellerPassword1!'])
            ->assertSessionHasErrors(['email' => 'Email or password is incorrect for your buyer account.']);
        $this->assertGuest();
    }

    public function test_an_ambiguous_legacy_login_requires_an_account_type(): void
    {
        $this->accounts();
        $this->post('/login', ['email' => 'shared@bagoo.test', 'password' => 'BuyerPassword1!'])
            ->assertSessionHasErrors(['email' => 'Choose the account type you want to sign in to.']);
        $this->assertGuest();
    }

    public function test_portal_hosts_cannot_be_overridden_by_a_role_field(): void
    {
        $this->accounts();
        $this->post('http://seller.localhost/login', ['email' => 'shared@bagoo.test', 'role' => 'admin', 'password' => 'AdminPassword1!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('http://seller.localhost/login', ['email' => 'shared@bagoo.test', 'password' => 'SellerPassword1!'])
            ->assertSessionHasNoErrors();
        $this->assertSame('seller', Auth::user()->role);
    }

    public function test_database_rejects_a_case_equivalent_duplicate_within_one_role(): void
    {
        $this->accounts();
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'SHARED@BAGOO.TEST', 'role' => 'seller']);
    }

    public function test_email_roles_cannot_be_forged_and_users_cannot_change_roles_directly(): void
    {
        $users = $this->accounts();
        try {
            DB::table('account_emails')->where('user_id', $users['buyer']->id)->update(['role' => 'seller']);
            $this->fail('Email ownership role changed.');
        } catch (QueryException) {
            $this->assertSame('buyer', $users['buyer']->accountEmails()->sole()->getRawOriginal('role'));
        }
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $users['buyer']->id)->update(['role' => 'admin']);
    }

    public function test_availability_and_registration_allow_another_role_but_reject_the_same_role(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'shared@bagoo.test', 'role' => 'logistics']);
        $this->postJson('/register/email/validate', ['email' => 'SHARED@bagoo.test', 'role' => 'buyer'])->assertJsonPath('available', true);
        $this->postJson('/register/email/validate', ['email' => 'SHARED@bagoo.test', 'role' => 'logistics'])->assertJsonPath('available', false);
        $data = $this->registrationPolicies() + ['role' => 'buyer', 'name' => 'Ana Dela Cruz', 'sex' => 'Female',
            'birthday' => '1990-01-01', 'email' => 'SHARED@bagoo.test', 'password' => 'BuyerPassword1!', 'password_confirmation' => 'BuyerPassword1!'];
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('users', 2);
        $this->post('/register', $data)->assertSessionHasErrors(['email' => 'This email is already registered for a buyer account. Sign in instead.']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_additional_recovery_addresses_are_reserved_within_their_owners_role(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer->accountEmails()->create(['email' => 'recovery@bagoo.test', 'verified_at' => now()]);
        $seller->accountEmails()->create(['email' => 'recovery@bagoo.test', 'verified_at' => now()]);
        $this->postJson('/register/email/validate', ['email' => 'recovery@bagoo.test', 'role' => 'buyer'])->assertJsonPath('available', false);
        $this->postJson('/register/email/validate', ['email' => 'recovery@bagoo.test', 'role' => 'logistics'])->assertJsonPath('available', true);
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'recovery@bagoo.test', 'role' => 'buyer']);
    }

    public function test_reset_links_are_independent_and_a_token_cannot_reset_another_roles_password(): void
    {
        Notification::fake();
        $users = $this->accounts();
        $this->post('/forgot-password', ['email' => 'shared@bagoo.test', 'role' => 'buyer'])->assertSessionHasNoErrors();
        $this->post('/forgot-password', ['email' => 'shared@bagoo.test', 'role' => 'seller'])->assertSessionHasNoErrors();
        Notification::assertSentTo($users['buyer'], ResetPassword::class);
        Notification::assertSentTo($users['seller'], ResetPassword::class);
        $buyerToken = Notification::sent($users['buyer'], ResetPassword::class)->sole()->token;
        $sellerToken = Notification::sent($users['seller'], ResetPassword::class)->sole()->token;
        $this->assertNotSame($buyerToken, $sellerToken);
        $this->assertTrue(Password::tokenExists($users['buyer'], $buyerToken));
        $this->assertTrue(Password::tokenExists($users['seller'], $sellerToken));
        $values = ['email' => 'shared@bagoo.test', 'token' => $buyerToken, 'password' => 'ChangedPassword1!', 'password_confirmation' => 'ChangedPassword1!'];
        $this->post('/reset-password', $values + ['role' => 'seller'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('SellerPassword1!', $users['seller']->fresh()->password));
        $this->post('/reset-password', $values + ['role' => 'buyer'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('ChangedPassword1!', $users['buyer']->fresh()->password));
        $this->assertTrue(Password::tokenExists($users['seller'], $sellerToken));
        $this->post('/reset-password', $values + ['role' => 'buyer'])->assertSessionHasErrors('email');
    }

    public function test_registration_verification_tokens_cannot_be_used_for_another_role(): void
    {
        Mail::fake();
        $otp = app(OtpService::class);
        $otp->sendOtp('shared@bagoo.test', 'registration', role: 'seller');
        $code = Mail::sent(OtpVerificationMail::class)->sole()->otpCode;
        $token = $otp->verifyOtp('shared@bagoo.test', $code, 'registration', role: 'seller')['token'];
        $this->assertFalse($otp->hasValidToken('shared@bagoo.test', $token, 'registration', role: 'buyer'));
        $this->assertFalse($otp->validateAndBurnToken('shared@bagoo.test', $token, 'registration', role: 'courier'));
        $this->assertTrue($otp->hasValidToken('shared@bagoo.test', $token, 'registration', role: 'seller'));
    }

    public function test_native_login_selects_the_rider_even_when_a_buyer_was_created_first(): void
    {
        $users = $this->accounts();
        $this->postJson('/api/v1/auth/tokens', ['email' => 'shared@bagoo.test', 'password' => 'CourierPassword1!', 'device_name' => 'Test rider'])
            ->assertOk()->assertJsonPath('data.user.id', (string) $users['courier']->id);
        $this->postJson('/api/v1/auth/tokens', ['email' => 'shared@bagoo.test', 'password' => 'BuyerPassword1!', 'device_name' => 'Test rider'])
            ->assertUnprocessable();
    }

    public function test_google_selects_the_buyer_and_does_not_link_another_roles_same_email(): void
    {
        $users = $this->accounts();
        $providerUser = Mockery::mock(SocialiteUser::class);
        $providerUser->shouldReceive('getRaw')->andReturn(['email_verified' => true]);
        $providerUser->shouldReceive('getId')->andReturn('shared-provider-identity');
        $providerUser->shouldReceive('getEmail')->andReturn('SHARED@bagoo.test');
        $providerUser->shouldReceive('getAvatar')->andReturn(null);
        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($providerUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->withSession(['auth.google.role' => 'buyer'])->get('/auth/google/callback')->assertRedirect();
        $this->assertAuthenticatedAs($users['buyer']);
        $this->assertSame('shared-provider-identity', $users['buyer']->fresh()->google_id);
        foreach (['seller', 'courier', 'logistics', 'admin'] as $role) {
            $this->assertNull($users[$role]->fresh()->google_id);
        }
    }

    public function test_recovery_for_an_ambiguous_email_requires_a_role_and_sends_nothing(): void
    {
        Notification::fake();
        Mail::fake();
        $this->accounts();
        $this->post('/forgot-password', ['email' => 'shared@bagoo.test'])->assertSessionHasErrors('email');
        $this->postJson('/api/otp/send', ['email' => 'shared@bagoo.test', 'purpose' => 'password_reset'])->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('email_otps', 0);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }
}
