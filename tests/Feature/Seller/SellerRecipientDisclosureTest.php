<?php

namespace Tests\Feature\Seller;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class SellerRecipientDisclosureTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['root'], ['subdomain']];
    }

    #[DataProvider('portals')]
    public function test_private_snapshot_is_absent_before_acceptance_and_original_after_acceptance(string $portal): void
    {
        $order = $this->newFlowOrder(shipping: ['shipping_address' => '192 Private Recipient Lane',
            'recipient_phone' => '09179876543', 'notes' => 'Private delivery instructions', 'destination_latitude' => 14.2812345, 'destination_longitude' => 121.4187654]);
        $this->withoutVite();
        $seller = $order->shop->user;
        $url = $portal === 'root' ? route('seller.orders.index') : 'http://seller.localhost:8000/orders';
        $original = [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()];
        $this->actingAs($seller)->get($url)->assertOk()->assertInertia(function (Assert $page) use ($order) {
            $page->where('orders.data.0.recipient_details_available', false)->where('orders.data.0.can_print_waybill', false)
                ->where('orders.data.0.shipping_address', null)->where('orders.data.0.recipient_phone', null)
                ->where('orders.data.0.destination_latitude', null)->where('orders.data.0.destination_longitude', null)
                ->where('orders.data.0.notes', null)->where('orders.data.0.delivery.tracking_number', null)
                ->where('orders.data.0.delivery.delivery_phone', null)->where('orders.data.0.delivery.delivery_address', null)
                ->where('orders.data.0.shipping_city', $order->shipping_city)
                ->where('orders', function ($orders) use ($order) {
                    $json = json_encode($orders);
                    foreach (['192 Private Recipient Lane', '9179876543', 'Private delivery instructions', '14.2812345', '121.4187654', $order->delivery->tracking_number] as $secret) {
                        $this->assertStringNotContainsString($secret, $json);
                    }

                    return true;
                });
        });
        $this->assertSame($original, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
        $this->postJson(route('seller.orders.accept', $order))->assertOk();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.recipient_details_available', true)->where('orders.data.0.can_print_waybill', true)
            ->where('orders.data.0.shipping_address', '192 Private Recipient Lane')
            ->where('orders.data.0.delivery.tracking_number', $order->delivery->tracking_number));
        $this->assertSame($original[0]['shipping_address'], $order->fresh()->shipping_address);
    }

    public function test_cancellation_before_acceptance_does_not_disclose_recipient_or_waybill(): void
    {
        $order = $this->newFlowOrder();
        $this->actingAs($order->buyer)->postJson(route('buyer.orders.cancel', $order), ['reason' => 'Ordered by mistake'])->assertOk();
        $this->actingAs($order->shop->user)->get(route('seller.orders.index'))->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.status', 'cancelled')->where('orders.data.0.can_print_waybill', false)
            ->where('orders.data.0.recipient_details_available', false)->where('orders.data.0.delivery.tracking_number', null));
    }
}
