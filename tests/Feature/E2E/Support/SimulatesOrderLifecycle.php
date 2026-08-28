<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Order;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

trait SimulatesOrderLifecycle
{
    public function advanceOrderStage(Order $order): TestResponse
    {
        return $this->post(route('simulator.orders.advance', $order->id), [], [
            'Accept' => 'application/json',
        ]);
    }

    public function resetOrderStage(Order $order): TestResponse
    {
        return $this->post(route('simulator.orders.reset', $order->id), [], [
            'Accept' => 'application/json',
        ]);
    }

    public function assertOrderStage(Order $order, string $expectedOrderStatus, string $expectedDeliveryStatus): void
    {
        $order->refresh();
        $delivery = $order->delivery?->fresh();

        Assert::assertEquals(
            $expectedOrderStatus,
            $order->status,
            "Expected order #{$order->order_number} to be in status '{$expectedOrderStatus}', but got '{$order->status}'."
        );

        if ($expectedDeliveryStatus === 'none') {
            Assert::assertNull($delivery, "Expected order #{$order->order_number} to have no delivery record, but one exists.");
        } else {
            Assert::assertNotNull($delivery, "Expected order #{$order->order_number} to have a delivery record, but none found.");
            Assert::assertEquals(
                $expectedDeliveryStatus,
                $delivery->status,
                "Expected delivery for order #{$order->order_number} to be in status '{$expectedDeliveryStatus}', but got '{$delivery->status}'."
            );
        }
    }

    public function fastForwardToDelivered(Order $order): Order
    {
        $maxAttempts = 10;
        $attempts = 0;

        while ($order->fresh()->status !== 'delivered' && $attempts < $maxAttempts) {
            $this->advanceOrderStage($order);
            $attempts++;
        }

        Assert::assertEquals(
            'delivered',
            $order->fresh()->status,
            "Failed to fast-forward order #{$order->order_number} to 'delivered' status within {$maxAttempts} iterations."
        );

        return $order->fresh(['delivery.checkpoints', 'items.product.shop', 'commissionLedger']);
    }
}
