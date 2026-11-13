<?php

namespace Tests\Feature\Finance\Support;

use App\Models\BuyerReturnMoneyEvent;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsHub;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\Finance\ShipmentRefusalLiability;
use App\Services\Finance\ShipmentRefusalMoney;
use App\Services\Finance\ShipmentRefusalReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait CreatesShipmentCashReferences
{
    private function shipmentCashReference(string $reference): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $admin = $this->createApprovedUser('admin');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '500.00']), 'quantity' => 1]], 'ready_for_pickup');
        // This acceptance fixture selects future purchased terms before any custody or money action.
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $route = DeliveryReturnRoute::where('delivery_id', $parcel->id)->sole();
        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return');
        }
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id,
            'mode' => 'confirm', 'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference])->assertOk();
        $this->actingAs($shop->user)->postJson(route('seller.orders.return-receipt', $order), ['barcode' => $parcel->tracking_number,
            'route_reference' => $route->reference, 'request_token' => (string) Str::uuid(), 'notes' => 'The owning seller received this actual refused parcel after its complete reverse route.'])->assertOk();
        $details = ['reverse_fee' => '40.00', 'cost_reference' => 'REFERENCE-REVIEW-'.$order->id];
        $review = app(ShipmentRefusalReview::class)->preview($admin, $order, $details);
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $details + [
            'review_token' => $review['review_token'], 'request_token' => (string) Str::uuid(), 'conforming_shipment_confirmed' => true,
            'reason' => 'The independent admin reviewed the actual refusal, complete seller receipt and original reverse-cost evidence.',
        ], UploadedFile::fake()->image('actual-refusal-cost.png'));
        app(ShipmentRefusalMoney::class)->execute($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'assessment_confirmed' => true, 'reason' => 'The original refusal has no collected COD payment; only its actual shipping liability is assessed.']);
        $input = ['expected_version' => 2, 'request_token' => (string) Str::uuid(), 'amount' => '1.00', 'recipient_id' => $admin->id,
            'cash_reference' => $reference, 'payment_confirmed' => true, 'reason' => 'The named buyer offered this actual transfer against its independently reviewed shipping liability.'];
        $proof = UploadedFile::fake()->image('actual-shipping-transfer.png');
        $offer = app(ShipmentRefusalLiability::class)->command($buyer, $plan, 'offer-shipping-payment', $input, $proof);
        $this->assertSame($offer->id, app(ShipmentRefusalLiability::class)->command($buyer, $plan, 'offer-shipping-payment', $input, $proof)->id);

        return [$plan, $offer, $admin];
    }

    private function assertCashReferenceConflict(callable $operation): void
    {
        $before = [BuyerReturnMoneyEvent::count(), ShipmentFinancialEvent::count()];
        try {
            $operation();
            $this->fail('A retained actual cash-transfer reference cannot fund or repay another obligation.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, [BuyerReturnMoneyEvent::count(), ShipmentFinancialEvent::count()]);
    }

    private function assertShipmentRejectsReturnCashReference(ShipmentFinancialPlan $plan, User $admin, string $reference): void
    {
        $this->assertCashReferenceConflict(fn () => app(ShipmentRefusalLiability::class)->command($plan->order->buyer, $plan, 'offer-shipping-payment', [
            'expected_version' => $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(), 'amount' => '1.00',
            'recipient_id' => $admin->id, 'cash_reference' => $reference, 'payment_confirmed' => true,
            'reason' => 'A distinct shipping obligation must retain its own actual transfer reference instead of reusing another payment.',
        ], UploadedFile::fake()->image('different-shipping-transfer.png')));
    }
}
