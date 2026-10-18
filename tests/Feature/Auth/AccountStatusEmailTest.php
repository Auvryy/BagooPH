<?php

namespace Tests\Feature\Auth;

use App\Mail\AccountStatusMail;
use App\Models\KycDecision;
use App\Models\NotificationDelivery;
use App\Models\RestrictionDecision;
use App\Models\User;
use App\Services\Notifications\AccountStatusDeliveryService;
use App\Services\Notifications\GovernanceNoticeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountStatusEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
        Mail::fake();
    }

    private function review(User $user, string $decision): KycDecision
    {
        $admin = User::factory()->admin()->create();

        return DB::transaction(fn () => KycDecision::create([
            'user_id' => $user->id, 'reviewer_id' => $admin->id, 'reviewer_role' => 'admin', 'reviewer_name' => $admin->name,
            'subject_role' => $user->role, 'submission_token' => hash('sha256', $user->id.$decision),
            'decision' => $decision, 'reason' => $decision === 'rejected' ? 'Please provide a readable identity document.' : null,
            'submission' => [], 'before_state' => [], 'after_state' => [], 'reviewed_at' => now(),
        ]));
    }

    private function restriction(User $user, string $action): RestrictionDecision
    {
        $admin = User::factory()->admin()->create();

        return RestrictionDecision::create(['subject_type' => 'account', 'subject_id' => $user->id,
            'actor_id' => $admin->id, 'actor_role' => 'admin', 'actor_name' => $admin->name,
            'source_token' => hash('sha256', $user->id.$action), 'action' => $action,
            'reason' => 'Internal review note stays in the admin record.', 'owner_feedback' => 'Please confirm your current account details.', 'before_state' => [], 'after_state' => [], 'decided_at' => now()]);
    }

    public static function reviews(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            foreach (['approved', 'rejected'] as $decision) {
                $cases[] = [$role, $decision];
            }
        }

        return $cases;
    }

    #[DataProvider('reviews')]
    public function test_every_public_roles_review_delivers_one_owned_notice_and_email(string $role, string $decision): void
    {
        $user = User::factory()->create(['role' => $role]);
        $event = $this->review($user, $decision);
        $delivery = NotificationDelivery::sole();
        $this->assertNotNull($delivery->delivered_at);
        $this->assertTrue($delivery->email_required);
        $this->assertNotNull($delivery->email_delivered_at);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'source_key' => 'governance:kyc_review:'.$event->id]);
        Mail::assertSent(AccountStatusMail::class, fn ($mail) => $mail->hasTo($user->email) && str_contains($mail->title, $decision));
        app(GovernanceNoticeService::class)->record($event);
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public static function restrictions(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['suspend', 'deactivate', 'reactivate'] as $action) {
                $cases[] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictions')]
    public function test_account_activity_decisions_email_every_role_after_commit(string $role, string $action): void
    {
        $user = User::factory()->create(['role' => $role]);
        DB::transaction(function () use ($user, $action): void {
            $this->restriction($user, $action);
            Mail::assertNothingSent();
            $this->assertDatabaseCount('notifications', 0);
        });
        Mail::assertSent(AccountStatusMail::class, fn ($mail) => $mail->hasTo($user->email)
            && str_contains($mail->messageBody, 'Please confirm your current account details.'));
        $this->assertNotNull(NotificationDelivery::sole()->email_delivered_at);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_rolled_back_decision_does_not_send_mail_or_leave_a_delivery_intent(): void
    {
        $user = User::factory()->buyer()->create();
        try {
            DB::transaction(function () use ($user): void {
                $this->restriction($user, 'suspend');
                throw new \RuntimeException('Simulated decision failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated decision failure', $exception->getMessage());
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('restriction_decisions', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_mail_failure_keeps_the_notice_and_retries_without_repeating_the_decision(): void
    {
        $this->freezeTime();
        $user = User::factory()->buyer()->create();
        $originalMailer = Mail::getFacadeRoot();
        Mail::partialMock()->shouldReceive('to')->once()->andThrow(new \RuntimeException('Simulated transport failure'));
        DB::transaction(fn () => $this->restriction($user, 'deactivate'));
        $delivery = NotificationDelivery::sole();
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->email_delivered_at);
        $this->assertSame(1, $delivery->email_attempts);
        $this->assertTrue($delivery->email_available_at->isFuture());
        Mail::swap($originalMailer);
        $this->travel(3)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertNotNull($delivery->fresh()->email_delivered_at);
        $this->assertSame(2, $delivery->fresh()->email_attempts);
        $this->assertDatabaseCount('restriction_decisions', 1);
        $this->assertDatabaseCount('notifications', 1);
        app(AccountStatusDeliveryService::class)->deliver($delivery->id);
        Mail::assertSentCount(1);
    }

    public function test_only_an_owned_verified_contact_can_receive_the_status_email(): void
    {
        $user = User::factory()->buyer()->create();
        $contact = $user->accountEmails()->create(['email' => 'recovery@bagoo.test', 'verified_at' => now()]);
        $user->forceFill(['preferred_contact_email_id' => $contact->id])->save();
        $this->review($user, 'approved');
        Mail::assertSent(AccountStatusMail::class, fn ($mail) => $mail->hasTo($contact->email));
        $mail = Mail::sent(AccountStatusMail::class)->sole();
        $html = $mail->render();
        $this->assertStringContainsString('buyer account', $html);
        $this->assertStringContainsString('Plus Jakarta Sans', $html);
        $this->assertStringNotContainsString('900000001000', $html);
    }
}
