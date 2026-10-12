<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestRequestFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public static function roleHosts(): array
    {
        return [['buyer', ''], ['seller', 'seller.'], ['courier', 'courier.'], ['logistics', 'hub.']];
    }

    #[DataProvider('roleHosts')]
    public function test_existing_session_on_a_role_registration_form_gets_json_feedback_instead_of_an_html_redirect(string $role, string $prefix): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($user)->get('http://'.$prefix.'localhost/register')->assertOk();
        $this->post('http://'.$prefix.'localhost/register/validate', ['role' => $role, 'step' => 1], ['Accept' => 'application/json'])
            ->assertConflict()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'You are already signed in. Sign out before continuing with this form.');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_registration_otp_with_an_existing_session_does_not_send_mail_or_redirect_to_html(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($user)->postJson('/api/otp/send', ['email' => 'new-person@bagoo.test'])
            ->assertConflict()->assertJsonPath('message', 'You are already signed in. Sign out before continuing with this form.');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('email_otps', 0);
        Mail::assertNotSent(OtpVerificationMail::class);
    }

    public function test_normal_guest_page_navigation_retains_its_authenticated_redirect(): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'kyc_status' => 'approved']);
        $this->actingAs($user)->get('/courier/register')->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
