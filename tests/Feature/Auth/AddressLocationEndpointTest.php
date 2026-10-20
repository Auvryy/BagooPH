<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressLocationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_every_role_receive_the_same_public_catalogue_as_the_buyer_address_book(): void
    {
        $selection = ['region' => '1300000000', 'province' => 'Metro Manila', 'city' => 'Makati'];
        $public = $this->getJson('/address-locations?'.http_build_query($selection))->assertOk()->json();
        $this->assertSame('City of Makati', $public['resolved']['city']);
        $this->assertContains('Poblacion', array_column($public['barangays'], 'value'));
        $this->assertArrayNotHasKey('users', $public);
        foreach (['buyer', 'seller', 'courier', 'hub', 'admin'] as $host) {
            $this->getJson('http://'.$host.'.localhost/address-locations?'.http_build_query($selection))->assertOk()->assertExactJson($public);
        }
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('http://localhost/address-locations?'.http_build_query($selection))->assertOk()->assertExactJson($public);
            if ($role === 'buyer') {
                $this->getJson(route('buyer.addresses.locations', $selection))->assertOk()->assertExactJson($public);
            }
        }
    }

    public function test_public_options_reject_invalid_parents_types_and_overlong_input(): void
    {
        foreach ([['region' => 'invalid'], ['province' => ['Laguna']], ['city' => str_repeat('a', 256)],
            ['region' => '1300000000', 'province' => 'Laguna'],
            ['region' => '1300000000', 'province' => 'Metro Manila', 'city' => 'City of Cebu']] as $selection) {
            $this->getJson('/address-locations?'.http_build_query($selection))->assertUnprocessable();
        }
        $this->getJson('/address-locations?region=1300000000')->assertOk()->assertJsonPath('resolved.province', 'Metro Manila');
    }
}
