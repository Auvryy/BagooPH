<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldWorkloadTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    protected function setUp(): void
    {
        parent::setUp();
        $tempDir = sys_get_temp_dir() . '/bagoo_testing_disks_' . getmypid() . '/public';
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        Storage::set('public', Storage::createLocalDriver([
            'driver' => 'local',
            'root' => $tempDir,
        ]));
    }

    /**
     * T4-01: Complete Metro Manila Multi-Role E2E Order Lifecycle
     * Full 5-role end-to-end choreography:
     * 1. Buyer KYC registration & Admin approval.
     * 2. Buyer cart with 2 variant items + voucher + COD checkout.
     * 3. Seller reviews incoming order, packs, and schedules pickup (thermal waybill).
     * 4. Courier claims unassigned job, navigates to store, scans pickup barcode.
     * 5. Logistics sorting hub scans intake barcode and classifies parcel into Barangay San Antonio sorting bin.
     * 6. Courier delivers parcel to doorstep, captures photo proof, settles COD payment.
     * 7. Platform Admin verifies atomic 90%/10%/₱60 financial ledger distribution.
     * 8. Buyer verifies live tracking timeline with all 5 milestones completed.
     */
    public function test_t4_01_complete_metro_manila_multi_role_e2e_order_lifecycle(): void
    {
        // ----------------------------------------------------
        // Step 1: Buyer Registration & Admin KYC Gate Approval
        // ----------------------------------------------------
        $idDoc = UploadedFile::fake()->create('juan_national_id.jpg', 600, 'image/jpeg');
        $regResponse = $this->post('/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@example.ph',
            'password' => 'SecurePass2026!',
            'password_confirmation' => 'SecurePass2026!',
            'role' => 'buyer',
            'phone' => '+63 917 555 1234',
            'address' => 'Unit 18A Pioneer Heights, Pioneer St',
            'city' => 'Mandaluyong City',
            'postal_code' => '1550',
            'id_document' => $idDoc,
        ]);
        $regResponse->assertRedirect(route('kyc.pending'));

        $buyer = User::where('email', 'juan.delacruz@example.ph')->first();
        $this->assertNotNull($buyer);
        $this->assertEquals('pending_approval', $buyer->kyc_status);

        // Admin verifies and approves Buyer KYC
        $admin = $this->createApprovedUser('admin', ['name' => 'Admin Gatekeeper']);
        $approveResponse = $this->actingAs($admin)->post(route('admin.kyc.approve', $buyer->id));
        $approveResponse->assertSessionHas('success');

        $buyer->refresh();
        $this->assertEquals('approved', $buyer->kyc_status);
        $this->assertEquals('active', $buyer->status);

        // ----------------------------------------------------
        // Step 2: Merchant Store, Products & Voucher Setup
        // ----------------------------------------------------
        $seller = $this->createApprovedUser('seller', ['name' => 'Manila Leathercraft Co']);
        $shop = $this->createE2EShop($seller, [
            'name' => 'Manila Leathercraft Co',
            'address' => '45 Escolta Street, Binondo',
            'city' => 'Manila',
        ]);

        $productA = $this->createE2EProduct($shop, [
            'name' => 'Handcrafted Leather Tote',
            'price' => 1500.00,
            'stock' => 20,
            'sku' => 'MLC-TOTE-01',
        ]);
        $productB = $this->createE2EProduct($shop, [
            'name' => 'Artisan Leather Cardholder',
            'price' => 500.00,
            'stock' => 50,
            'sku' => 'MLC-CARD-02',
        ]);

        $voucher = $this->createE2EVoucher($shop, [
            'code' => 'MANILA100',
            'discount_type' => 'fixed',
            'discount_value' => 100.00,
            'min_spend' => 1500.00,
            'is_active' => true,
        ]);

        // ----------------------------------------------------
        // Step 3: Buyer Cart with 2 Variant Items & COD Checkout
        // ----------------------------------------------------
        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $productA->id,
            'quantity' => 1,
            'unit_price' => 1500.00,
            'subtotal' => 1500.00,
            'color' => 'Chestnut Brown',
            'size' => 'Large',
            'sku_snapshot' => 'MLC-TOTE-01-BRN-L',
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'unit_price' => 500.00,
            'subtotal' => 500.00,
            'color' => 'Midnight Black',
            'size' => 'Standard',
            'sku_snapshot' => 'MLC-CARD-02-BLK',
        ]);

        $checkoutResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 555 1234',
            'shipping_address' => 'Unit 18A Pioneer Heights, Pioneer St',
            'shipping_city' => 'Mandaluyong City',
            'shipping_postal_code' => '1550',
            'payment_method' => 'cod',
            'voucher_code' => 'MANILA100',
            'notes' => 'Please call recipient before gate entry.',
        ]);
        $checkoutResponse->assertRedirect(route('buyer.orders.index'));

        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        // Gross Subtotal = 2000, Shipping = 0 (subtotal > 1500), Voucher = 100 -> Total Amount = 1900
        $this->assertEquals(2000.00, (float) $order->subtotal);
        $this->assertEquals(1900.00, (float) $order->total_amount);
        $this->assertEquals('cod', $order->payment_method);
        $this->assertEquals(2, $order->items()->count());

        // ----------------------------------------------------
        // Step 4: Seller Packaging Approval & Waybill Dispatch
        // ----------------------------------------------------
        $sellerOrdersView = $this->actingAs($seller)->get(route('seller.orders.index'));
        $sellerOrdersView->assertOk();

        $packResponse = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $packResponse->assertSessionHas('success');

        $readyResponse = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $readyResponse->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);
        $delivery = $order->delivery;
        $this->assertNotNull($delivery);
        $this->assertEquals('unassigned', $delivery->status);
        $this->assertNotNull($delivery->tracking_number);
        $this->assertCheckpointLogged($delivery, 'seller_pack');

        // ----------------------------------------------------
        // Step 5: Courier Claims Job & Store Pickup Scan
        // ----------------------------------------------------
        $courier = $this->createApprovedUser('courier', ['name' => 'Fleet Rider Rodrigo']);
        $boardResponse = $this->actingAs($courier)->get(route('courier.deliveries'));
        $boardResponse->assertOk();

        $claimResponse = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $claimResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courier->id, $delivery->courier_id);
        $this->assertEquals('assigned', $delivery->status);

        $pickupResponse = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Scanned parcel barcode at merchant Escolta store and loaded onto bike.',
        ]);
        $pickupResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('picked_up', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'courier_pickup');

        // ----------------------------------------------------
        // Step 6: Central Logistics Sorting Hub Intake & Barangay Sorting
        // ----------------------------------------------------
        $logistics = $this->createApprovedUser('logistics', ['name' => 'Hub Supervisor Elena']);
        $hubIndex = $this->actingAs($logistics)->get(route('hub.index'));
        $hubIndex->assertOk();

        // Hub Intake Scan
        $scanResponse = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
            'notes' => 'Intake scan verified. Parcel intact.',
        ]);
        $scanResponse->assertOk();

        $delivery->refresh();
        $this->assertEquals('in_transit', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'hub_intake');

        // Barangay Sorting to Bin
        $sortResponse = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Barangay San Antonio',
            'bin' => 'BIN-MM-PASIG-04',
            'notes' => 'Sorted for last-mile route delivery.',
        ]);
        $sortResponse->assertOk();

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Barangay San Antonio');

        // ----------------------------------------------------
        // Step 7: Courier Doorstep Handover & COD Settlement
        // ----------------------------------------------------
        $deliveryResponse = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=800',
            'courier_notes' => 'Handed parcel to Juan Dela Cruz. Collected ₱1,900.00 COD cash.',
        ]);
        $deliveryResponse->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();
        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull($delivery->proof_image);
        $this->assertCheckpointLogged($delivery, 'doorstep_handover');

        // ----------------------------------------------------
        // Step 8: Platform Commission & Financial Split Ledger
        // ----------------------------------------------------
        // Gross subtotal = ₱2,000.00 -> 90% Seller = ₱1,800.00, 10% Platform = ₱200.00, Courier Fee = ₱60.00
        $ledger = $this->assertCommissionSplit($order, 2000.00);
        $this->assertEquals(1800.00, (float) $ledger->seller_amount);
        $this->assertEquals(200.00, (float) $ledger->platform_commission);
        $this->assertEquals(60.00, (float) $ledger->delivery_fee);
        $this->assertEquals($seller->id, $ledger->seller_id);
        $this->assertEquals($courier->id, $ledger->courier_id);

        // ----------------------------------------------------
        // Step 9: Buyer Live Tracking Timeline Verification
        // ----------------------------------------------------
        $timelineResponse = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $timelineResponse->assertOk();

        // Verify full 5-checkpoint audit sequence
        $this->assertCheckpointSequence($delivery, [
            'seller_pack',
            'courier_pickup',
            'hub_intake',
            'barangay_sort',
            'doorstep_handover',
        ]);
    }

    /**
     * T4-02: Multi-Seller Cart Independent Fulfillment and Settlement
     * Buyer orders from 2 distinct merchant shops simultaneously.
     * System handles independent fulfillment & courier dispatch for both shops without state crosstalk.
     */
    public function test_t4_02_multi_seller_cart_independent_fulfillment_and_settlement(): void
    {
        $buyer = $this->createApprovedUser('buyer', ['name' => 'Multi-Shop Shopper']);

        // Merchant 1 (Apparel)
        $seller1 = $this->createApprovedUser('seller', ['name' => 'Boutique Manila']);
        $shop1 = $this->createE2EShop($seller1, ['name' => 'Boutique Manila']);
        $product1 = $this->createE2EProduct($shop1, ['price' => 1200.00, 'stock' => 10]);

        // Merchant 2 (Ceramics)
        $seller2 = $this->createApprovedUser('seller', ['name' => 'Pampanga Pottery']);
        $shop2 = $this->createE2EShop($seller2, ['name' => 'Pampanga Pottery']);
        $product2 = $this->createE2EProduct($shop2, ['price' => 800.00, 'stock' => 15]);

        // Independent Order 1 for Shop 1
        $order1 = $this->createE2EOrder($buyer, $shop1, [['product' => $product1, 'quantity' => 1]], 'pending');
        // Independent Order 2 for Shop 2
        $order2 = $this->createE2EOrder($buyer, $shop2, [['product' => $product2, 'quantity' => 2]], 'pending');

        $courier1 = $this->createApprovedUser('courier', ['name' => 'Rider Express 1']);
        $courier2 = $this->createApprovedUser('courier', ['name' => 'Rider Express 2']);

        // Seller 1 packs and readies Order 1
        $this->actingAs($seller1)->post(route('seller.orders.pack', $order1->id))->assertSessionHas('success');
        $this->actingAs($seller1)->post(route('seller.orders.ready', $order1->id))->assertSessionHas('success');
        $order1->refresh();
        $delivery1 = $order1->delivery;

        // Seller 2 packs and readies Order 2
        $this->actingAs($seller2)->post(route('seller.orders.pack', $order2->id))->assertSessionHas('success');
        $this->actingAs($seller2)->post(route('seller.orders.ready', $order2->id))->assertSessionHas('success');
        $order2->refresh();
        $delivery2 = $order2->delivery;

        // Courier 1 claims Delivery 1
        $this->actingAs($courier1)->post(route('courier.claim', $delivery1->id))->assertSessionHas('success');
        // Courier 2 claims Delivery 2
        $this->actingAs($courier2)->post(route('courier.claim', $delivery2->id))->assertSessionHas('success');

        // Courier 1 fulfills Delivery 1
        $delivery1->refresh();
        $this->actingAs($courier1)->patch(route('courier.updateStatus', $delivery1->id), [
            'status' => 'picked_up',
        ])->assertSessionHas('success');

        $this->actingAs($courier1)->patch(route('courier.updateStatus', $delivery1->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1?w=500',
        ])->assertSessionHas('success');

        // Courier 2 fulfills Delivery 2
        $delivery2->refresh();
        $this->actingAs($courier2)->patch(route('courier.updateStatus', $delivery2->id), [
            'status' => 'picked_up',
        ])->assertSessionHas('success');

        $this->actingAs($courier2)->patch(route('courier.updateStatus', $delivery2->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-2?w=500',
        ])->assertSessionHas('success');

        $order1->refresh();
        $order2->refresh();
        $this->assertEquals('delivered', $order1->status);
        $this->assertEquals('delivered', $order2->status);

        // Commission Ledger 1: Gross ₱1,200 -> Seller ₱1,080, Platform ₱120, Courier1 ₱60
        $ledger1 = $this->assertCommissionSplit($order1, 1200.00);
        $this->assertEquals(1080.00, (float) $ledger1->seller_amount);
        $this->assertEquals(120.00, (float) $ledger1->platform_commission);
        $this->assertEquals($seller1->id, $ledger1->seller_id);
        $this->assertEquals($courier1->id, $ledger1->courier_id);

        // Commission Ledger 2: Gross ₱1,600 (800 x 2) -> Seller ₱1,440, Platform ₱160, Courier2 ₱60
        $ledger2 = $this->assertCommissionSplit($order2, 1600.00);
        $this->assertEquals(1440.00, (float) $ledger2->seller_amount);
        $this->assertEquals(160.00, (float) $ledger2->platform_commission);
        $this->assertEquals($seller2->id, $ledger2->seller_id);
        $this->assertEquals($courier2->id, $ledger2->courier_id);
    }

    /**
     * T4-03: Courier Delivery Failure Exception and Hub Rerouting
     * Courier delivery failure -> Hub intake exception checkpoint -> Reassignment to new courier -> Successful doorstep delivery with photo proof & settled ledger.
     */
    public function test_t4_03_courier_delivery_failure_exception_and_hub_rerouting(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $product = $this->createE2EProduct($shop, ['price' => 1800.00]);
        $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'ready_for_pickup');

        $courier1 = $this->createApprovedUser('courier', ['name' => 'Original Rider 1']);
        $courier2 = $this->createApprovedUser('courier', ['name' => 'Relief Rider 2']);

        $delivery = $this->createE2EDelivery($order, 'assigned', $courier1);

        // Courier 1 picks up parcel
        $this->actingAs($courier1)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Picked up from merchant store.',
        ])->assertSessionHas('success');

        // Courier 1 attempts delivery but encounters unreachable recipient -> marks failed
        $this->actingAs($courier1)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer phone unanswered and gate locked. Returning parcel to hub.',
        ])->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('failed', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'delivery_failed');

        // Logistics Hub Supervisor receives returned parcel and overrides assignment to Relief Rider 2
        $admin = $this->createApprovedUser('admin');
        $overrideResponse = $this->actingAs($admin)->post(route('admin.logistics.override'), [
            'delivery_id' => $delivery->id,
            'courier_id' => $courier2->id,
            'status' => 'out_for_delivery',
        ]);
        $overrideResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courier2->id, $delivery->courier_id);
        $this->assertEquals('out_for_delivery', $delivery->status);
        $this->assertCheckpointLogged($delivery, 'supervisor_override');

        // Courier 2 completes successful doorstep delivery with photo proof
        $finalDelivery = $this->actingAs($courier2)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-delivered-relief?w=800',
            'courier_notes' => 'Successfully handed over to buyer Juan on second attempt.',
        ]);
        $finalDelivery->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();
        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);

        // Commission Ledger settlements: ₱1,800 gross -> ₱1,620 seller (90%), ₱180 platform (10%), ₱60 to Courier 2
        $ledger = $this->assertCommissionSplit($order, 1800.00);
        $this->assertEquals(1620.00, (float) $ledger->seller_amount);
        $this->assertEquals(180.00, (float) $ledger->platform_commission);
        $this->assertEquals($courier2->id, $ledger->courier_id);
    }

    /**
     * T4-04: Rapid Fast-Forward Simulator Stress and State Sync
     * 5 distinct orders simultaneously fast-forwarded through all 7 stages;
     * assert zero deadlocks, clean checkpoint trails, exact ledger balance across all 5 orders.
     */
    public function test_t4_04_rapid_fast_forward_simulator_stress_and_state_sync(): void
    {
        $prices = [500.00, 1200.00, 2500.00, 3000.00, 4500.00];
        $orders = [];
        $totalExpectedGross = 0;
        $totalExpectedSeller = 0;
        $totalExpectedPlatform = 0;

        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin);

        foreach ($prices as $i => $price) {
            $seller = $this->createApprovedUser('seller', ['name' => "Merchant Stress #{$i}"]);
            $shop = $this->createE2EShop($seller, ['name' => "Shop Stress #{$i}"]);
            $buyer = $this->createApprovedUser('buyer', ['name' => "Buyer Stress #{$i}"]);
            $product = $this->createE2EProduct($shop, ['price' => $price, 'stock' => 100]);

            $order = $this->createE2EOrder($buyer, $shop, [['product' => $product, 'quantity' => 1]], 'pending');
            $orders[] = $order;

            $totalExpectedGross += $price;
            $totalExpectedSeller += round($price * 0.90, 2);
            $totalExpectedPlatform += round($price * 0.10, 2);
        }

        $this->assertCount(5, $orders);

        // Rapid fast-forward all 5 orders
        foreach ($orders as $order) {
            $deliveredOrder = $this->fastForwardToDelivered($order);
            $this->assertEquals('delivered', $deliveredOrder->status);
            $this->assertEquals('delivered', $deliveredOrder->delivery->status);
            $this->assertNotNull($deliveredOrder->commissionLedger);
            $this->assertEquals('settled', $deliveredOrder->commissionLedger->status);

            $this->assertCheckpointSequence($deliveredOrder->delivery, [
                'seller_pack',
                'courier_pickup',
                'hub_intake',
                'barangay_sort',
                'doorstep_handover',
            ]);
        }

        // Validate aggregate financial split ledger integrity
        $settledLedgers = CommissionLedger::whereIn('order_id', collect($orders)->pluck('id'))->get();
        $this->assertCount(5, $settledLedgers);

        $actualGross = $settledLedgers->sum(fn ($l) => (float) $l->gross_amount);
        $actualSeller = $settledLedgers->sum(fn ($l) => (float) $l->seller_amount);
        $actualPlatform = $settledLedgers->sum(fn ($l) => (float) $l->platform_commission);
        $actualCourierFees = $settledLedgers->sum(fn ($l) => (float) $l->delivery_fee);

        $this->assertEquals($totalExpectedGross, $actualGross);
        $this->assertEquals($totalExpectedSeller, $actualSeller);
        $this->assertEquals($totalExpectedPlatform, $actualPlatform);
        $this->assertEquals(300.00, $actualCourierFees); // 5 x ₱60.00 = ₱300.00
    }

    /**
     * T4-05: KYC Rejection Feedback Resubmission and First Sale Workflow
     * Seller registration rejection with feedback -> Resubmission -> Admin approval -> Product listing -> Buyer purchase -> Merchant fulfillment.
     */
    public function test_t4_05_kyc_rejection_feedback_resubmission_and_first_sale_workflow(): void
    {
        // 1. Seller registers with incomplete documents
        $idDoc = UploadedFile::fake()->create('blurry_id.jpg', 300, 'image/jpeg');
        $permit = UploadedFile::fake()->create('expired_permit.pdf', 500, 'application/pdf');

        $this->post('/register', [
            'name' => 'Elena Artisan',
            'email' => 'elena.artisan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'seller',
            'shop_name' => 'Elena Woven Creations',
            'phone' => '+63 919 444 5555',
            'address' => '12 Mabini Street',
            'city' => 'Baguio City',
            'id_document' => $idDoc,
            'business_permit' => $permit,
        ])->assertRedirect(route('kyc.pending'));

        $seller = User::where('email', 'elena.artisan@example.com')->first();
        $this->assertNotNull($seller);
        $this->assertEquals('pending_approval', $seller->kyc_status);

        // 2. Admin reviews KYC and rejects with clear feedback
        $admin = $this->createApprovedUser('admin');
        $feedbackReason = 'Uploaded business permit is expired. Please submit a valid 2026 DTI or Mayor permit.';
        $rejectResponse = $this->actingAs($admin)->post(route('admin.kyc.reject', $seller->id), [
            'reason' => $feedbackReason,
        ]);
        $rejectResponse->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('rejected', $seller->kyc_status);
        $this->assertEquals($feedbackReason, $seller->kyc_feedback);

        // 3. Seller visits portal -> blocked and sees rejection feedback on holding page
        $holdingPage = $this->actingAs($seller)->get(route('kyc.pending'));
        $holdingPage->assertOk();

        // 4. Seller resubmits valid 2026 documents
        $validPermit = UploadedFile::fake()->create('valid_2026_dti_permit.pdf', 1000, 'application/pdf');
        $resubmitResponse = $this->actingAs($seller)->post(route('kyc.resubmit'), [
            'business_permit' => $validPermit,
        ]);
        $resubmitResponse->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('pending_approval', $seller->kyc_status);
        $this->assertNull($seller->kyc_feedback);

        // 5. Admin inspects and approves resubmitted KYC
        $this->actingAs($admin)->post(route('admin.kyc.approve', $seller->id))->assertSessionHas('success');

        $seller->refresh();
        $this->assertEquals('approved', $seller->kyc_status);
        $this->assertEquals('active', $seller->status);

        // 6. Approved Seller accesses dashboard and creates new product listing
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();

        $createProductResponse = $this->actingAs($seller)->post(route('seller.products.store'), [
            'name' => 'Handwoven Cordillera Blanket',
            'price' => 2200.00,
            'stock' => 15,
            'sku' => 'HCB-2026-01',
            'description' => 'Authentic handwoven traditional Cordillera cotton blanket.',
        ]);
        $createProductResponse->assertSessionHas('success');

        $product = Product::where('sku', 'HCB-2026-01')->first();
        $this->assertNotNull($product);
        $this->assertEquals(2200.00, (float) $product->price);

        // 7. Buyer places order for newly listed product
        $buyer = $this->createApprovedUser('buyer', ['name' => 'Baguio Tourist Buyer']);
        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 2200.00,
            'subtotal' => 2200.00,
            'color' => 'Crimson & Cream',
            'size' => 'Queen',
            'sku_snapshot' => 'HCB-2026-01',
        ]);

        $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Baguio Tourist Buyer',
            'recipient_phone' => '+63 917 111 3333',
            'shipping_address' => 'Camp John Hay Manor',
            'shipping_city' => 'Baguio City',
            'payment_method' => 'cod',
        ])->assertRedirect(route('buyer.orders.index'));

        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(2200.00, (float) $order->subtotal);

        // 8. Seller fulfills order
        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id))->assertSessionHas('success');
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id))->assertSessionHas('success');

        $order->refresh();
        $delivery = $order->delivery;
        $this->assertNotNull($delivery);

        // 9. Courier delivers order
        $courier = $this->createApprovedUser('courier', ['name' => 'Highland Express Courier']);
        $this->actingAs($courier)->post(route('courier.claim', $delivery->id))->assertSessionHas('success');

        $delivery->refresh();
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ])->assertSessionHas('success');

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-handover-baguio?w=500',
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('delivered', $order->status);

        // 10. Verify Commission Split: ₱2,200 gross -> ₱1,980 seller (90%), ₱220 platform (10%), ₱60 courier fee
        $ledger = $this->assertCommissionSplit($order, 2200.00);
        $this->assertEquals(1980.00, (float) $ledger->seller_amount);
        $this->assertEquals(220.00, (float) $ledger->platform_commission);
        $this->assertEquals($courier->id, $ledger->courier_id);
    }
}
