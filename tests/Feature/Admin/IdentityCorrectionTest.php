<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\IdentityCorrectionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\IdentityCorrectionService;
use App\Services\ShopEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\TestCase;

class IdentityCorrectionTest extends TestCase
{
    use InteractsWithKycReviews, RefreshDatabase;

    private function subject(string $role = 'buyer', string $status = 'active'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status, 'birthday' => '2000-01-01', 'phone' => '+639171234567', 'address' => 'Bagoo Test Street', 'city' => 'Makati']);
        if ($role === 'seller') {
            Shop::create(['user_id' => $user->id, 'name' => 'Bagoo Reviewed Shop', 'slug' => 'reviewed-'.$user->id, 'root_category_id' => $this->validMasterCategory()->id,
                'phone' => $user->phone, 'address' => $user->address, 'city' => $user->city, 'status' => 'suspended']);
        } elseif ($role === 'courier') {
            CourierProfile::create(['user_id' => $user->id, 'vehicle_type' => 'Motorcycle', 'plate_number' => 'BAG-123', 'is_available' => true]);
        } elseif ($role === 'logistics') {
            LogisticsCompany::create(['user_id' => $user->id, 'name' => 'Bagoo Reviewed Company', 'slug' => 'reviewed-'.$user->id, 'code' => 'BG'.$user->id,
                'contact_phone' => $user->phone, 'contact_email' => $user->email, 'address' => $user->address, 'status' => 'suspended', 'is_active' => false]);
        }
        if ($role === 'admin') {
            Storage::fake('local');
            Storage::disk('local')->put('kyc_documents/admin.pdf', '%PDF-1.4 Bagoo admin evidence');
            $user->update(['id_document_path' => 'kyc_documents/admin.pdf']);
        } else {
            $this->addKycEvidence($user);
        }
        if ($role === 'seller') {
            $user->shop->update(['business_permit_path' => $user->business_permit_path]);
        }

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['birthday' => '1990-01-01']);
    }

    private function payload(User $subject, array $changes = ['birthday' => '1999-04-03']): array
    {
        return ['source_token' => app(IdentityCorrectionService::class)->form($subject, $subject)['source_token'],
            'changes' => $changes, 'reason' => 'Correct the mistake shown on the identity document.'];
    }

    private function submit(User $subject, array $changes = ['birthday' => '1999-04-03']): IdentityCorrectionRequest
    {
        $this->actingAs($subject)->post('/account/identity-corrections', $this->payload($subject, $changes))->assertSessionHasNoErrors()->assertSessionHas('success');

        return IdentityCorrectionRequest::latest('id')->firstOrFail();
    }

    private function inspect(User $admin, IdentityCorrectionRequest $correction): array
    {
        foreach (app(IdentityCorrectionService::class)->presentation($correction)['documents'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }

        return ['action' => 'approve', 'review_token' => app(IdentityCorrectionService::class)->reviewToken($correction),
            'reason' => 'Current evidence confirms this correction.', 'affected_work_confirmed' => true];
    }

    public static function rolesAndRoutes(): array
    {
        $data = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['/admin/identity-corrections', 'http://admin.localhost/identity-corrections'] as $base) {
                $data[] = [$role, $base];
            }
        }

        return $data;
    }

    #[DataProvider('rolesAndRoutes')]
    public function test_evidenced_correction_is_separate_and_preserves_restrictions(string $role, string $base): void
    {
        $subject = $this->subject($role, 'suspended');
        $admin = $this->admin();
        $correction = $this->submit($subject);
        $this->assertSame('2000-01-01', $subject->fresh()->birthday->toDateString());
        $this->assertSame('legacy_without_recorded_review', $correction->provenance['source']);
        $profileBefore = $subject->courierProfile?->getAttributes();
        $companyBefore = $subject->logisticsCompany?->getAttributes();
        $this->post($base.'/'.$correction->id.'/decision', $this->inspect($admin, $correction))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('1999-04-03', $subject->fresh()->birthday->toDateString());
        $this->assertSame('suspended', $subject->fresh()->status);
        $this->assertSame($subject->kyc_status, $subject->fresh()->kyc_status);
        $this->assertSame($profileBefore, $subject->fresh()->courierProfile?->getAttributes());
        $this->assertSame($companyBefore, $subject->fresh()->logisticsCompany?->getAttributes());
        $this->assertDatabaseCount('identity_correction_decisions', 1);
        $this->assertSame('2000-01-01', IdentityCorrectionDecision::sole()->before_state['identity']['values']['birthday']);
        $this->assertSame('1999-04-03', IdentityCorrectionDecision::sole()->after_state['identity']['values']['birthday']);
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public static function invalidProposals(): array
    {
        return [[['birthday' => null]], [['birthday' => '2026-02-30']], [['birthday' => '2099-01-01']], [['name' => '<script>name</script>']], [['role' => 'admin']], [['age' => 44]], [['status' => 'active']], ['not an array'], [[]]];
    }

    #[DataProvider('invalidProposals')]
    public function test_invalid_proposals_leave_identity_and_files_intact(mixed $changes): void
    {
        $subject = $this->subject();
        $before = $subject->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $this->actingAs($subject)->post('/account/identity-corrections', array_replace($this->payload($subject), ['changes' => $changes]))->assertSessionHasErrors();
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('identity_correction_requests', 0);
    }

    public function test_missing_evidence_needs_real_upload_without_guessing_legacy_date(): void
    {
        $subject = $this->subject();
        $subject->update(['birthday' => null]);
        Storage::disk('local')->delete($subject->id_document_path);
        $payload = $this->payload($subject);
        $this->actingAs($subject)->post('/account/identity-corrections', $payload)->assertSessionHasErrors('evidence');
        $this->assertNull($subject->fresh()->birthday);
        $payload['id_document'] = UploadedFile::fake()->createWithContent('evidence.pdf', '%PDF-1.4 current Bagoo identity evidence');
        $this->post('/account/identity-corrections', $payload)->assertSessionHas('success');
        $correction = IdentityCorrectionRequest::sole();
        $this->assertNull($correction->provenance['kyc_decision_id']);
        $this->assertNull($subject->fresh()->birthday);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($this->admin(), $correction))->assertSessionHas('success');
        $this->assertSame('1999-04-03', $subject->fresh()->birthday->toDateString());
    }

    public function test_original_review_and_private_evidence_survive_correction(): void
    {
        $subject = $this->subject();
        $subject->update(['kyc_status' => 'pending_approval', 'status' => 'pending_approval']);
        $admin = $this->admin();
        $this->post('/admin/kyc/'.$subject->id.'/approve', $this->prepareKycReview($admin, $subject))->assertSessionHas('success');
        $prior = KycDecision::sole()->getAttributes();
        $correction = $this->submit($subject->fresh());
        $this->assertSame(KycDecision::sole()->id, $correction->provenance['kyc_decision_id']);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($admin, $correction))->assertSessionHas('success');
        $this->assertSame($prior, KycDecision::sole()->getAttributes());
        $this->get('/verification-documents/'.$subject->id.'/id.pdf?decision='.KycDecision::sole()->id)->assertOk();
    }

    public function test_shop_correction_has_current_review_and_preserves_restriction_and_listings(): void
    {
        $subject = $this->subject('seller');
        $shop = $subject->shop;
        $other = Category::create(['name' => 'Electronics and Gadgets', 'slug' => 'electronics-gadgets', 'is_active' => true]);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $before = $product->fresh()->getAttributes();
        $correction = $this->submit($subject, ['root_category_id' => $other->id, 'shop_name' => 'Bagoo Corrected Shop']);
        $admin = $this->admin();
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($admin, $correction))->assertSessionHas('success');
        $this->assertSame($other->id, $shop->fresh()->root_category_id);
        $this->assertSame('suspended', $shop->fresh()->status);
        $review = ShopReviewDecision::sole();
        $this->assertSame($correction->id, $review->identity_correction_request_id);
        $this->assertSame($review->id, $shop->fresh()->review_decision_id);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertFalse(app(ShopEligibilityService::class)->productIsEligible($product->fresh()));
    }

    public function test_underage_correction_blocks_new_work_and_preserves_existing_orders(): void
    {
        $subject = $this->subject('courier');
        $order = Order::factory()->create(['status' => 'out_for_delivery']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'courier_id' => $subject->id, 'assigned_rider_id' => $subject->id]);
        $before = [$order->fresh()->getAttributes(), $delivery->fresh()->getAttributes(), $subject->courierProfile->getAttributes()];
        $correction = $this->submit($subject, ['birthday' => '2016-01-01']);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($this->admin(), $correction))->assertSessionHas('success');
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->assertSame('active', $subject->fresh()->status);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $delivery->fresh()->getAttributes(), $subject->fresh()->courierProfile->getAttributes()]);
        $this->assertCount(1, IdentityCorrectionDecision::sole()->after_state['work']['work']);
    }

    public function test_last_eligible_admin_requires_controlled_replacement(): void
    {
        $subject = $this->subject('admin');
        $correction = $this->submit($subject, ['birthday' => '2016-01-01']);
        $payload = $this->inspect($subject, $correction);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertConflict();
        $this->assertTrue($subject->fresh()->canAccessPortal());
        $this->assertDatabaseCount('identity_correction_decisions', 0);
        $replacement = $this->admin();
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($replacement, $correction))->assertSessionHas('success');
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->assertTrue($replacement->fresh()->canAccessPortal());
    }

    public function test_identical_and_competing_decisions_are_idempotent(): void
    {
        $subject = $this->subject();
        $input = $this->payload($subject);
        $this->actingAs($subject)->post('/account/identity-corrections', $input)->assertSessionHas('success');
        $this->post('/account/identity-corrections', $input)->assertSessionHas('success');
        $this->assertDatabaseCount('identity_correction_requests', 1);
        $first = IdentityCorrectionRequest::sole();
        $second = $this->submit($subject, ['birthday' => '1998-01-01']);
        $admin = $this->admin();
        $payload = $this->inspect($admin, $first);
        $this->post('/admin/identity-corrections/'.$first->id.'/decision', $payload)->assertSessionHas('success');
        $record = IdentityCorrectionDecision::sole()->getAttributes();
        $this->post('/admin/identity-corrections/'.$first->id.'/decision', $payload)->assertSessionHas('success');
        $this->post('/admin/identity-corrections/'.$first->id.'/decision', array_replace($payload, ['reason' => 'A competing reason with different intent.']))->assertConflict();
        $this->post('/admin/identity-corrections/'.$second->id.'/decision', $this->inspect($admin, $second))->assertConflict();
        $this->assertSame($record, IdentityCorrectionDecision::sole()->getAttributes());
        $this->assertSame('1999-04-03', $subject->fresh()->birthday->toDateString());
    }

    public function test_current_private_evidence_and_reviewer_inspection_are_required(): void
    {
        $subject = $this->subject();
        $correction = $this->submit($subject);
        $admin = $this->admin();
        $payload = ['action' => 'approve', 'review_token' => app(IdentityCorrectionService::class)->reviewToken($correction), 'reason' => 'Current evidence confirms the correction.', 'affected_work_confirmed' => true];
        $this->actingAs($admin)->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertSessionHasErrors('review');
        $payload = $this->inspect($admin, $correction);
        $this->actingAs($this->admin())->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertSessionHasErrors('review');
        Storage::disk('local')->put($correction->evidence['id']['path'], '%PDF-1.4 changed evidence');
        $this->actingAs($admin)->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertConflict();
        $this->get(app(IdentityCorrectionService::class)->presentation($correction)['documents']['id'])->assertConflict();
        $this->assertDatabaseCount('identity_correction_decisions', 0);
    }

    public function test_stale_reviewer_and_foreign_evidence_are_denied(): void
    {
        $subject = $this->subject();
        $correction = $this->submit($subject);
        $url = app(IdentityCorrectionService::class)->presentation($correction)['documents']['id'];
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->get('/admin/users/'.$subject->id.'/identity-corrections')->assertForbidden();
        $admin = $this->admin();
        $payload = $this->inspect($admin, $correction);
        User::whereKey($admin->id)->update(['status' => 'suspended']);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertRedirect();
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->assertDatabaseCount('identity_correction_decisions', 0);
    }

    public function test_rejection_preserves_identity_and_reports_the_recorded_reason(): void
    {
        $subject = $this->subject();
        $correction = $this->submit($subject);
        $before = $subject->getAttributes();
        $payload = $this->inspect($this->admin(), $correction);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', array_replace($payload, ['action' => 'reject', 'reason' => 'The supplied document does not support this change.']))->assertSessionHas('success');
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertSame('reject', app(IdentityCorrectionService::class)->presentation($correction->fresh())['decision']['action']);
    }

    public function test_failed_audit_rolls_back_every_identity_and_review_change(): void
    {
        $subject = $this->subject('seller');
        $shopBefore = $subject->shop->getAttributes();
        $before = $subject->getAttributes();
        $correction = $this->submit($subject, ['shop_name' => 'Bagoo Corrected Name', 'birthday' => '1999-01-01']);
        $payload = $this->inspect($this->admin(), $correction);
        Event::listen('eloquent.creating: '.IdentityCorrectionDecision::class, fn () => throw new RuntimeException('Audit storage failed.'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }
        $this->assertSame($before, $subject->fresh()->getAttributes());
        $this->assertSame($shopBefore, $subject->fresh()->shop->getAttributes());
        $this->assertDatabaseCount('shop_review_decisions', 0);
        $this->assertDatabaseCount('identity_correction_decisions', 0);
    }

    public function test_sql_cannot_change_or_delete_request_evidence(): void
    {
        $correction = $this->submit($this->subject());
        $this->expectException(QueryException::class);
        DB::table('identity_correction_requests')->where('id', $correction->id)->update(['reason' => 'Tampered request.']);
    }

    public function test_generic_profile_cannot_replace_reviewed_name(): void
    {
        $subject = $this->subject();
        $this->actingAs($subject)->patch('/profile', ['name' => 'Replacement Identity', 'email' => $subject->email])->assertSessionHasErrors('name');
        $this->assertSame($subject->name, $subject->fresh()->name);
        $this->post('/buyer/profile', ['name' => 'Replacement Identity', 'phone' => $subject->phone])->assertSessionHasErrors('name');
        $this->assertSame($subject->name, $subject->fresh()->name);
    }

    public static function profileRoutes(): array
    {
        return [['seller', '/seller/profile', 'post'], ['seller', 'http://seller.localhost/profile', 'post'],
            ['courier', '/courier/profile/account', 'patch'], ['courier', 'http://courier.localhost/profile/account', 'patch'],
            ['buyer', '/buyer/profile', 'post'], ['buyer', 'http://localhost/profile', 'patch']];
    }

    #[DataProvider('profileRoutes')]
    public function test_reviewed_name_is_protected_in_every_existing_profile_writer(string $role, string $url, string $method): void
    {
        $subject = $this->subject($role);
        $before = $subject->getAttributes();
        $this->actingAs($subject)->{$method}($url, ['name' => 'Different Reviewed Identity', 'email' => $subject->email, 'phone' => $subject->phone])->assertSessionHasErrors('name');
        $this->assertSame($before, $subject->fresh()->getAttributes());
    }

    public function test_own_feedback_and_admin_candidates_expose_only_authorized_links(): void
    {
        $subject = $this->subject('courier');
        $correction = $this->submit($subject);
        $this->actingAs($subject)->get('/account/identity-corrections')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Governance/IdentityCorrection')->where('subject.id', $subject->id)->where('subject.requests.0.id', $correction->id)
            ->missing('subject.requests.0.evidence')->missing('subject.requests.0.source')->where('adminReview', false));
        $subject->update(['birthday' => null]);
        $this->actingAs($this->admin())->get('/admin/identity-corrections')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/IdentityCorrections')->where('candidates.data.0.id', $subject->id)->has('requests.data', 1));
        $this->get('/admin/users/'.$subject->id.'/identity-corrections')->assertOk();
        $this->assertFalse(app(IdentityCorrectionService::class)->presentation($correction)['current']);
    }

    public function test_new_work_after_inspection_requires_current_review(): void
    {
        $subject = $this->subject();
        $correction = $this->submit($subject);
        $admin = $this->admin();
        $payload = $this->inspect($admin, $correction);
        $order = Order::factory()->create(['buyer_id' => $subject->id, 'status' => 'placed']);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $payload)->assertConflict();
        $this->assertDatabaseCount('identity_correction_decisions', 0);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($admin, $correction))->assertSessionHas('success');
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertCount(1, IdentityCorrectionDecision::sole()->after_state['work']['work']);
    }

    public function test_foreign_shop_and_failed_request_preserve_original_and_new_files(): void
    {
        $subject = $this->subject('seller');
        $foreign = Shop::factory()->create();
        $files = Storage::disk('local')->allFiles();
        $payload = $this->payload($subject, ['shop_name' => 'Corrected Shop Identity']);
        $payload['shop_id'] = $foreign->id;
        $payload['id_document'] = UploadedFile::fake()->createWithContent('new.pdf', '%PDF-1.4 Bagoo new evidence');
        $this->actingAs($subject)->post('/account/identity-corrections', $payload)->assertNotFound();
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('identity_correction_requests', 0);
    }

    public function test_corrected_worker_without_a_birth_date_loses_legacy_exception(): void
    {
        $subject = $this->subject('courier');
        $subject->update(['birthday' => null]);
        $this->assertTrue($subject->fresh()->canAccessPortal());
        $correction = $this->submit($subject->fresh(), ['name' => 'Corrected Rider Identity']);
        $this->post('/admin/identity-corrections/'.$correction->id.'/decision', $this->inspect($this->admin(), $correction))->assertSessionHas('success');
        $this->assertFalse($subject->fresh()->canAccessPortal());
        $this->assertFalse(User::eligibleCouriers()->whereKey($subject->id)->exists());
        $this->assertTrue($subject->fresh()->courierProfile->is_available);
    }
}
