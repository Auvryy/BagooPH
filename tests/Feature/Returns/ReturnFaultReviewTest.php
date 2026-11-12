<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnCase;
use App\Models\User;
use App\Services\Returns\BuyerReturnService;
use App\Services\Returns\ReturnFaultReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class ReturnFaultReviewTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function requestCase(): BuyerReturnCase
    {
        $order = $this->flowDelivery($this->newFlowOrder(), 'delivered')->order->fresh(['items', 'buyer']);
        $service = app(BuyerReturnService::class);

        return $service->request($order->buyer, $order, ['request_token' => $service->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered item has a broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('evidence.png')]);
    }

    private function payload(BuyerReturnCase $case, array $extra = []): array
    {
        return ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original item, parcel and private evidence were independently reviewed.', ...$extra];
    }

    public function test_seller_fault_approval_records_a_finding_without_a_pickup_or_money_transfer(): void
    {
        $case = $this->requestCase();
        $seller = User::findOrFail($case->seller_id);
        $before = [$case->delivery->getAttributes(), $case->order->getAttributes()];
        $this->actingAs($seller)->post('/buyer-returns/'.$case->id.'/actions', ['action' => 'approve', ...$this->payload($case, ['fault' => 'seller'])])->assertSessionHasNoErrors();
        $case->refresh();
        $review = app(ReturnFaultReviewService::class)->current($case);
        $this->assertSame('seller', $review['fault']);
        $this->assertSame('seller', $review['actor_role']);
        $this->assertSame('approved', $case->status);
        $this->assertSame('awaiting_dropoff', $case->parcel->status);
        $this->assertSame(['kind' => 'buyer', 'user_id' => $case->buyer_id], $case->parcel->custody);
        $this->assertSame($before, [$case->delivery->fresh()->getAttributes(), $case->order->fresh()->getAttributes()]);
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
        $this->get('/buyer-returns/'.$case->id)->assertInertia(fn (AssertableInertia $page) => $page->where('returnCase.fault_review.fault', 'seller'));
    }

    public function test_the_return_page_reads_its_purchased_policy_without_exposing_the_order_snapshot(): void
    {
        $case = $this->requestCase();
        // This presentation fixture exercises the successor reader before checkout activation.
        $order = $case->order;
        $order->shipping_quote = array_replace($order->shipping_quote, ['refund_policy_version' => 2]);
        $order->save();
        $this->actingAs(User::findOrFail($case->seller_id))->get('/buyer-returns/'.$case->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnCase.refund_policy_version', 2)->missing('returnCase.order.shipping_quote'));
    }

    public function test_only_independent_admin_review_can_assign_the_original_courier_company(): void
    {
        $case = $this->requestCase();
        $seller = User::findOrFail($case->seller_id);
        $input = $this->payload($case, ['fault' => 'courier', 'fault_evidence' => 'Original intake and sealed transfer observations were checked.']);
        $this->actingAs($seller)->post('/buyer-returns/'.$case->id.'/actions', ['action' => 'approve', ...$input])->assertForbidden();
        $this->assertSame('requested', $case->fresh()->status);
        $service = app(BuyerReturnService::class);
        $service->command($seller, $case, 'approve', $this->payload($case, ['fault' => 'seller']));
        $original = $case->events()->where('event_type', 'approve')->sole()->getAttributes();
        $admin = $this->createApprovedUser('admin');
        $input = $this->payload($case, ['fault' => 'courier', 'fault_evidence' => 'The original transfer receipt records the observed transit damage.']);
        $event = $service->command($admin, $case, 'review-fault', $input);
        $this->assertSame($event->id, $service->command($admin, $case, 'review-fault', $input)->id);
        $this->assertSame('approved', $case->fresh()->status);
        $this->assertSame($original, $case->events()->where('event_type', 'approve')->sole()->getAttributes());
        $review = app(ReturnFaultReviewService::class)->current($case->fresh());
        $this->assertSame('courier', $review['fault']);
        $this->assertSame($case->delivery->logistics_company_id, $review['company_id']);
        $this->actingAs(User::findOrFail($case->buyer_id))->post('/buyer-returns/'.$case->id.'/actions', ['action' => 'review-fault', ...$this->payload($case, ['fault' => 'seller'])])->assertForbidden();
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public static function invalidEvidence(): array
    {
        return [['short', '123456789'], ['long', str_repeat('a', 1001)], ['hidden', "Original\u{200B} evidence"], ['empty', '']];
    }

    #[DataProvider('invalidEvidence')]
    public function test_courier_finding_requires_meaningful_bounded_evidence(string $label, string $evidence): void
    {
        $case = $this->requestCase();
        $service = app(BuyerReturnService::class);
        $service->command(User::findOrFail($case->seller_id), $case, 'approve', $this->payload($case));
        $this->actingAs($this->createApprovedUser('admin'))->post('/buyer-returns/'.$case->id.'/actions', ['action' => 'review-fault',
            ...$this->payload($case, ['fault' => 'courier', 'fault_evidence' => $evidence])])->assertSessionHasErrors('fault_evidence');
        $this->assertSame('unspecified', app(ReturnFaultReviewService::class)->current($case->fresh())['fault']);
    }
}
