<?php

namespace Tests\Feature\Auth;

use App\Models\AccountPolicyAcceptance;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\OtpService;
use App\Services\RegistrationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RegistrationPolicyTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase, WithRegistrationPolicies;

    private function application(string $role): array
    {
        return $this->registrationPolicies() + [
            'role' => $role, 'name' => 'Bagoo Applicant', 'email' => 'applicant@bagoo.test',
            'password' => 'BagooPassword2026!', 'password_confirmation' => 'BagooPassword2026!',
            'birthday' => '2000-01-01', 'phone' => '09171234567', 'address' => '12 Bagoo Street', 'city' => 'Makati',
            'shop_name' => 'Bagoo Shop', 'root_category_id' => $this->validMasterCategory()->id,
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'AB-12345', 'license_number' => 'N01-12-345678',
            'company_name' => 'Bagoo Logistics', 'company_code' => 'BGL', 'fleet_size' => '1',
            'vehicle_types' => ['motorcycle'],
            ...array_fill_keys(['id_document', 'business_permit', 'driver_license', 'or_cr_document'], UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\nBagoo evidence\n%%EOF")),
        ];
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_acknowledgement_and_current_versions_are_required_before_uploads_or_accounts(string $role): void
    {
        Storage::fake('local');
        $data = $this->application($role);
        foreach ([null, false, 0, 'false', ['true']] as $invalid) {
            $this->postJson('/register/validate', array_replace($data, ['policy_accepted' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('policy_accepted');
            $this->post('/register', array_replace($data, ['policy_accepted' => $invalid]))->assertSessionHasErrors('policy_accepted');
        }
        foreach (['terms_version', 'privacy_version'] as $field) {
            $this->postJson('/register/validate', array_replace($data, [$field => 'old']))->assertJsonValidationErrors($field);
            $this->postJson('/register', array_replace($data, [$field => ['wrong']]))->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('roles')]
    public function test_each_role_records_the_current_versions_and_server_time_with_its_account(string $role): void
    {
        Storage::fake('local');
        $this->freezeSecond();
        $this->post('/register', $this->application($role) + ['accepted_at' => '1990-01-01'])->assertSessionHasNoErrors();
        $user = User::where('email', 'applicant@bagoo.test')->sole();
        $acceptance = AccountPolicyAcceptance::sole();
        $this->assertSame($user->id, $acceptance->user_id);
        $this->assertSame(RegistrationPolicyService::TERMS_VERSION, $acceptance->terms_version);
        $this->assertSame(RegistrationPolicyService::PRIVACY_VERSION, $acceptance->privacy_version);
        $this->assertTrue($acceptance->accepted_at->equalTo(now()));
        $this->assertSame('web', $acceptance->source);
        $this->assertSame($role === 'buyer' ? 'active' : 'pending_approval', $user->status);
        $this->assertSame('pending_approval', $user->kyc_status);
    }

    public function test_a_failed_acceptance_write_rolls_back_the_account_files_and_otp_token(): void
    {
        Storage::fake('local');
        $token = str_repeat('a', 64);
        EmailOtp::create(['email' => 'applicant@bagoo.test', 'purpose' => 'registration', 'code_hash' => 'unused', 'expires_at' => now()->addMinutes(10), 'verified_at' => now(), 'token' => $token]);
        $this->partialMock(RegistrationPolicyService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Acceptance storage unavailable.'));
        $this->postJson('/register', $this->application('seller') + ['otp_token' => $token])->assertServerError();
        foreach (['users', 'shops', 'account_policy_acceptances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertTrue(app(OtpService::class)->hasValidToken('applicant@bagoo.test', $token, 'registration'));
    }

    public function test_preflight_and_failed_retries_cannot_manufacture_acceptance_records(): void
    {
        Storage::fake('local');
        $data = $this->application('buyer');
        $this->postJson('/register/validate', $data)->assertOk();
        $this->postJson('/register/validate', $data)->assertOk();
        $this->assertDatabaseCount('account_policy_acceptances', 0);
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_policy_acceptances', 1);
    }

    public function test_public_policy_pages_work_on_each_role_host_and_unknown_versions_are_not_misrepresented(): void
    {
        foreach (['', 'seller.', 'courier.', 'hub.', 'admin.'] as $host) {
            foreach (['terms' => 'Terms of Use', 'privacy' => 'Privacy Notice'] as $kind => $title) {
                $this->get('http://'.$host.'localhost/'.$kind.'/2026-10-12')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Policy')->where('document.title', $title)->where('document.version', '2026-10-12')->has('document.sections'));
                $this->get('http://'.$host.'localhost/'.$kind.'/old')->assertNotFound();
            }
        }
    }

    public function test_existing_accounts_do_not_receive_invented_historical_acceptance(): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'password' => 'Password1234']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Password1234'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('account_policy_acceptances', 0);
    }

    public function test_policy_history_is_retained_by_the_existing_account_closure_review(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        app(RegistrationPolicyService::class)->record($user, 'web');
        $closure = app(AccountClosureService::class);
        $this->assertFalse($closure->canHardDelete($user));
        $state = $closure->state($user);
        $this->assertSame('retained', $state['outcome']);
        $this->assertContains('account_policy_acceptances', array_column($state['references'], 'table'));
        $this->assertDatabaseCount('account_policy_acceptances', 1);
    }
}
