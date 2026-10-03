<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private Delivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->order = Order::factory()->create([
            'order_number' => 'BGO-ORDER-PRIVATE',
            'status' => 'ready_for_pickup',
            'payment_status' => 'pending',
            'shipping_address' => 'Unit 17, Sample Building',
            'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna',
        ]);
        $this->delivery = Delivery::factory()->create([
            'order_id' => $this->order->id,
            'tracking_number' => 'BGO-TRACKING123',
            'status' => 'unassigned',
            'delivery_recipient_name' => 'José Li',
            'delivery_phone' => '+639171234567',
            'delivery_address' => 'Unit 17, Sample Building, Private Landmark',
            'proof_image' => '/storage/delivery-proofs/private.jpg',
            'estimated_delivery_at' => null,
        ]);
        DeliveryCheckpoint::record($this->delivery, 'order_placed', 'Private Landmark', 'Private notes +639171234567', proofImage: '/storage/private.jpg');
        DeliveryCheckpoint::record($this->delivery, 'seller_pack', 'Private seller address', 'Private parcel contents');
    }

    public function test_guest_can_read_tracking_without_operational_actions(): void
    {
        $this->get('/track/'.$this->delivery->tracking_number)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Tracking')
                ->where('parcel.status', 'ready_for_pickup')
                ->where('parcel.delivery_recipient_name', 'J*** L***')
                ->where('parcel.delivery_address', 'Protected location, Santa Cruz, Laguna')
                ->where('parcel.estimated_delivery_at', null)
                ->has('parcel.checkpoints', 2)
                ->where('availableActions', [])
                ->missing('parcel.items')
                ->missing('parcel.order_number')
                ->missing('parcel.total_amount')
                ->missing('parcel.courier_name')
                ->missing('parcel.checkpoints.0.notes')
            );
    }

    public static function publicActors(): array
    {
        return array_map(fn ($role) => [$role], [null, 'owner', 'buyer', 'seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('publicActors')]
    public function test_public_data_stays_masked_for_every_role_on_web_and_api(?string $role): void
    {
        if ($role === 'owner') {
            $this->actingAs($this->order->buyer);
        } elseif ($role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        foreach (['/track/', '/api/track/'] as $prefix) {
            $response = $this->getJson($prefix.$this->delivery->tracking_number)->assertOk();
            $response->assertJsonPath('available_actions', [])
                ->assertJsonPath('parcel.delivery_recipient_name', 'J*** L***')
                ->assertJsonPath('parcel.delivery_phone', '••• ••• 4567');
            $this->assertSame([
                'tracking_number', 'status', 'order_status', 'delivery_recipient_name', 'delivery_phone',
                'delivery_address', 'estimated_delivery_at', 'delivered_at', 'checkpoints',
            ], array_keys($response->json('parcel')));
            $json = json_encode($response->json('parcel'), JSON_UNESCAPED_UNICODE);
            foreach (['José Li', '+639171234567', 'Unit 17', 'Private', '/storage/', 'BGO-ORDER-PRIVATE'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
    }

    public function test_checkpoint_free_text_and_unknown_events_are_never_public(): void
    {
        DeliveryCheckpoint::record($this->delivery, 'cod_remittance', 'Private finance office', 'Private money record');
        $this->getJson('/api/track/'.$this->delivery->tracking_number)
            ->assertOk()
            ->assertJsonCount(2, 'parcel.checkpoints')
            ->assertJsonPath('parcel.checkpoints.0.location_name', 'BagooPH');
    }

    public function test_lookup_normalizes_case_and_spaces_but_does_not_accept_order_numbers(): void
    {
        $this->getJson('/track?number=%20bgo-tracking123%20')->assertOk();
        $this->getJson('/api/track/'.$this->order->order_number)->assertNotFound();
    }

    public function test_empty_not_found_and_invalid_searches_have_clear_states(): void
    {
        $this->get('/track')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('parcel', null)->where('notFound', false)->where('validationMessage', null));
        $this->get('/track/BGO-NOTFOUND')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('parcel', null)->where('notFound', true));
        $this->getJson('/track')->assertStatus(400);
        $this->get('/track?number=%3Cscript%3E')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('parcel', null)->where('notFound', false)->where('searchedNumber', '')
            ->where('validationMessage', fn ($message) => is_string($message) && $message !== ''));
    }

    public static function invalidCodes(): array
    {
        return [
            [str_repeat('A', 65)], ['BGO-<script>'], ['BGO-123 456'], ["BGO-123\0"],
            ["\tBGO-123"], ["BGO-123\n"], ['BGO-１２３'], ["BGO-\u{202E}123"],
            ["BGO-\u{200B}123"], [['BGO-TRACKING123']],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_malformed_code_is_rejected_without_a_lookup_or_mutation(mixed $code): void
    {
        $this->getJson('/track?'.http_build_query(['number' => $code]))
            ->assertStatus(422)->assertJsonPath('parcel', null)->assertJsonPath('available_actions', []);
        $this->assertSame('unassigned', $this->delivery->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 2);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_minimum_and_maximum_code_lengths_are_valid_searches(): void
    {
        foreach (['A', str_repeat('A', 64)] as $code) {
            $this->getJson('/track?number='.$code)->assertNotFound();
        }
    }

    public static function legacyTrackingActions(): array
    {
        $cases = [];
        foreach ([null, 'buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['courier_claim', 'courier_pickup', 'hub_intake', 'hub_sorted', 'out_for_delivery', 'delivered', 'buyer_confirm'] as $action) {
                $cases[($role ?? 'guest').' '.$action] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('legacyTrackingActions')]
    public function test_old_tracking_actions_cannot_change_custody_proof_or_settlement(?string $role, string $action): void
    {
        if ($role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        $beforeOrder = $this->order->fresh()->getAttributes();
        $beforeDelivery = $this->delivery->fresh()->getAttributes();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/track/'.$this->delivery->tracking_number.'/action', [
                'action' => $action, 'proof_image' => '/storage/fake.jpg', 'status' => 'delivered',
            ])->assertNotFound();
        }
        $this->assertSame($beforeOrder, $this->order->fresh()->getAttributes());
        $this->assertSame($beforeDelivery, $this->delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 2);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_tracking_reads_are_side_effect_free_and_reject_mutating_http_methods(): void
    {
        $beforeOrder = $this->order->fresh()->getAttributes();
        $beforeDelivery = $this->delivery->fresh()->getAttributes();
        foreach (['/track/', '/api/track/'] as $prefix) {
            $url = $prefix.$this->delivery->tracking_number;
            $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->getJson($url)->assertOk();
            foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
                $this->json($method, $url, ['status' => 'completed'])->assertStatus(405);
            }
        }
        $this->assertSame($beforeOrder, $this->order->fresh()->getAttributes());
        $this->assertSame($beforeDelivery, $this->delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 2);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_web_and_api_share_a_rate_limit_even_when_logged_in(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'courier']));
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $prefix = $attempt % 2 ? '/track/' : '/api/track/';
            $this->getJson($prefix.$this->delivery->tracking_number)->assertOk();
        }
        $this->getJson('/track/'.$this->delivery->tracking_number)->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/track/'.$this->delivery->tracking_number)->assertStatus(429);
    }

    public static function commercialStates(): array
    {
        return [
            ['at_sorting_center', 'arrived_at_mother_hub', 'at_sorting_center'],
            ['sorted', 'ready_for_hub_pickup', 'sorted'],
            ['delivery_failed', 'return_to_sender', 'delivery_failed'],
            ['delivered', 'delivered', 'delivered'],
            ['completed', 'delivered', 'completed'],
            ['cancelled', 'unassigned', 'cancelled'],
            ['returned', 'returned', 'returned'],
            ['pending', 'unassigned', 'placed'],
            ['processing', 'unassigned', 'preparing'],
            ['shipped', 'arrived_at_mother_hub', 'at_sorting_center'],
        ];
    }

    #[DataProvider('commercialStates')]
    public function test_public_status_uses_the_commercial_lifecycle(string $orderStatus, string $deliveryStatus, string $expected): void
    {
        $this->order->update(['status' => $orderStatus]);
        $this->delivery->update(['status' => $deliveryStatus]);
        $this->getJson('/api/track/'.$this->delivery->tracking_number)->assertOk()->assertJsonPath('parcel.status', $expected);
    }
}
