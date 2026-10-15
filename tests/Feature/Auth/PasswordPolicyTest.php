<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;
    use WithRegistrationPolicies;

    public static function rejectedPasswords(): array
    {
        return [['Aa1!'.str_repeat('a', 3)], ['Aa1!'.str_repeat('a', 125)], [['invalid']], ['lowercase1234!'], ['UPPERCASE1234!'], ['NoNumbersHere!'], ['NoSymbols12345'], ['Password 12345'], ["Bagoo2026!\nPassword"]];
    }

    #[DataProvider('rejectedPasswords')]
    public function test_creation_and_both_reset_writers_reject_outside_the_shared_policy(mixed $password): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->getRepository()->create($user);
        $data = ['email' => 'new@bagoo.test', 'birthday' => '1995-05-15', 'name' => 'New Buyer', 'password' => $password, 'password_confirmation' => $password];
        $this->post('/register', $data)->assertSessionHasErrors('password');
        $data['email'] = $user->email;
        $data['token'] = $token;
        $this->post('/reset-password', $data)->assertSessionHasErrors('password');
        $this->postJson('/api/password/reset-otp', $data)->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public static function acceptedPasswords(): array
    {
        return [['Aa1!'.str_repeat('a', 4)], ['Aa1!'.str_repeat('a', 124)], ['  A plain passphrase 2026!  '], ['Aa1!'.str_repeat('é', 124)]];
    }

    #[DataProvider('acceptedPasswords')]
    public function test_compliant_passwords_are_saved_completely_and_authenticate_without_truncation(string $password): void
    {
        $this->post('/register', [...$this->registrationPolicies(), 'sex' => 'Female', 'birthday' => '1995-05-15', 'name' => 'New Buyer', 'email' => 'new@bagoo.test', 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasNoErrors();
        $user = User::where('email', 'new@bagoo.test')->sole();
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check(mb_substr($password, 0, -1).'b', $user->password));
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_existing_short_bcrypt_password_signs_in_and_upgrades_without_a_reset(): void
    {
        $user = User::factory()->create();
        $legacy = Hash::driver('bcrypt')->make('password');
        DB::table('users')->where('id', $user->id)->update(['password' => $legacy]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_existing_bcrypt_otp_challenges_still_verify(): void
    {
        EmailOtp::create(['email' => 'new@bagoo.test', 'purpose' => 'registration', 'code_hash' => Hash::driver('bcrypt')->make('123456'), 'expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/otp/verify', ['email' => 'new@bagoo.test', 'code' => '123456'])->assertOk()->assertJsonPath('success', true);
    }

    public function test_an_existing_short_bcrypt_password_opens_a_native_session_and_options_advertise_the_creation_policy(): void
    {
        $user = User::factory()->create(['role' => 'courier', 'status' => 'active', 'kyc_status' => 'approved', 'birthday' => '2000-01-01']);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::driver('bcrypt')->make('password')]);
        $response = $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Test device'])
            ->assertOk()->assertJsonPath('data.user.id', (string) $user->id);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/rider/me')->assertOk();
        $this->getJson('/api/v1/rider/registration/options')->assertOk()
            ->assertJsonPath('data.password_policy.min_length', 8)
            ->assertJsonPath('data.mobile_number_format', '+639XXXXXXXXX')
            ->assertJsonPath('data.mobile_number_unique', true)
            ->assertJsonPath('data.password_policy.max_length', 128);
    }
}
