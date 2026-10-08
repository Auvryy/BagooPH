<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\CourierProfile;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\AccountEmailService;
use App\Services\AccountSettingsService;
use App\Services\RiderAccountService;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/rider/settings';

    private function rider(array $attributes = []): User
    {
        return User::factory()->courier()->create($attributes + ['birthday' => '1998-01-01', 'password' => 'Password1234', 'phone' => null]);
    }

    private function signIn(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'Password1234', 'device_name' => 'Settings test']);
        $response->assertOk()->assertJsonPath('data.user.settings_api_version', 1);

        return $response->json('data.token');
    }

    private function token(User $user, ?array $abilities = null): string
    {
        $issued = $user->createToken('rider:settings-test', $abilities ?? ['rider:account', 'rider:logout', ...RiderAccountService::SETTINGS_ABILITIES], now()->addHour());
        $issued->accessToken->forceFill(['credential_fingerprint' => hash('sha256', $user->getAuthPassword())])->save();

        return $issued->plainTextToken;
    }

    private function snapshot(): array
    {
        return $this->getJson(self::BASE)->assertOk()->json('data');
    }

    private function issue(User $user, string $email = 'extra@bagoo.test', string $purpose = 'account_email', string $code = '123456'): EmailOtp
    {
        return EmailOtp::create(['user_id' => $user->id, 'email' => $email, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
    }

    private function emailPayload(array $extra = []): array
    {
        return $extra + ['email' => 'extra@bagoo.test', 'current_password' => 'Password1234'];
    }

    public function test_login_advertises_the_complete_version_and_snapshot_contains_only_owned_settings(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $other->accountEmails()->create(['email' => 'foreign@bagoo.test', 'verified_at' => now()]);
        $token = $this->signIn($rider);
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertOk()->assertJsonPath('data.settings_api_version', 1);
        $response = $this->getJson(self::BASE)->assertOk()->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.account_id', (string) $rider->id)->assertJsonPath('data.profile.email', $rider->email)
            ->assertJsonPath('data.profile.phone', null)->assertJsonPath('data.managed_details.available', false)
            ->assertJsonPath('data.capabilities.update_contact', true)->assertJsonPath('data.capabilities.change_password', true)
            ->assertJsonPath('data.capabilities.manage_emails', true);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $response->json('data.revision'));
        $addresses = $response->json('data.emails');
        $this->assertCount(1, $addresses);
        $this->assertSame(['id', 'email', 'is_original', 'verified', 'preferred'], array_keys($addresses[0]));
        $this->assertIsString($addresses[0]['id']);
        $this->assertTrue($addresses[0]['is_original']);
        $this->assertTrue($addresses[0]['preferred']);
        foreach (['foreign@bagoo.test', 'password', 'id_document_path', 'driver_license_path', 'credential_fingerprint', 'contact_settings_version'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
    }

    public function test_managed_details_use_the_same_stored_courier_fields_as_the_website(): void
    {
        $rider = $this->rider();
        CourierProfile::create(['user_id' => $rider->id, 'vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC-1234', 'license_number' => 'N01-123456', 'or_cr_status' => 'valid', 'assigned_barangay' => 'Sample Barangay']);
        $this->withToken($this->token($rider))->getJson(self::BASE)->assertOk()
            ->assertJsonPath('data.managed_details.available', true)->assertJsonPath('data.managed_details.company', null)
            ->assertJsonPath('data.managed_details.vehicle_type', 'Motorcycle')->assertJsonPath('data.managed_details.vehicle_model', null)
            ->assertJsonPath('data.managed_details.plate_number', 'ABC-1234')->assertJsonPath('data.managed_details.license_number', 'N01-123456')
            ->assertJsonPath('data.managed_details.registration_status', 'valid');
    }

    public function test_account_only_tokens_must_sign_in_again_and_read_only_settings_tokens_cannot_write(): void
    {
        $rider = $this->rider();
        $legacy = $this->token($rider, ['rider:account', 'rider:logout']);
        $this->withToken($legacy)->getJson('/api/v1/rider/me')->assertOk();
        $this->getJson(self::BASE)->assertForbidden();
        $this->assertSame(['rider:account', 'rider:logout'], PersonalAccessToken::findToken($legacy)->abilities);
        $this->withToken($this->token($rider, ['rider:account', 'rider:settings:read']));
        $snapshot = $this->snapshot();
        $this->assertSame(['update_contact' => false, 'change_password' => false, 'manage_emails' => false], $snapshot['capabilities']);
        $this->patchJson(self::BASE.'/profile', ['phone' => '0917 123 4567', 'revision' => $snapshot['revision']])->assertForbidden();
        $this->putJson(self::BASE.'/password', [])->assertForbidden();
        $this->postJson(self::BASE.'/emails/send', [])->assertForbidden();
        $this->assertNull($rider->fresh()->phone);
    }

    public function test_every_settings_endpoint_requires_a_real_bearer_even_with_a_browser_session(): void
    {
        $rider = $this->rider();
        $address = $rider->accountEmails()->first();
        $this->actingAs($rider);
        foreach ([['GET', ''], ['PATCH', '/profile'], ['PUT', '/password'], ['POST', '/emails/send'], ['POST', '/emails/confirm'], ['PATCH', '/emails/'.$address->id.'/preferred'], ['DELETE', '/emails/'.$address->id]] as [$method, $path]) {
            $response = $this->json($method, self::BASE.$path, [])->assertUnauthorized()->assertHeaderMissing('Location');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->deleteJson(self::BASE.'/emails/999999')->assertUnauthorized();
    }

    public static function ineligibleAccounts(): array
    {
        return array_map(fn ($changes) => [$changes], [['role' => 'buyer'], ['role' => 'seller'], ['role' => 'logistics'], ['role' => 'admin'],
            ['status' => 'pending_approval', 'kyc_status' => 'pending_approval'], ['status' => 'pending_approval', 'kyc_status' => 'rejected'],
            ['status' => 'suspended'], ['status' => 'inactive'], ['kyc_status' => 'rejected'], ['birthday' => '2015-01-01']]);
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_fresh_ineligible_accounts_cannot_read_or_mutate_settings(array $changes): void
    {
        $rider = $this->rider($changes);
        $this->withToken($this->token($rider))->getJson(self::BASE)->assertForbidden();
        $this->patchJson(self::BASE.'/profile', ['phone' => '0917 123 4567', 'revision' => str_repeat('a', 64)])->assertStatus(in_array($rider->status, ['suspended', 'inactive'], true) || $rider->role !== 'courier' || $rider->birthday->year === 2015 ? 401 : 403);
        $this->assertNull($rider->fresh()->phone);
    }

    public function test_expired_revoked_and_changed_password_tokens_return_private_401(): void
    {
        $rider = $this->rider();
        $token = $this->token($rider);
        PersonalAccessToken::findToken($token)->update(['expires_at' => now()]);
        $this->withToken($token)->getJson(self::BASE)->assertUnauthorized();
        $token = $this->token($rider);
        PersonalAccessToken::findToken($token)->delete();
        $this->withToken($token)->getJson(self::BASE)->assertUnauthorized();
        $token = $this->token($rider);
        $rider->update(['password' => 'NewPassword2026!']);
        $this->withToken($token)->getJson(self::BASE)->assertUnauthorized();
    }

    public function test_phone_save_is_canonical_identity_preserving_and_visible_on_the_website(): void
    {
        $rider = $this->rider();
        $this->withToken($this->signIn($rider));
        $snapshot = $this->snapshot();
        $saved = $this->patchJson(self::BASE.'/profile', ['phone' => '0917 123 4567', 'revision' => $snapshot['revision']])->assertOk()
            ->assertJsonPath('data.profile.phone', '+639171234567')->assertJsonPath('data.profile.name', $rider->name)
            ->assertJsonPath('data.profile.email', $rider->email)->json('data');
        $this->assertNotSame($snapshot['revision'], $saved['revision']);
        $this->assertSame('+639171234567', $rider->fresh()->phone);
        $this->assertSame('approved', $rider->fresh()->kyc_status);
        $this->actingAs($rider->fresh())->get('/courier/profile')->assertOk()->assertInertia(fn ($page) => $page->where('rider.phone', '+639171234567'));
        $this->patchJson(self::BASE.'/profile', ['phone' => null, 'revision' => $saved['revision']])->assertOk()->assertJsonPath('data.profile.phone', null);
    }

    public function test_same_second_website_edits_and_phone_aba_reject_stale_native_forms(): void
    {
        $this->freezeTime();
        $rider = $this->rider();
        $this->withToken($this->token($rider));
        $original = $this->snapshot();
        $this->actingAs($rider)->patch('/courier/profile/account', ['name' => $rider->name, 'phone' => '0918 123 4567'])->assertSessionHasNoErrors();
        $this->assertSame('+639181234567', $this->snapshot()['profile']['phone']);
        $this->patchJson(self::BASE.'/profile', ['phone' => '0917 123 4567', 'revision' => $original['revision']])->assertStatus(409)->assertJsonPath('data.profile.phone', '+639181234567');
        DB::table('users')->where('id', $rider->id)->update(['phone' => null]);
        $this->assertNotSame($original['revision'], $this->snapshot()['revision']);
        $this->patchJson(self::BASE.'/profile', ['phone' => '0917 123 4567', 'revision' => $original['revision']])->assertStatus(409);
        $this->assertNull($rider->fresh()->phone);
    }

    public static function invalidPhones(): array
    {
        return [['not a number'], ['+12025550100'], ['(02) 8123 4567'], ['０９１７１２３４５６７'], ["0917\n1234567"], ["0917\t1234567"], [['09171234567']]];
    }

    #[DataProvider('invalidPhones')]
    public function test_phone_validation_keeps_the_website_rules(mixed $phone): void
    {
        $rider = $this->rider();
        $this->withToken($this->token($rider));
        $revision = $this->snapshot()['revision'];
        $this->patchJson(self::BASE.'/profile', ['phone' => $phone, 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertNull($rider->fresh()->phone);
    }

    public static function managedFields(): array
    {
        return array_map(fn ($field) => [$field], ['name', 'email', 'birthday', 'role', 'status', 'kyc_status', 'company_id', 'assigned_hub_id', 'vehicle_type', 'driver_license_path', 'user_id', 'contact_settings_version']);
    }

    #[DataProvider('managedFields')]
    public function test_native_contact_cannot_change_managed_or_reviewed_fields(string $field): void
    {
        $rider = $this->rider();
        $this->withToken($this->token($rider));
        $revision = $this->snapshot()['revision'];
        $this->patchJson(self::BASE.'/profile', ['phone' => null, 'revision' => $revision, $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($revision, $this->snapshot()['revision']);
    }

    public function test_missing_phone_invalid_revision_and_other_accounts_revision_never_change_contact(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $this->withToken($this->token($other));
        $foreign = $this->snapshot()['revision'];
        $this->withToken($this->token($rider));
        $own = $this->snapshot()['revision'];
        $this->patchJson(self::BASE.'/profile', ['revision' => $own])->assertJsonValidationErrors('phone');
        $this->patchJson(self::BASE.'/profile', ['phone' => null, 'revision' => 'bad'])->assertJsonValidationErrors('revision');
        $this->patchJson(self::BASE.'/profile', ['phone' => '09171234567', 'revision' => $foreign])->assertStatus(409);
        $this->assertNull($rider->fresh()->phone);
    }

    public function test_password_change_confirms_then_revokes_all_old_native_tokens_and_allows_fresh_login(): void
    {
        $rider = $this->rider();
        $first = $this->signIn($rider);
        $second = $this->signIn($rider);
        $this->withToken($first)->putJson(self::BASE.'/password', ['current_password' => 'Password1234', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])
            ->assertOk()->assertExactJson(['data' => ['password_changed' => true, 'reauthentication_required' => true]]);
        $this->assertTrue(Hash::check('NewPassword2026!', $rider->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        foreach ([$first, $second] as $old) {
            $this->withToken($old)->getJson(self::BASE)->assertUnauthorized();
        }
        $new = $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'NewPassword2026!', 'device_name' => 'New session'])->assertOk()->json('data.token');
        $this->withToken($new)->getJson(self::BASE)->assertOk();
    }

    public static function invalidPasswords(): array
    {
        return [['wrong', 'NewPassword2026!', 'NewPassword2026!', 'current_password'],
            ['Password1234', 'Short1!', 'Short1!', 'password'],
            ['Password1234', str_repeat('a', 129), str_repeat('a', 129), 'password'],
            ['Password1234', 'NewPassword2026!', 'Different2026!', 'password']];
    }

    #[DataProvider('invalidPasswords')]
    public function test_password_rejections_leave_password_and_session_unchanged(string $current, string $password, string $confirmation, string $field): void
    {
        $rider = $this->rider();
        $this->withToken($this->token($rider))->putJson(self::BASE.'/password', ['current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertTrue(Hash::check('Password1234', $rider->fresh()->password));
        $this->getJson(self::BASE)->assertOk();
    }

    public function test_unverified_original_email_cannot_change_password_but_retains_ordinary_contact_and_email_rules(): void
    {
        $rider = $this->rider(['email_verified_at' => null]);
        $this->withToken($this->token($rider));
        $snapshot = $this->snapshot();
        $this->assertFalse($snapshot['capabilities']['change_password']);
        $this->assertTrue($snapshot['capabilities']['manage_emails']);
        $this->putJson(self::BASE.'/password', ['current_password' => 'Password1234', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('Password1234', $rider->fresh()->password));
        $this->assertFalse($snapshot['emails'][0]['verified']);
        $this->assertTrue($snapshot['emails'][0]['preferred']);
    }

    public function test_password_save_and_token_revocation_rollback_together(): void
    {
        $rider = $this->rider();
        $token = $this->token($rider);
        DB::unprepared("CREATE TRIGGER deny_token_deletion BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'Test token failure'); END;");
        $this->withoutExceptionHandling();
        try {
            $this->withToken($token)->putJson(self::BASE.'/password', ['current_password' => 'Password1234', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!']);
            $this->fail('Password and token writes must rollback together.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Test token failure', $exception->getMessage());
        }
        $this->assertTrue(Hash::check('Password1234', $rider->fresh()->password));
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_bearer_owner_password_is_used_even_when_browser_guard_is_another_user(): void
    {
        Mail::fake();
        $rider = $this->rider();
        $browser = $this->rider(['password' => 'BrowserPassword1234']);
        $this->actingAs($browser)->withToken($this->token($rider));
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload())->assertOk();
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload(['email' => 'different@bagoo.test', 'current_password' => 'BrowserPassword1234']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($rider->id, EmailOtp::firstOrFail()->user_id);
    }

    public function test_send_verify_prefer_remove_returns_fresh_snapshots_and_the_website_observes_the_changes(): void
    {
        Mail::fake();
        $rider = $this->rider();
        $this->withToken($this->signIn($rider));
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload(['email' => 'EXTRA@Bagoo.Test']))->assertOk()->assertJsonPath('success', true)->assertJsonPath('cooldown', 60)->assertJsonPath('expires_in', 600);
        $code = null;
        Mail::assertSent(OtpVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('extra@bagoo.test') && $mail->purpose === 'account_email';
        });
        $confirmed = $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => $code]))->assertOk()->json('data');
        $this->assertCount(2, $confirmed['emails']);
        $address = $rider->accountEmails()->where('is_original', false)->firstOrFail();
        $this->assertNull(EmailOtp::firstOrFail()->token);
        $credentials = ['current_password' => 'Password1234'];
        $preferred = $this->patchJson(self::BASE.'/emails/'.$address->id.'/preferred', $credentials)->assertOk()->json('data.emails');
        $this->assertSame(1, count(array_filter($preferred, fn ($email) => $email['preferred'])));
        $this->assertTrue($preferred[1]['preferred']);
        $this->assertSame('extra@bagoo.test', app(AccountEmailService::class)->presentation($rider->fresh())['addresses'][1]['email']);
        $this->assertSame('extra@bagoo.test', $rider->fresh()->routeNotificationForMail(new \stdClass));
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => $code]))->assertOk();
        $this->assertSame(2, $rider->accountEmails()->count());
        $this->deleteJson(self::BASE.'/emails/'.$address->id, $credentials)->assertOk()->assertJsonCount(1, 'data.emails')->assertJsonPath('data.emails.0.preferred', true);
        $this->assertSame($rider->email, $rider->fresh()->routeNotificationForMail(new \stdClass));
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => $code]))->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_original_foreign_and_unverified_addresses_cannot_be_removed_or_preferred_improperly(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $foreign = $other->accountEmails()->firstOrFail();
        $pending = $rider->accountEmails()->create(['email' => 'unverified@bagoo.test']);
        $original = $rider->accountEmails()->where('is_original', true)->firstOrFail();
        $this->withToken($this->token($rider));
        $credentials = ['current_password' => 'Password1234'];
        $this->patchJson(self::BASE.'/emails/'.$foreign->id.'/preferred', $credentials)->assertForbidden();
        $this->deleteJson(self::BASE.'/emails/'.$foreign->id, $credentials)->assertForbidden();
        $this->patchJson(self::BASE.'/emails/'.$pending->id.'/preferred', $credentials)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->deleteJson(self::BASE.'/emails/'.$original->id, $credentials)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotNull($original->fresh());
        $this->assertNull(app(AccountEmailService::class)->recoveryOwner($pending->email));
    }

    public function test_oversized_address_identifiers_return_private_404_before_database_lookup(): void
    {
        $rider = $this->rider();
        $this->withToken($this->token($rider));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "account_emails"')) {
                $queries[] = $query->sql;
            }
        });

        foreach (['9223372036854775808', str_repeat('9', 100)] as $id) {
            foreach ([['PATCH', '/preferred'], ['DELETE', '']] as [$method, $suffix]) {
                $response = $this->json($method, self::BASE.'/emails/'.$id.$suffix, ['current_password' => 'Password1234'])
                    ->assertNotFound()->assertHeader('Content-Type', 'application/json')->assertHeader('Pragma', 'no-cache')->assertHeaderMissing('Location');
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        }

        $this->assertSame([], $queries, 'Out-of-range email identifiers must not reach an integer database query.');
        $this->patchJson(self::BASE.'/emails/9223372036854775807/preferred', ['current_password' => 'Password1234'])->assertNotFound();
        $this->assertCount(1, $queries, 'The largest supported identifier must still reach the ordinary missing-address lookup.');
        $this->assertSame(1, $rider->accountEmails()->count());
        $this->assertNull($rider->fresh()->preferred_contact_email_id);
    }

    public function test_owned_email_management_requires_current_password_and_blocks_other_account_addresses_and_capacity(): void
    {
        Mail::fake();
        $rider = $this->rider();
        $other = $this->rider();
        $this->withToken($this->token($rider));
        $this->postJson(self::BASE.'/emails/send', ['email' => 'extra@bagoo.test'])->assertJsonValidationErrors('current_password');
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload(['current_password' => 'wrong']))->assertJsonValidationErrors('current_password');
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload(['email' => strtoupper($other->email)]))->assertJsonValidationErrors('email');
        for ($n = 0; $n < 5; $n++) {
            $rider->accountEmails()->create(['email' => 'contact'.$n.'@bagoo.test', 'verified_at' => now()]);
        }
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload())->assertJsonValidationErrors('email');
        $this->issue($rider);
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '123456']))->assertJsonValidationErrors('email');
        $this->assertSame(6, $rider->accountEmails()->count());
        Mail::assertNothingSent();
    }

    public function test_wrong_actor_wrong_purpose_expired_and_exhausted_codes_do_not_add_addresses(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $this->withToken($this->token($rider));
        $this->issue($other);
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '123456']))->assertJsonValidationErrors('code');
        $this->issue($rider, purpose: 'registration');
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '123456']))->assertJsonValidationErrors('code');
        $otp = $this->issue($rider);
        $otp->update(['expires_at' => now()]);
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '123456']))->assertJsonValidationErrors('code');
        $otp = $this->issue($rider);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '000000']))->assertJsonValidationErrors('code');
        }
        $this->assertSame(5, $otp->fresh()->attempts);
        $this->postJson(self::BASE.'/emails/confirm', $this->emailPayload(['code' => '123456']))->assertJsonValidationErrors('code');
        $this->assertSame(1, $rider->accountEmails()->count());
    }

    public function test_removal_revokes_pending_recovery_and_account_challenges(): void
    {
        $rider = $this->rider();
        $address = $rider->accountEmails()->create(['email' => 'extra@bagoo.test', 'verified_at' => now()]);
        $broker = app(PasswordBroker::class);
        $broker->getRepository()->create($rider);
        $this->issue($rider, purpose: 'password_reset');
        $this->issue($rider);
        $rider->forceFill(['preferred_contact_email_id' => $address->id])->save();
        $this->withToken($this->token($rider))->deleteJson(self::BASE.'/emails/'.$address->id, ['current_password' => 'Password1234'])->assertOk()->assertJsonPath('data.emails.0.preferred', true);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('email_otps', 0);
        $this->assertNull($rider->fresh()->preferred_contact_email_id);
        $this->assertNull(app(AccountEmailService::class)->recoveryOwner($address->email));
    }

    public function test_otp_cooldown_and_route_rate_limits_are_private_and_report_numeric_waits(): void
    {
        Mail::fake();
        $rider = $this->rider();
        $this->withToken($this->token($rider));
        $this->postJson(self::BASE.'/emails/send', $this->emailPayload())->assertOk();
        $response = $this->postJson(self::BASE.'/emails/send', $this->emailPayload())->assertStatus(429)->assertJsonPath('success', false);
        $this->assertIsInt($response->json('cooldown'));
        $this->assertGreaterThan(0, $response->json('cooldown'));
        $revision = $this->snapshot()['revision'];
        for ($n = 0; $n < 8; $n++) {
            $this->patchJson(self::BASE.'/profile', ['phone' => null, 'revision' => $revision])->assertOk();
        }
        $response = $this->patchJson(self::BASE.'/profile', ['phone' => null, 'revision' => $revision])->assertStatus(429)->assertHeaderMissing('Location');
        $this->assertTrue(ctype_digit($response->headers->get('Retry-After')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_failed_mail_send_does_not_claim_delivery_or_leave_an_active_code(): void
    {
        $rider = $this->rider();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Test mail failure'));
        $this->withToken($this->token($rider))->postJson(self::BASE.'/emails/send', $this->emailPayload())->assertStatus(503)->assertJsonPath('success', false);
        $this->assertDatabaseCount('email_otps', 0);
        $this->assertSame(1, $rider->accountEmails()->count());
    }

    public function test_revocation_between_middleware_and_a_locked_command_is_rechecked(): void
    {
        $rider = $this->rider();
        $token = $this->token($rider);
        $model = PersonalAccessToken::findToken($token);
        $request = Request::create(self::BASE.'/password', 'PUT', ['current_password' => 'Password1234', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!']);
        $request->headers->set('Authorization', 'Bearer '.$token);
        $request->attributes->set('rider_settings_ability', 'rider:settings:password');
        $request->setUserResolver(fn () => $rider->withAccessToken($model));
        $model->delete();
        try {
            app(AccountSettingsService::class)->changePassword($request);
            $this->fail('The locked command must recheck the token.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(401, $exception->getResponse()->getStatusCode());
        }
        $this->assertTrue(Hash::check('Password1234', $rider->fresh()->password));
    }

    #[DataProvider('acceptedPasswordLengths')]
    public function test_password_boundary_lengths_follow_the_website_contract(int $length): void
    {
        $rider = $this->rider();
        $password = str_repeat('a', $length);
        $this->withToken($this->token($rider))->putJson(self::BASE.'/password', ['current_password' => 'Password1234', 'password' => $password, 'password_confirmation' => $password])->assertOk();
        $this->assertTrue(Hash::check($password, $rider->fresh()->password));
    }

    public static function acceptedPasswordLengths(): array
    {
        return [[12], [128]];
    }

    public function test_website_password_changes_revoke_native_tokens_and_native_reads_observe_website_email_changes(): void
    {
        $rider = $this->rider();
        $token = $this->token($rider);
        $this->issue($rider);
        $this->actingAs($rider)->postJson('/account/emails/confirm', $this->emailPayload(['code' => '123456']))->assertOk();
        $address = $rider->accountEmails()->where('is_original', false)->firstOrFail();
        $this->patchJson('/account/emails/'.$address->id.'/preferred', ['current_password' => 'Password1234'])->assertOk();
        $this->withToken($token)->getJson(self::BASE)->assertOk()->assertJsonCount(2, 'data.emails')->assertJsonPath('data.emails.1.preferred', true);
        $this->put('/password', ['current_password' => 'Password1234', 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])->assertRedirect();
        $this->getJson(self::BASE)->assertUnauthorized();
    }

    public function test_email_only_authority_cannot_save_phone_or_change_password_and_wrong_password_cannot_manage_emails(): void
    {
        $rider = $this->rider();
        $address = $rider->accountEmails()->create(['email' => 'extra@bagoo.test', 'verified_at' => now()]);
        $this->withToken($this->token($rider, ['rider:account', 'rider:settings:emails']));
        $this->patchJson(self::BASE.'/profile', [])->assertForbidden();
        $this->putJson(self::BASE.'/password', [])->assertForbidden();
        $this->patchJson(self::BASE.'/emails/'.$address->id.'/preferred', ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->deleteJson(self::BASE.'/emails/'.$address->id, ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->assertNotNull($address->fresh());
        $this->assertNull($rider->fresh()->preferred_contact_email_id);
    }

    public function test_invalid_preference_falls_back_to_the_original_without_claiming_verification(): void
    {
        $rider = $this->rider(['email_verified_at' => null]);
        $pending = $rider->accountEmails()->create(['email' => 'pending@bagoo.test']);
        $rider->forceFill(['preferred_contact_email_id' => $pending->id])->save();
        $this->withToken($this->token($rider))->getJson(self::BASE)->assertOk()->assertJsonPath('data.emails.0.preferred', true)
            ->assertJsonPath('data.emails.0.verified', false)->assertJsonPath('data.emails.1.preferred', false);
    }

    public function test_fresh_restriction_and_password_checks_inside_the_shared_email_service_deny_stale_actors(): void
    {
        $rider = $this->rider();
        $token = $this->token($rider);
        $request = Request::create(self::BASE.'/emails/send', 'POST', $this->emailPayload());
        $request->headers->set('Authorization', 'Bearer '.$token);
        $request->attributes->set('rider_settings_ability', 'rider:settings:emails');
        $request->setUserResolver(fn () => $rider);
        User::whereKey($rider->id)->update(['status' => 'suspended']);
        try {
            app(AccountEmailService::class)->send($request);
            $this->fail('Fresh account restrictions must deny the stale actor.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_api_errors_are_json_without_an_accept_header_and_never_redirect_to_web_login(): void
    {
        $rider = $this->rider();
        $this->withToken($this->token($rider))->withHeader('Accept', 'text/html')->patch(self::BASE.'/profile', ['phone' => 'bad', 'revision' => str_repeat('a', 64)])
            ->assertStatus(409)->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
        $this->withToken('invalid')->get(self::BASE)->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
    }

    public function test_incomplete_deployment_does_not_advertise_settings_or_issue_new_authority(): void
    {
        $rider = $this->rider();
        Schema::partialMock()->shouldReceive('hasColumn')->with('users', 'contact_settings_version')->andReturn(false);
        $response = $this->postJson('/api/v1/auth/tokens', ['email' => $rider->email, 'password' => 'Password1234', 'device_name' => 'Pre-migration device'])->assertOk()->assertJsonMissingPath('data.user.settings_api_version');
        $token = $response->json('data.token');
        $this->assertSame(['rider:account', 'rider:logout'], PersonalAccessToken::findToken($token)->abilities);
        $this->withToken($token)->getJson('/api/v1/rider/me')->assertOk()->assertJsonMissingPath('data.settings_api_version');
        $this->getJson(self::BASE)->assertStatus(503);
    }
}
