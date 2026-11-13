<?php

namespace Tests\Feature\Finance\Support;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Finance\ShipmentLossCustody;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

trait CreatesMissingShipments
{
    private function quarantineMissing(ShipmentFinancialPlan $plan): ShipmentLossFinding
    {
        $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
        $handler = $this->flowHandler($manifest->destinationHub);

        return app(ShipmentLossCustody::class)->intake($handler, $plan, $manifest->destinationHub,
            ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
                'barcode' => $plan->snapshot['source']['barcode'], 'reason' => 'The original parcel was physically found and placed in the named hub quarantine pending independent reconciliation.',
                'quarantine_confirmed' => true], UploadedFile::fake()->image('actual-found-parcel.png'));
    }

    private function missing(string $mode = 'wallet', bool $buyerReturn = false, bool $shippingVoucher = false, bool $sellerPaid = false): array
    {
        $admin = $this->createApprovedUser('admin');
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $shipping = ['payment_method' => $mode];
        if ($shippingVoucher) {
            $voucher = Voucher::factory()->funded()->create(['min_spend' => 0, 'discount_type' => 'free_shipping', 'discount_value' => 0, 'max_discount' => 20]);
            $shipping['platform_voucher_revision_id'] = $voucher->current_revision_id;
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '100.00']), 'quantity' => 2]], shipping: $shipping);
        // Future-policy fixture only; public checkout remains policy one until all successor consumers pass.
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        if ($mode === 'wallet') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($buyer, ['amount' => '1000.00', 'reason' => 'Demonstration credits for the actual isolated loss-source journey.', 'request_token' => (string) Str::uuid()]);
            $credits->review($admin, $request, ['decision' => 'approved', 'reason' => 'Independently reviewed the original demonstration credit source.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk();
        }
        $case = null;
        if ($buyerReturn) {
            $this->flowDelivery($order, 'delivered');
            $order = $order->fresh(['buyer', 'items']);
            if ($sellerPaid) {
                $this->assertSame('cod', $mode);
                $this->flowSettlementStorageFaked = true;
                $this->settleFlowOrder($order);
            }
            $returns = app(BuyerReturnService::class);
            $case = $returns->request($buyer, $order, ['request_token' => $returns->form($buyer, $order)['requestToken'], 'reason' => 'damaged',
                'description' => 'The original delivered unit fastening is broken and cannot be used.',
                'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('original-item.png')]);
            $returns->command($shop->user, $case, 'approve', ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
                'reason' => 'Original item evidence supports review and actual reverse transport.', 'fault' => 'seller']);
            $case = $case->fresh(['parcel', 'order']);
            $this->intake($case);
            $this->measureReturn($case);
            $manifest = $this->prepareLeg($case);
            $source = LogisticsHub::findOrFail($manifest->source_hub_id);
            $owner = LogisticsCompany::findOrFail($manifest->logistics_company_id)->user;
            $this->actingAs($this->flowHandler($source))->postJson('/hub/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $case->parcel->barcode]))->assertOk();
            $this->actingAs($owner)->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
            $this->actingAs($this->flowHandler($source))->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
            $member = $manifest->buyerReturnParcels()->sole();
        } else {
            $parcel = $this->flowDelivery($order, 'arrived_at_origin_hub');
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->current_hub_id), true);
            $manifest = LogisticsManifest::findOrFail(DeliveryCheckpoint::lastCustody($parcel->fresh())['manifest_id']);
            $member = $manifest->parcels()->where('delivery_id', $parcel->id)->sole();
        }
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/report-discrepancy', $this->flowManifestPayload($manifest,
            ['kind' => 'missing', 'parcel_id' => $member->id, 'notes' => 'The expected original parcel was not found during the actual arriving transport check.']))->assertOk();
        $observation = $manifest->events()->where('event_type', 'report-discrepancy')->sole();

        return [$observation, $admin, $order->fresh(['delivery', 'items.shop', 'buyer']), $case?->fresh(['parcel', 'order', 'items'])];
    }

    private function approval(LogisticsManifestEvent $source, User $admin, string $protection = '180.00'): array
    {
        $details = ['seller_protection' => $protection, 'investigation_reference' => 'ACTUAL-INVESTIGATION-'.$source->id];
        $review = app(ShipmentLossReview::class)->preview($admin, $source, $details);

        return [...$details, 'review_token' => $review['review_token'], 'request_token' => (string) Str::uuid(), 'loss_confirmed' => true,
            'reason' => 'Independently investigated the original missing transport, actual custody, original responsible company and exact eligible amounts.'];
    }
}
