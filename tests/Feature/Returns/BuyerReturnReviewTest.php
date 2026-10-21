<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnEvidence;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class BuyerReturnReviewTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private array $actualPhotoSources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function delivered(bool $complete = false): Order
    {
        $order = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered')->order->fresh(['items', 'buyer', 'delivery']);
        if ($complete) {
            app(OrderLifecycleService::class)->buyerComplete($order, $order->buyer);
        }

        return $order->fresh(['items', 'buyer', 'delivery']);
    }

    private function payload(Order $order, int $quantity = 1): array
    {
        return ['request_token' => app(BuyerReturnService::class)->form($order->buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered item has a broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => $quantity]]];
    }

    private function createReturn(Order $order, int $quantity = 1): BuyerReturnCase
    {
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $this->payload($order, $quantity) + [
            'photos' => [UploadedFile::fake()->image('damage.png')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        return BuyerReturnCase::latest('id')->firstOrFail();
    }

    private function command(BuyerReturnCase $case, string $action): array
    {
        return ['action' => $action, 'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original item and photo evidence were checked carefully.'];
    }

    public function test_an_owned_request_has_private_original_units_and_replays_without_mutating_delivery_or_money(): void
    {
        config(['session.lifetime' => 60 * 24 * 10]);
        $order = $this->delivered();
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $order->items->map->getAttributes()->all()];
        $payload = $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]];
        $url = '/buyer-returns/orders/'.$order->id;
        $this->actingAs($order->buyer)->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $case = BuyerReturnCase::sole();
        $this->assertSame('requested', $case->status);
        $this->assertSame([0], $case->items()->sole()->unit_positions);
        $this->assertSame($order->items->first()->unit_price, $case->items()->sole()->purchase_snapshot['unit_price']);
        $this->assertCount(3, $case->route_snapshot['hub_ids']);
        $this->travel(8)->days();
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect('/buyer-returns/'.$case->id);
        $this->assertSame(1, BuyerReturnCase::count());
        $this->assertSame(1, BuyerReturnEvent::count());
        $this->assertSame(1, BuyerReturnEvidence::count());
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->items()->get()->map->getAttributes()->all()]);
        $this->assertSame(0, SellerSettlement::count());
        $evidence = BuyerReturnEvidence::sole();
        $this->get('/buyer-returns/'.$case->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Returns/Show')
            ->where('returnCase.description', $case->description)->missing('returnCase.creation_token_hash')
            ->missing('returnCase.evidence.0.path')->where('returnCase.items.0.quantity', 1));
        $this->get('/buyer-returns/'.$case->id.'/evidence/'.$evidence->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($order->shop->user)->get('/buyer-returns/'.$case->id.'/evidence/'.$evidence->id)->assertOk();
        $this->actingAs($this->createApprovedUser('buyer'))->get('/buyer-returns/'.$case->id.'/evidence/'.$evidence->id)->assertNotFound();
        $this->actingAs($this->createApprovedUser('admin'))->get('/buyer-returns/'.$case->id.'/evidence/'.$evidence->id)->assertOk();
        $this->actingAs($this->createApprovedUser('logistics'))->get('/buyer-returns/'.$case->id)->assertNotFound();
        Storage::disk('local')->put($evidence->path, 'changed contents');
        $this->actingAs($order->buyer)->get('/buyer-returns/'.$case->id.'/evidence/'.$evidence->id)->assertNotFound();
    }

    public static function window(): array
    {
        return ['delivered before' => [false, 604799, true], 'delivered exact' => [false, 604800, true],
            'delivered after' => [false, 604801, false], 'completed before' => [true, 604799, true],
            'completed exact' => [true, 604800, true], 'completed after' => [true, 604801, false]];
    }

    #[DataProvider('window')]
    public function test_the_seven_day_window_includes_completed_orders(bool $complete, int $seconds, bool $allowed): void
    {
        $order = $this->delivered($complete);
        $payload = $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]];
        $this->travelTo($order->delivery->delivered_at->copy()->addSeconds($seconds));
        $response = $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $payload);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(1, BuyerReturnCase::count());
        } else {
            $response->assertSessionHasErrors('return');
            $this->assertSame(0, BuyerReturnCase::count());
        }
        $this->assertSame($complete ? 'completed' : 'delivered', $order->fresh()->status);
    }

    public function test_status_labels_without_physical_evidence_and_pre_delivery_requests_are_rejected(): void
    {
        $order = $this->newFlowOrder();
        $this->actingAs($order->buyer)->get('/buyer-returns/orders/'.$order->id.'/create')->assertSessionHasErrors('return');
        $order->update(['status' => 'delivered']);
        $order->delivery->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->get('/buyer-returns/orders/'.$order->id.'/create')->assertSessionHasErrors('return');
        $this->assertSame(0, BuyerReturnCase::count());
    }

    public function test_units_cannot_be_overclaimed_and_withdrawal_releases_only_unused_units(): void
    {
        $order = $this->delivered();
        $first = $this->createReturn($order);
        $second = $this->createReturn($order);
        $this->assertSame([1], $second->items()->sole()->unit_positions);
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]])
            ->assertSessionHasErrors('items');
        $this->post('/buyer-returns/'.$first->id.'/actions', $this->command($first, 'withdraw'))->assertSessionHasNoErrors();
        $third = $this->createReturn($order);
        $this->assertSame([0], $third->items()->sole()->unit_positions);
        $this->assertSame(3, BuyerReturnCase::count());
        $this->assertSame(2, DB::table('buyer_return_unit_claims')->count());
    }

    public function test_rejection_keeps_the_exact_reason_and_buyer_can_escalate_for_admin_review(): void
    {
        $order = $this->delivered(true);
        $case = $this->createReturn($order);
        $this->assertContains('A buyer return is unresolved. Review its decision, custody and repayment before releasing seller proceeds.', app(SellerSettlementService::class)->eligibility($order->fresh())['blockers']);
        $this->assertContains('buyer_return', array_column(app(AccountClosureService::class)->state($order->buyer)['blockers'], 'code'));
        $payload = $this->command($case, 'reject');
        $payload['reason'] = 'The photos show a different item from this purchase. Please provide the original item evidence.';
        $this->actingAs($order->shop->user)->post('/buyer-returns/'.$case->id.'/actions', $payload)->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/actions', $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, $case->events()->count());
        $this->assertSame($payload['reason'], $case->events()->where('event_type', 'reject')->sole()->reason);
        $this->actingAs($order->buyer)->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'escalate'))->assertSessionHasNoErrors();
        $this->actingAs($order->shop->user)->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'approve'))->assertForbidden();
        $this->actingAs($this->createApprovedUser('admin'))->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'approve'))->assertSessionHasNoErrors();
        $this->assertSame('approved', $case->fresh()->status);
        $this->assertTrue($case->fresh()->dropoff_until->equalTo(now()->startOfSecond()->addDays(7)));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(0, SellerSettlement::count());
    }

    public function test_seller_rejection_reason_boundaries_retain_pending_cases_and_accept_the_limits(): void
    {
        $case = $this->createReturn($this->delivered());
        $seller = $case->order->shop->user;
        $before = $case->fresh()->getRawOriginal();
        foreach (['', 'Item used', str_repeat('x', 301)] as $reason) {
            $this->actingAs($seller)->post('/buyer-returns/'.$case->id.'/actions',
                array_replace($this->command($case, 'reject'), ['reason' => $reason]))->assertSessionHasErrors('reason');
            $this->assertSame($before, $case->fresh()->getRawOriginal());
            $this->assertSame(1, $case->events()->count());
        }
        foreach ([10, 300] as $length) {
            if ($case->fresh()->status !== 'requested') {
                $case = $this->createReturn($this->delivered());
            }
            $payload = array_replace($this->command($case, 'reject'), ['reason' => str_repeat('x', $length)]);
            $this->actingAs($case->order->shop->user)->post('/buyer-returns/'.$case->id.'/actions', $payload)->assertSessionHasNoErrors();
            $this->post('/buyer-returns/'.$case->id.'/actions', $payload)->assertSessionHasNoErrors();
            $this->assertSame('rejected', $case->fresh()->status);
            $this->assertSame($payload['reason'], $case->events()->where('event_type', 'reject')->sole()->reason);
            $this->assertSame(2, $case->events()->count());
        }
    }

    public function test_foreign_units_and_changed_or_stale_retries_do_not_change_the_saved_request(): void
    {
        $order = $this->delivered();
        $payload = $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]];
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $payload)->assertSessionHasNoErrors();
        $case = BuyerReturnCase::sole();
        $changed = $payload;
        $changed['description'] = 'The new request uses changed evidence and details.';
        $this->post('/buyer-returns/orders/'.$order->id, $changed)->assertConflict();
        $this->actingAs($this->createApprovedUser('buyer'))->post('/buyer-returns/orders/'.$order->id, $payload)->assertForbidden();
        $this->actingAs($order->shop->user);
        $command = $this->command($case, 'approve');
        $this->post('/buyer-returns/'.$case->id.'/actions', $command)->assertSessionHasNoErrors();
        $stale = $command;
        $stale['request_token'] = (string) Str::uuid();
        $this->post('/buyer-returns/'.$case->id.'/actions', $stale)->assertConflict();
        $command['reason'] = 'A changed decision must not replace the retained approval.';
        $this->post('/buyer-returns/'.$case->id.'/actions', $command)->assertConflict();
        $this->assertSame(2, BuyerReturnEvent::count());
    }

    public static function invalidEvidence(): array
    {
        return ['short' => ['description', 'abcdefghi'], 'blank' => ['description', str_repeat(' ', 20)],
            'long' => ['description', str_repeat('a', 1001)], 'hidden' => ['description', "A broken\u{200b} item description"],
            'no photos' => ['photos', 0], 'six photos' => ['photos', 6], 'over bytes' => ['bytes', 5242881],
            'video renamed' => ['video', null], 'foreign units' => ['foreign', null], 'zero units' => ['quantity', 0],
            'amount injection' => ['amount', 1]];
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_requests_leave_no_case_or_orphan_evidence(string $field, mixed $value): void
    {
        $order = $this->delivered();
        $payload = $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]];
        $error = $field;
        if ($field === 'description') {
            $payload[$field] = $value;
        } elseif ($field === 'photos') {
            $payload['photos'] = array_map(fn () => UploadedFile::fake()->image('photo.png'), range(1, max(1, $value)));
            if ($value === 0) {
                $payload['photos'] = [];
            }
        } elseif ($field === 'bytes') {
            $payload['photos'] = [$this->photoBytes($value)];
            $error = 'photos.0';
        } elseif ($field === 'video') {
            $payload['photos'] = [UploadedFile::fake()->createWithContent('photo.png', 'not an image or a real photo')];
            $error = 'photos.0';
        } elseif ($field === 'foreign') {
            $payload['items'][0]['order_item_id'] = $order->items->first()->id + 999;
            $error = 'items';
        } elseif ($field === 'quantity') {
            $payload['items'][0]['quantity'] = $value;
            $error = 'items.0.quantity';
        } else {
            $payload[$field] = $value;
        }
        $before = Storage::disk('local')->allFiles('buyer-return-evidence');
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $payload)->assertSessionHasErrors($error);
        $this->assertSame(0, BuyerReturnCase::count());
        $this->assertSame($before, Storage::disk('local')->allFiles('buyer-return-evidence'));
    }

    private function photoBytes(int $bytes): UploadedFile
    {
        $source = UploadedFile::fake()->image('photo.png');
        $data = file_get_contents($source->getRealPath());
        $file = UploadedFile::fake()->createWithContent('photo.png', $data.str_repeat(' ', $bytes - strlen($data)));
        $this->actualPhotoSources[] = $file;

        return new UploadedFile($file->getRealPath(), 'photo.png', 'image/png', null, true);
    }

    public static function acceptedLimits(): array
    {
        return ['minimum' => [10, 1, 5242879], 'maximum' => [1000, 5, 5242880]];
    }

    #[DataProvider('acceptedLimits')]
    public function test_exact_valid_description_photo_and_real_byte_boundaries_persist(int $length, int $count, int $bytes): void
    {
        $order = $this->delivered();
        $payload = $this->payload($order);
        $payload['description'] = str_repeat('a', $length);
        $payload['photos'] = [$this->photoBytes($bytes)];
        for ($n = 1; $n < $count; $n++) {
            $payload['photos'][] = UploadedFile::fake()->image('photo.png');
        }
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame($length, mb_strlen(BuyerReturnCase::sole()->description));
        $this->assertSame($count, BuyerReturnEvidence::count());
        $this->assertSame($bytes, BuyerReturnEvidence::first()->bytes);
        $this->assertSame($bytes, Storage::disk('local')->size(BuyerReturnEvidence::first()->path));
    }

    public function test_a_failed_journal_write_rolls_back_case_units_and_new_files_then_allows_retry(): void
    {
        $order = $this->delivered();
        $payload = $this->payload($order) + ['photos' => [UploadedFile::fake()->image('damage.png')]];
        DB::unprepared("CREATE TRIGGER fail_return_event BEFORE INSERT ON buyer_return_events BEGIN SELECT RAISE(ABORT, 'test'); END");
        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, $payload)->assertSessionHasErrors('return');
        $this->assertSame(0, BuyerReturnCase::count());
        $this->assertSame(0, DB::table('buyer_return_unit_claims')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('buyer-return-evidence'));
        DB::unprepared('DROP TRIGGER fail_return_event');
        $this->post('/buyer-returns/orders/'.$order->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, BuyerReturnCase::count());
    }

    public static function responseClocks(): array
    {
        return ['seller exact deadline' => ['seller', 0, true], 'seller one second late' => ['seller', 1, false],
            'appeal one second before' => ['appeal', -1, true], 'appeal exact deadline' => ['appeal', 0, true],
            'appeal one second late' => ['appeal', 1, false]];
    }

    #[DataProvider('responseClocks')]
    public function test_response_and_escalation_clocks_use_their_own_exact_deadlines(string $clock, int $offset, bool $allowed): void
    {
        $order = $this->delivered();
        $case = $this->createReturn($order);
        $this->actingAs($order->shop->user);
        if ($clock === 'seller') {
            $this->travelTo($case->response_until->copy()->addSeconds($offset));
            $response = $this->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'approve'));
        } else {
            $this->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'reject'))->assertSessionHasNoErrors();
            $this->travelTo($case->fresh()->appeal_until->copy()->addSeconds($offset));
            $response = $this->actingAs($order->buyer)->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'escalate'));
        }
        if ($allowed) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertForbidden();
            $action = $clock === 'seller' ? 'escalate' : 'close-period';
            $this->actingAs($order->buyer)->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, $action))->assertSessionHasNoErrors();
            $this->assertSame($clock === 'seller' ? 'escalated' : 'denied', $case->fresh()->status);
        }
    }

    public function test_new_restrictions_preserve_history_but_block_seller_review_and_closed_accounts(): void
    {
        $order = $this->delivered();
        $case = $this->createReturn($order);
        $seller = $order->shop->user;
        $seller->update(['status' => 'suspended']);
        $this->actingAs($seller)->get('/buyer-returns/'.$case->id)->assertOk();
        $this->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'approve'))->assertForbidden();
        $order->buyer->update(['status' => 'suspended']);
        $this->actingAs($order->buyer)->get('/buyer-returns/'.$case->id)->assertOk();
        $this->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'withdraw'))->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $case->fresh()->status);
        $order->buyer->forceFill(['status' => 'inactive', 'closed_at' => now()])->save();
        $this->getJson('/buyer-returns/'.$case->id)->assertForbidden();
    }

    public function test_a_failed_notice_intent_rolls_back_the_decision_and_can_be_retried(): void
    {
        $order = $this->delivered();
        $case = $this->createReturn($order);
        $command = $this->command($case, 'approve');
        DB::unprepared("CREATE TRIGGER fail_return_notice BEFORE INSERT ON notification_deliveries BEGIN SELECT RAISE(ABORT, 'test'); END");
        $this->actingAs($order->shop->user)->post('/buyer-returns/'.$case->id.'/actions', $command)->assertSessionHasErrors('return');
        $this->assertSame('requested', $case->fresh()->status);
        $this->assertSame(1, $case->fresh()->version);
        $this->assertSame(1, $case->events()->count());
        DB::unprepared('DROP TRIGGER fail_return_notice');
        $this->post('/buyer-returns/'.$case->id.'/actions', $command)->assertSessionHasNoErrors();
        $this->assertSame('approved', $case->fresh()->status);
        $this->assertSame(2, $case->events()->count());
    }

    public function test_return_issue_counts_and_filters_use_owned_cases_without_rewriting_completion(): void
    {
        $order = $this->delivered(true);
        $case = $this->createReturn($order);
        $this->actingAs($order->buyer)->get('/buyer/orders?order_status=return_refund')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orderCounts.return_refund', 1)->where('orderCounts.completed', 1)->has('orders.data', 1)
            ->where('orders.data.0.status', 'completed')->where('orders.data.0.buyer_returns.0.id', $case->id));
        $this->actingAs($this->createApprovedUser('buyer'))->get('/buyer/orders?order_status=return_refund')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orderCounts.return_refund', 0)->has('orders.data', 0));
    }

    public function test_a_paid_seller_settlement_does_not_remove_the_buyers_remaining_return_window(): void
    {
        $order = $this->delivered(true);
        $this->settleFlowOrder($order);
        $record = $order->fresh()->sellerSettlement;
        $payment = app(SellerSettlementService::class)->verifiedPayment($record);
        $before = [$record->getAttributes(), $record->events()->get()->map->getAttributes()->all(), $order->fresh()->commissionLedger->getAttributes()];
        $this->travelTo($order->delivery->delivered_at->copy()->addDays(6));
        $case = $this->createReturn($order);
        $this->assertSame('requested', $case->status);
        $this->assertSame($payment->id, app(SellerSettlementService::class)->verifiedPayment($record->fresh())->id);
        $this->assertSame($before, [$record->fresh()->getAttributes(), $record->events()->get()->map->getAttributes()->all(), $order->fresh()->commissionLedger->getAttributes()]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_pending_buyer_can_read_legacy_history_without_receiving_return_authority(): void
    {
        $buyer = $this->createPendingUser('buyer');
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'completed']);
        $this->actingAs($buyer)->get('/buyer/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.return_availability', null));
    }

    public function test_return_notices_target_the_original_seller_and_current_admin_reviewers_without_private_paths(): void
    {
        config(['session.lifetime' => 60 * 24 * 10]);
        $order = $this->delivered();
        $admin = $this->createApprovedUser('admin');
        $inactive = $this->createApprovedUser('admin', ['status' => 'inactive']);
        $case = $this->createReturn($order);
        $event = $case->events()->sole();
        $intent = NotificationDelivery::where('type', 'buyer-return')->where('source_key', 'buyer-return:'.$event->id)->sole();
        $this->assertSame($case->seller_id, $intent->recipient_id);
        $this->assertTrue(app(NotificationDeliveryService::class)->deliver($intent->id));
        $seller = $order->shop->user;
        $notice = $seller->notifications()->where('type', 'buyer-return')->sole();
        $view = app(NotificationCenterService::class)->present($notice, $seller);
        $this->assertSame('/buyer-returns/'.$case->id, $view['href']);
        $this->assertStringNotContainsString('buyer-return-evidence/', json_encode($view));
        $this->travelTo($case->response_until->copy()->addSecond());
        $this->actingAs($order->buyer)->post('/buyer-returns/'.$case->id.'/actions', $this->command($case, 'escalate'))->assertSessionHasNoErrors();
        $event = $case->events()->where('event_type', 'escalate')->sole();
        $intents = NotificationDelivery::where('type', 'buyer-return')->where('source_key', 'buyer-return:'.$event->id)->get();
        $this->assertTrue($intents->contains('recipient_id', $seller->id));
        $this->assertTrue($intents->contains('recipient_id', $admin->id));
        foreach ($intents->where('recipient_id', '!=', $seller->id) as $review) {
            $reviewer = User::findOrFail($review->recipient_id);
            $this->assertTrue($reviewer->isAdmin() && $reviewer->canAccessPortal() && $reviewer->email_verified_at !== null);
        }
        $this->assertFalse($intents->contains('recipient_id', $inactive->id));
        $intent = $intents->firstWhere('recipient_id', $admin->id);
        $this->assertTrue(app(NotificationDeliveryService::class)->deliver($intent->id));
        $notice = $admin->notifications()->where('type', 'buyer-return')->sole();
        $this->assertSame('/buyer-returns/'.$case->id, app(NotificationCenterService::class)->present($notice, $admin)['href']);
    }

    public function test_malformed_or_oversized_route_identifiers_never_reach_a_database_binding(): void
    {
        $this->actingAs($this->createApprovedUser('buyer'));
        foreach (['0', '01', '-1', '1e1', str_repeat('9', 50)] as $id) {
            $this->get('/buyer-returns/'.$id)->assertNotFound();
            $this->get('/buyer-returns/orders/'.$id.'/create')->assertNotFound();
            $this->get('/buyer-returns/1/evidence/'.$id)->assertNotFound();
        }
        $this->assertSame(0, BuyerReturnCase::count());
    }
}
