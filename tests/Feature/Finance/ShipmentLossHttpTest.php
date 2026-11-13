<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\ShipmentFinancialPlan;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossCash;
use App\Services\Finance\ShipmentLossReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossHttpTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public static function sources(): array
    {
        return [['wallet', false, 25000], ['cod', false, 0], ['cod', true, 12500]];
    }

    #[DataProvider('sources')]
    public function test_admin_preview_uses_the_prepared_page_contract_and_retains_only_safe_source_scoped_fields(string $mode, bool $return, int $refund): void
    {
        [$source, $admin, $order] = $this->missing($mode, $return);
        $url = '/shipment-finances/missing/'.$source->id;
        $this->actingAs($admin)->get($url.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossReview')->assertJsonPath('props.order.id', $order->id)
            ->assertJsonPath('props.source.company_name', $source->manifest->company->name)
            ->assertJsonPath('props.basis.buyer_refund_cents', $refund)->assertJsonPath('props.preview', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $safe = ['seller_protection' => $return ? '0.00' : '180.00', 'investigation_reference' => 'ACTUAL-INDEPENDENT-REVIEW'];
        $this->post($url.'/preview', $safe + ['reason' => 'Private unfinished text must not enter the cached preview.', 'loss_confirmed' => true])
            ->assertRedirect($url.'/review');
        $saved = session('shipment_loss_preview');
        $this->assertSame($source->id, $saved['observation_id']);
        $this->assertSame($admin->id, $saved['actor_id']);
        $this->assertSame($safe, $saved['value']['input']);
        $this->assertSame(['input', 'review_token', 'amounts'], array_keys($saved['value']));
        $this->assertCount(6, $saved['value']['amounts']);
        $page = $this->get($url.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('props.preview', $saved['value']);
        $this->assertTrue(Str::isUuid($page->json('props.requestToken')));
        $this->assertStringNotContainsString('Private unfinished text', json_encode($page->json(), JSON_THROW_ON_ERROR));
        $this->actingAs($this->createApprovedUser('admin'))->get($url.'/review', $this->inertia())
            ->assertOk()->assertJsonPath('props.preview', null);
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    public function test_browser_approval_reaches_retained_scoped_outcome_and_exact_json_retry_does_not_move_money_or_custody(): void
    {
        [$source, $admin, $order] = $this->missing();
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $proof = UploadedFile::fake()->image('actual-investigation.png');
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), WalletTransaction::count()];
        $this->actingAs($admin)->post($url.'/preview', $input)->assertRedirect($url.'/review');
        $this->post($url.'/approve', $input + ['proof' => $proof])->assertRedirect('/shipment-finances/1')->assertSessionHas('success');
        $plan = ShipmentFinancialPlan::sole();
        $this->assertNull(session('shipment_loss_preview'));
        $this->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossOutcome')->assertJsonPath('props.loss.status', 'awaiting_payment')
            ->assertJsonPath('props.loss.amounts.Buyer refund', 25000)
            ->assertJsonPath('props.loss.amounts.Separate seller protection', 18000)
            ->assertJsonPath('props.loss.history.0.event_type', 'approved')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url.'/approve', $input + ['proof' => $proof])->assertCreated()->assertJsonPath('plan.id', $plan->id);
        $this->get($url.'/review')->assertRedirect('/shipment-finances/'.$plan->id);
        $this->assertDatabaseCount('shipment_financial_plans', 1);
        $this->assertDatabaseCount('shipment_financial_events', 1);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), WalletTransaction::count()]);
    }

    public function test_review_rejects_stale_actual_receipt_and_clears_only_its_own_preview_without_creating_approval(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $this->actingAs($admin)->post($url.'/preview', $input)->assertRedirect($url.'/review');
        $manifest = $source->manifest->fresh();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->openFlowMotherBags($manifest, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest,
            ['barcode' => $order->delivery->tracking_number, 'notes' => 'The original missing parcel was actually found and received.']))->assertOk();
        $this->actingAs($admin)->postJson($url.'/approve', $input + ['proof' => UploadedFile::fake()->image('stale.png')])->assertStatus(409);
        $this->get($url.'/review', $this->inertia())->assertOk()->assertJsonPath('props.preview', null)
            ->assertJsonPath('props.source', null)->assertJsonPath('props.basis', null)->assertJsonPath('props.blocker', fn ($value) => is_string($value) && $value !== '');
        $this->assertNull(session('shipment_loss_preview'));
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('shipment-finance'));
    }

    public function test_browser_validation_does_not_flash_private_reason_or_confirmation_and_direct_amounts_are_rejected(): void
    {
        [$source, $admin] = $this->missing('cod');
        $url = '/shipment-finances/missing/'.$source->id;
        $input = $this->approval($source, $admin);
        $this->actingAs($admin)->from($url.'/review')->post($url.'/approve', $input)
            ->assertRedirect($url.'/review')->assertSessionHasErrors('proof');
        $this->assertSame([], session()->getOldInput());
        foreach (['company_id' => 1, 'buyer_id' => 1, 'buyer_refund_cents' => 999, 'amount_cents' => 999] as $field => $value) {
            $this->postJson($url.'/preview', $input + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($url.'/approve', $input + ['proof' => UploadedFile::fake()->createWithContent('fake.pdf', 'This is not real PDF evidence.')])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    public function test_original_parties_receive_only_their_own_financial_summary_and_independent_proof_remains_admin_only(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('private-review.png'));
        $url = '/shipment-finances/'.$plan->id;
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        foreach ([$order->buyer, $order->shop->user, $owner] as $actor) {
            $this->actingAs($actor)->get($url, $this->inertia())->assertOk()->assertJsonPath('props.loss.source', null)
                ->assertJsonPath('props.loss.review_reason', null)->assertJsonPath('props.loss.review_proof_url', null);
            $this->get($url.'/proof')->assertNotFound();
            foreach (['review', 'preview', 'approve'] as $action) {
                $review = '/shipment-finances/missing/'.$source->id.'/'.$action;
                ($action === 'review' ? $this->getJson($review) : $this->postJson($review, []))->assertForbidden();
            }
        }
        $this->actingAs($order->buyer)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts', ['Buyer refund' => 0]);
        $this->actingAs($order->shop->user)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts', ['Separate seller protection' => 18000]);
        $owner->update(['status' => 'suspended']);
        $this->actingAs($owner)->get($url, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.amounts.Company funding outstanding', 18833);
        foreach (['buyer', 'seller', 'logistics'] as $role) {
            $this->actingAs($this->createApprovedUser($role))->get($url, $this->inertia())->assertNotFound();
        }
        $floor = $this->flowHandler(LogisticsHub::findOrFail($source->manifest->destination_hub_id));
        $this->actingAs($floor)->get($url, $this->inertia())->assertNotFound();
        $this->actingAs($admin)->get($url.'/proof')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url.'/events/'.$plan->events()->sole()->id.'/proof')->assertNotFound();
        Storage::disk('local')->put($plan->proof_path, 'Changed bytes must not download as the reviewed evidence.');
        $this->get($url.'/proof')->assertNotFound();
        $this->get($url, $this->inertia())->assertOk();
    }

    public function test_company_and_seller_cash_evidence_are_isolated_even_when_they_share_the_same_original_loss(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('private-review.png'));
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $receiver = $this->createApprovedUser('admin');
        $service = app(ShipmentLossCash::class);
        $command = function ($actor, string $action, array $extra) use ($service, $plan) {
            return $service->command($actor, $plan, $action, $extra + ['expected_version' => (int) $plan->events()->max('sequence'),
                'request_token' => (string) Str::uuid(), 'reason' => 'Checked the actual original company transfer and its separate recipient evidence.',
                'payment_confirmed' => true], UploadedFile::fake()->image('actual-'.$action.'.png'));
        };
        $offer = $command($owner, 'offer-company', ['amount' => '188.33', 'recipient_id' => $receiver->id, 'cash_reference' => 'PRIVATE-COMPANY-TRANSFER']);
        $received = $command($receiver, 'receive-company', ['source_event_id' => $offer->id]);
        $payment = $command($admin, 'record-seller-protection', ['cash_reference' => 'PRIVATE-SELLER-PROTECTION']);
        $url = '/shipment-finances/'.$plan->id;
        foreach ([$owner, $order->shop->user, $order->buyer] as $actor) {
            $company = $actor->id === $owner->id;
            $seller = $actor->id === $order->shop->user_id;
            foreach ([$offer, $received, $payment] as $event) {
                $allowed = $company ? $event->id !== $payment->id : ($seller && $event->id === $payment->id);
                $response = $this->actingAs($actor)->get($url.'/events/'.$event->id.'/proof');
                $allowed ? $response->assertOk() : $response->assertNotFound();
            }
            $page = $this->get($url, $this->inertia())->assertOk();
            $history = $page->json('props.loss.history');
            $this->assertSame($company ? [1, $offer->id, $received->id] : ($seller ? [1, $payment->id] : [1]), array_column($history, 'id'));
            $this->assertStringNotContainsString($company ? 'PRIVATE-SELLER-PROTECTION' : 'PRIVATE-COMPANY-TRANSFER', json_encode($page->json(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($plan->proof_path, json_encode($page->json(), JSON_THROW_ON_ERROR));
        }
        $this->actingAs($admin)->get($url.'/events/'.$payment->id.'/proof')->assertOk();
    }

    public function test_voided_unfunded_review_retains_history_without_presenting_its_cancelled_company_obligation_as_due(): void
    {
        [$source, $admin] = $this->missing('cod');
        $review = app(ShipmentLossReview::class);
        $plan = $review->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('original-review.png'));
        $review->voidUnfunded($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'unfunded_void_confirmed' => true, 'reason' => 'Retain this unfunded superseded investigation for an independently evidenced new review.']);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $this->actingAs($owner)->get('/shipment-finances/'.$plan->id, $this->inertia())->assertOk()
            ->assertJsonPath('props.loss.status', 'voided')->assertJsonPath('props.loss.amounts.Company funding outstanding', 0)
            ->assertJsonCount(2, 'props.loss.history')->assertJsonPath('props.loss.history.1.event_type', 'voided');
        $this->actingAs($admin)->get('/shipment-finances/missing/'.$source->id.'/review', $this->inertia())->assertOk()
            ->assertJsonPath('component', 'Finance/LossReview')->assertJsonPath('props.preview', null);
        $this->assertDatabaseCount('shipment_financial_plans', 1);
    }
}
