<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChallengerM1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Test Seller KYC Full State Machine Lifecycle:
     * Register (pending_approval) -> Reject (rejected + feedback) -> Resubmit (pending_approval + null feedback) -> Approve (active + approved + active shop)
     */
    public function test_seller_kyc_full_lifecycle_state_machine(): void
    {
        Storage::fake('public');

        // Step 1: Registration
        $idDoc = UploadedFile::fake()->create('seller_gov_id.pdf', 500, 'application/pdf');
        $permitDoc = UploadedFile::fake()->create('dti_permit.pdf', 800, 'application/pdf');

        $regResponse = $this->post('/register', [
            'role' => 'seller',
            'name' => 'Adversarial Seller',
            'email' => 'adv.seller@bagoo.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'shop_name' => 'Adversarial Artisan Crafts',
            'phone' => '09170001111',
            'address' => '456 Crafts Street',
            'city' => 'Cebu City',
            'postal_code' => '6000',
            'id_document' => $idDoc,
            'business_permit' => $permitDoc,
        ]);

        $regResponse->assertRedirect(route('kyc.pending'));

        $seller = User::where('email', 'adv.seller@bagoo.test')->first();
        $this->assertNotNull($seller);
        $this->assertEquals('pending_approval', $seller->status);
        $this->assertEquals('pending_approval', $seller->kyc_status);
        $this->assertNull($seller->kyc_feedback);
        $this->assertNotNull($seller->kyc_submitted_at);
        $this->assertNotNull($seller->id_document_path);
        $this->assertNotNull($seller->business_permit_path);

        $shop = Shop::where('user_id', $seller->id)->first();
        $this->assertNotNull($shop);
        $this->assertEquals('pending', $shop->status);
        $this->assertEquals('Adversarial Artisan Crafts', $shop->name);

        // Verify gate: Seller cannot access seller portal routes
        $gateResponse = $this->actingAs($seller)->get('/seller/orders');
        $gateResponse->assertRedirect(route('kyc.pending'));

        $dashResponse = $this->actingAs($seller)->get('/dashboard');
        $dashResponse->assertRedirect(route('kyc.pending'));

        // Step 2: Admin Rejection with Feedback
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $rejectionReason = 'The submitted DTI Business Permit appears expired or illegible. Please provide a valid 2026 renewal document.';
        $rejectResponse = $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/reject", [
            'reason' => $rejectionReason,
        ]);

        $rejectResponse->assertRedirect();
        $seller->refresh();
        $shop->refresh();

        $this->assertEquals('pending_approval', $seller->status);
        $this->assertEquals('rejected', $seller->kyc_status);
        $this->assertEquals($rejectionReason, $seller->kyc_feedback);
        $this->assertNotNull($seller->kyc_reviewed_at);
        $this->assertEquals('pending', $shop->status);

        // Verify rejected seller is still held at pending-approval and sees feedback
        $rejectedGateResponse = $this->actingAs($seller)->get('/seller/dashboard');
        $rejectedGateResponse->assertRedirect(route('kyc.pending'));

        // Step 3: User Resubmission with New Documents
        $newPermitDoc = UploadedFile::fake()->create('renewed_dti_permit_2026.pdf', 900, 'application/pdf');
        $resubmitResponse = $this->actingAs($seller)->post('/kyc/resubmit', [
            'business_permit' => $newPermitDoc,
        ]);

        $resubmitResponse->assertRedirect();
        $seller->refresh();
        $shop->refresh();

        $this->assertEquals('pending_approval', $seller->status);
        $this->assertEquals('pending_approval', $seller->kyc_status);
        $this->assertNull($seller->kyc_feedback, 'kyc_feedback must be cleared upon resubmission');
        $this->assertNotNull($seller->kyc_submitted_at);
        $this->assertStringContainsString('kyc_documents', $seller->business_permit_path);

        // Step 4: Admin Approval
        $approveResponse = $this->actingAs($admin)->post("/admin/kyc/{$seller->id}/approve");
        $approveResponse->assertRedirect();

        $seller->refresh();
        $shop->refresh();

        $this->assertEquals('active', $seller->status);
        $this->assertEquals('approved', $seller->kyc_status);
        $this->assertNull($seller->kyc_feedback);
        $this->assertNotNull($seller->kyc_reviewed_at);
        $this->assertEquals('active', $shop->status);

        // Verify approved seller can now access seller dashboard and orders
        $sellerOrderResponse = $this->actingAs($seller)->get('/seller/orders');
        $sellerOrderResponse->assertStatus(200);

        $sellerDashResponse = $this->actingAs($seller)->get('/seller/dashboard');
        $sellerDashResponse->assertStatus(200);

        $universalDashResponse = $this->actingAs($seller)->get('/dashboard');
        $universalDashResponse->assertRedirect(route('seller.dashboard'));
    }

    /**
     * 2. Test Courier KYC Lifecycle and Fleet Profile Creation & Activation:
     * Register -> Verify courier_profiles created with is_available=false, or_cr_status='Pending Verification'
     * Admin Approve -> Verify is_available=true, or_cr_status='Verified & Registered'
     */
    public function test_courier_fleet_profile_creation_and_activation(): void
    {
        Storage::fake('public');

        $idDoc = UploadedFile::fake()->create('courier_id.jpg', 600, 'image/jpeg');
        $licenseDoc = UploadedFile::fake()->create('lto_license.jpg', 700, 'image/jpeg');
        $orCrDoc = UploadedFile::fake()->create('vehicle_or_cr.pdf', 800, 'application/pdf');

        // Step 1: Courier Registration
        $regResponse = $this->post('/register', [
            'role' => 'courier',
            'name' => 'Speedy Dave',
            'email' => 'speedy.dave@bagoo.test',
            'password' => 'RiderSecret2026!',
            'password_confirmation' => 'RiderSecret2026!',
            'phone' => '09189998888',
            'address' => '789 Logistics Way',
            'city' => 'Pasig City',
            'postal_code' => '1600',
            'vehicle_type' => 'Yamaha NMAX 155',
            'plate_number' => 'ND-12345',
            'license_number' => 'N02-99-887766',
            'id_document' => $idDoc,
            'driver_license' => $licenseDoc,
            'or_cr_document' => $orCrDoc,
        ]);

        $regResponse->assertRedirect(route('kyc.pending'));

        $courier = User::where('email', 'speedy.dave@bagoo.test')->first();
        $this->assertNotNull($courier);
        $this->assertEquals('pending_approval', $courier->status);
        $this->assertEquals('pending_approval', $courier->kyc_status);
        $this->assertNotNull($courier->id_document_path);
        $this->assertNotNull($courier->driver_license_path);
        $this->assertNotNull($courier->or_cr_path);

        // Verify courier_profiles record
        $profile = CourierProfile::where('user_id', $courier->id)->first();
        $this->assertNotNull($profile, 'courier_profiles record must be created on registration');
        $this->assertEquals('Yamaha NMAX 155', $profile->vehicle_type);
        $this->assertEquals('ND-12345', $profile->plate_number);
        $this->assertEquals('N02-99-887766', $profile->license_number);
        $this->assertEquals('Pending Verification', $profile->or_cr_status);
        $this->assertFalse((bool)$profile->is_available, 'Courier should not be available before approval');

        // Courier cannot access courier portal
        $gateResponse = $this->actingAs($courier)->get('/courier/deliveries');
        $gateResponse->assertRedirect(route('kyc.pending'));

        // Step 2: Admin Approves Courier
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $approveResponse = $this->actingAs($admin)->post("/admin/kyc/{$courier->id}/approve");
        $approveResponse->assertRedirect();

        $courier->refresh();
        $profile->refresh();

        $this->assertEquals('active', $courier->status);
        $this->assertEquals('approved', $courier->kyc_status);
        $this->assertEquals('Verified & Registered', $profile->or_cr_status);
        $this->assertTrue((bool)$profile->is_available, 'Courier is_available must be true upon approval');

        // Approved courier can access courier deliveries portal
        $courierDeliveriesResponse = $this->actingAs($courier)->get('/courier/deliveries');
        $courierDeliveriesResponse->assertStatus(200);

        $universalDashResponse = $this->actingAs($courier)->get('/dashboard');
        $universalDashResponse->assertRedirect(route('courier.deliveries'));
    }

    /**
     * 3. Test Cart Items and Order Items Variant Fields:
     * - Adding 2 distinct variants of same product to cart creates 2 separate items
     * - Adding same variant increments quantity of matching item
     * - Checkout creates order_items preserving color, size, and sku_snapshot
     */
    public function test_cart_and_order_items_variant_fields_preservation(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Fashion Haven',
            'slug' => 'fashion-haven',
            'status' => 'active',
            'address' => 'Fashion Strip',
            'city' => 'Makati City',
        ]);

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Premium Heritage Linen Shirt',
            'slug' => 'premium-heritage-linen-shirt',
            'sku' => 'LINEN-SHIRT-001',
            'price' => 750.00,
            'stock' => 100,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        // Buyer adds Variant 1: Crimson Red / XL (Qty: 2)
        $addVar1 = $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 2,
            'color' => 'Crimson Red',
            'size' => 'XL',
        ]);
        $addVar1->assertRedirect();

        // Buyer adds Variant 2: Navy Blue / M (Qty: 1)
        $addVar2 = $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'color' => 'Navy Blue',
            'size' => 'M',
        ]);
        $addVar2->assertRedirect();

        // Verify cart has 2 distinct items
        $cart = Cart::where('user_id', $buyer->id)->first();
        $this->assertNotNull($cart);
        $this->assertEquals(2, $cart->items()->count());

        $item1 = $cart->items()->where('color', 'Crimson Red')->where('size', 'XL')->first();
        $this->assertNotNull($item1);
        $this->assertEquals(2, $item1->quantity);
        $this->assertEquals('LINEN-SHIRT-001-Crimson Red-XL', $item1->sku_snapshot);
        $this->assertEquals(750.00, $item1->unit_price);

        $item2 = $cart->items()->where('color', 'Navy Blue')->where('size', 'M')->first();
        $this->assertNotNull($item2);
        $this->assertEquals(1, $item2->quantity);
        $this->assertEquals('LINEN-SHIRT-001-Navy Blue-M', $item2->sku_snapshot);

        // Buyer adds Variant 1 again (Qty: 1) -> Quantity should increment to 3 without creating a 3rd row
        $addVar1Again = $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'color' => 'Crimson Red',
            'size' => 'XL',
        ]);
        $addVar1Again->assertRedirect();

        $this->assertEquals(2, $cart->items()->count());
        $item1->refresh();
        $this->assertEquals(3, $item1->quantity);

        // Step 4: Checkout and verify order_items preservation
        $checkoutResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Maria Clara',
            'recipient_phone' => '09171234567',
            'shipping_address' => 'Unit 402 Casa Real',
            'shipping_city' => 'Manila',
            'shipping_postal_code' => '1000',
            'payment_method' => 'cod',
            'notes' => 'Ring doorbell upon arrival',
        ]);

        $checkoutResponse->assertRedirect();

        // Verify Order
        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('Maria Clara', $order->recipient_name);
        $this->assertEquals('09171234567', $order->recipient_phone);
        $this->assertEquals(3000.00, (float)$order->subtotal); // 3 * 750 + 1 * 750 = 3000

        // Verify Order Items
        $this->assertEquals(2, $order->items()->count());

        $orderItem1 = $order->items()->where('color', 'Crimson Red')->first();
        $this->assertNotNull($orderItem1);
        $this->assertEquals('XL', $orderItem1->size);
        $this->assertEquals(3, $orderItem1->quantity);
        $this->assertEquals(750.00, (float)$orderItem1->unit_price);
        $this->assertEquals(2250.00, (float)$orderItem1->subtotal);
        $this->assertEquals('LINEN-SHIRT-001-Crimson Red-XL', $orderItem1->sku_snapshot);

        $orderItem2 = $order->items()->where('color', 'Navy Blue')->first();
        $this->assertNotNull($orderItem2);
        $this->assertEquals('M', $orderItem2->size);
        $this->assertEquals(1, $orderItem2->quantity);
        $this->assertEquals(750.00, (float)$orderItem2->unit_price);
        $this->assertEquals(750.00, (float)$orderItem2->subtotal);
        $this->assertEquals('LINEN-SHIRT-001-Navy Blue-M', $orderItem2->sku_snapshot);

        // Verify stock decremented properly (100 - 4 = 96)
        $product->refresh();
        $this->assertEquals(96, $product->stock);

        // Verify cart is emptied
        $this->assertEquals(0, $cart->items()->count());
    }

    /**
     * 4. Test delivery_phone consistency across Checkout and Seller Order Dispatch
     */
    public function test_delivery_phone_consistency_and_persistence(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
            'phone' => '09191112222',
        ]);

        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Artisan Roasters',
            'slug' => 'artisan-roasters',
            'status' => 'active',
            'address' => '100 Coffee Lane',
            'city' => 'Baguio City',
        ]);

        $category = Category::create([
            'name' => 'Beverages',
            'slug' => 'beverages',
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Single Origin Arabica Beans',
            'slug' => 'single-origin-arabica-beans',
            'sku' => 'COFFEE-001',
            'price' => 450.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        // Add to cart
        $this->actingAs($buyer)->post('/cart', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $targetPhone = '+63 917 555 1234';

        // Checkout with specific recipient phone
        $checkoutResponse = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => $targetPhone,
            'shipping_address' => 'Block 12 Lot 5 Golden Heights',
            'shipping_city' => 'Antipolo',
            'shipping_postal_code' => '1870',
            'payment_method' => 'cod',
        ]);

        $checkoutResponse->assertRedirect();

        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals($targetPhone, $order->recipient_phone);

        // Verify Delivery record has accurate delivery_phone (not null, not empty)
        $delivery = Delivery::where('order_id', $order->id)->first();
        $this->assertNotNull($delivery, 'Delivery record must exist after checkout');
        $this->assertEquals($targetPhone, $delivery->delivery_phone, 'deliveries.delivery_phone must match recipient_phone');
        $this->assertEquals('Juan Dela Cruz', $delivery->delivery_recipient_name);
        $this->assertEquals('Block 12 Lot 5 Golden Heights, Antipolo', $delivery->delivery_address);

        // Seller transitions order to ready for pickup
        $readyResponse = $this->actingAs($seller)->post("/seller/orders/{$order->id}/ready");
        $readyResponse->assertRedirect();

        $delivery->refresh();
        $this->assertEquals($targetPhone, $delivery->delivery_phone, 'deliveries.delivery_phone must be preserved after seller marks ready');
        $this->assertEquals('unassigned', $delivery->status);
    }

    /**
     * 5. Adversarial Edge Cases:
     * - Rejection feedback validation constraints (min 5, max 1000)
     * - Resubmitting without files retains existing document paths while resetting status
     * - Product without variants creates clean sku_snapshot
     */
    public function test_adversarial_edge_cases_and_validations(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $applicant = User::factory()->create([
            'role' => 'seller',
            'status' => 'pending_approval',
            'kyc_status' => 'pending_approval',
            'id_document_path' => '/storage/kyc_documents/existing_id.pdf',
        ]);

        // Rejection with empty reason should fail validation
        $failReject = $this->actingAs($admin)->post("/admin/kyc/{$applicant->id}/reject", [
            'reason' => '',
        ]);
        $failReject->assertSessionHasErrors(['reason']);

        // Rejection with too short reason (< 5 chars) should fail
        $shortReject = $this->actingAs($admin)->post("/admin/kyc/{$applicant->id}/reject", [
            'reason' => 'Bad',
        ]);
        $shortReject->assertSessionHasErrors(['reason']);

        // Valid rejection
        $validReject = $this->actingAs($admin)->post("/admin/kyc/{$applicant->id}/reject", [
            'reason' => 'Valid rejection reason exceeding minimum characters.',
        ]);
        $validReject->assertRedirect();
        $applicant->refresh();
        $this->assertEquals('rejected', $applicant->kyc_status);

        // Resubmit without uploading new files: should still reset kyc_status to pending_approval and clear feedback
        $resubmitNoFiles = $this->actingAs($applicant)->post('/kyc/resubmit', []);
        $resubmitNoFiles->assertRedirect();

        $applicant->refresh();
        $this->assertEquals('pending_approval', $applicant->kyc_status);
        $this->assertNull($applicant->kyc_feedback);
        $this->assertEquals('/storage/kyc_documents/existing_id.pdf', $applicant->id_document_path);
    }
}
