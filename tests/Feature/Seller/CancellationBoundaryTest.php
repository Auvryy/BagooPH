<?php

namespace Tests\Feature\Seller;

use App\Models\DeliveryCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CancellationBoundaryTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function heldParcel(): array
    {
        $order = $this->newFlowOrder('preparing');
        $parcel = $order->delivery;
        $rider = $this->createApprovedUser('courier');
        DeliveryCheckpoint::record($parcel, 'courier_pickup', actor: $rider, barcodeScanned: $parcel->tracking_number,
            evidence: ['custody_before' => DeliveryCheckpoint::lastCustody($parcel),
                'custody_after' => ['kind' => 'pickup_rider', 'user_id' => $rider->id]]);

        return [$order, $order->shop->user];
    }

    public static function sellerCommands(): array
    {
        return ['cancel' => ['cancel'], 'ready' => ['ready']];
    }

    #[DataProvider('sellerCommands')]
    public function test_retained_custody_cannot_be_bypassed_by_unclaimed_mutable_fields(string $action): void
    {
        [$order, $seller] = $this->heldParcel();
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $order->items->first()->product->fresh()->getAttributes()];
        $response = $this->actingAs($seller)->postJson(route('seller.orders.'.$action, $order), ['reason' => 'Out of stock / Inventory shortage']);
        $this->assertSame('preparing', $order->fresh()->status);
        $response->assertConflict()->assertJsonStructure(['message', 'errors' => ['fulfillment']]);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->items->first()->product->fresh()->getAttributes()]);
    }

    public function test_seller_actions_explain_the_same_custody_boundary_as_the_write(): void
    {
        [$order, $seller] = $this->heldParcel();
        $this->withoutVite();
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.can_cancel', false)->where('orders.data.0.can_mark_ready', false)
            ->where('orders.data.0.fulfillment_feedback.code', 'custody_recorded'));
    }

    public function test_seller_cancellation_is_retry_safe_and_retains_its_named_non_scan_evidence(): void
    {
        $order = $this->newFlowOrder();
        $seller = $order->shop->user;
        $input = ['reason' => 'Out of stock / Inventory shortage'];
        $this->actingAs($seller)->postJson(route('seller.orders.cancel', $order), $input)->assertOk()
            ->assertJsonPath('order.status', 'cancelled');
        $checkpoint = $order->delivery->checkpoints()->where('checkpoint_type', 'cancelled')->sole();
        $stock = $order->items->first()->product->fresh()->stock;
        $this->postJson(route('seller.orders.cancel', $order), $input)->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock);
        $this->assertSame($seller->id, $checkpoint->scanned_by_id);
        $this->assertSame('not_scanned', $checkpoint->scan_provenance);
        $this->assertSame($checkpoint->custody_before, $checkpoint->custody_after);
        $this->assertSame(1, $order->delivery->checkpoints()->where('checkpoint_type', 'cancelled')->count());
    }
}
