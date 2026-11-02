<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithRegistrationPolicies;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithRegistrationPolicies;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [...$this->registrationPolicies(), 'sex' => 'Female',
            'birthday' => '1995-05-15', 'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1234!',
            'password_confirmation' => 'Password1234!',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
    }

    public function test_buyer_registration_can_save_a_confirmed_delivery_pin_for_checkout(): void
    {
        $input = [...$this->registrationPolicies(), 'role' => 'buyer', 'sex' => 'Female', 'birthday' => '1995-05-15',
            'name' => 'Map Buyer', 'email' => 'map-buyer@example.test', 'password' => 'Password1234!',
            'password_confirmation' => 'Password1234!', 'phone' => '09171234567', 'region' => '1300000000',
            'province' => 'Metro Manila', 'city' => 'Taguig City', 'barangay' => 'Fort Bonifacio',
            'address' => '123 Test Street', 'postal_code' => '1634',
            'delivery_latitude' => '14.5500000', 'delivery_longitude' => '121.0500000'];
        $this->postJson('/register/validate', [...$input, 'step' => 2, 'delivery_longitude' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_longitude');
        $this->postJson('/register/validate', [...$input, 'step' => 2, 'phone' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/register/validate', [...$input, 'step' => 2, 'delivery_latitude' => '91'])
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_latitude');
        $this->postJson('/register/validate', [...$input, 'step' => 2, 'region' => null, 'address' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['region', 'address']);
        $this->post('/register', $input)->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $buyer = User::where('email', 'map-buyer@example.test')->sole();
        $address = $buyer->addresses()->sole();
        $this->assertSame('Map Buyer', $address->recipient_name);
        $this->assertSame('+639171234567', $address->phone);
        $this->assertSame('City of Taguig', $address->city);
        $this->assertTrue($address->is_default);
        $this->assertEquals(14.55, $address->latitude);
        $this->assertEquals(121.05, $address->longitude);
    }

    public function test_buyer_registration_with_demographic_fields(): void
    {
        $response = $this->post('/register', [...$this->registrationPolicies(),
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'email' => 'maria.delacruz@example.com',
            'password' => 'Password1234!',
            'password_confirmation' => 'Password1234!',
            'sex' => 'Female',
            'birthday' => '2000-05-15',
            'phone' => '+63 917 123 4567',
            'city' => 'Quezon City',
            'address' => 'Unit 401 Katipunan Ave',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));

        $user = User::where('email', 'maria.delacruz@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Maria Santos Dela Cruz', $user->name);
        $this->assertEquals('Maria', $user->first_name);
        $this->assertEquals('Santos', $user->middle_name);
        $this->assertEquals('Dela Cruz', $user->last_name);
        $this->assertEquals('Female', $user->sex);
        $this->assertEquals('2000-05-15', $user->birthday->format('Y-m-d'));
        $this->assertGreaterThanOrEqual(24, $user->age);
        $this->assertEquals('Quezon City', $user->city);
        $this->assertEquals('Unit 401 Katipunan Ave', $user->address);
        $this->assertEquals('+639171234567', $user->phone);
    }

    public function test_buyer_registration_accepts_different_ph_phone_formats(): void
    {
        $phoneFormats = [
            '+63 918 222 3333',
            '09182223334',
            '639182223335',
            '+639182223336',
        ];

        foreach ($phoneFormats as $idx => $phone) {
            $email = "buyer.phone.{$idx}@example.com";
            $response = $this->post('/register', [...$this->registrationPolicies(),
                'name' => 'Buyer Applicant',
                'email' => $email,
                'password' => 'Password1234!',
                'password_confirmation' => 'Password1234!',
                'phone' => $phone,
                'birthday' => '1998-01-01',
                'sex' => 'Male',
                'city' => 'Manila',
                'address' => 'Sample Street',
            ]);

            $response->assertSessionHasNoErrors();
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user);
            $this->assertEquals('+63918222333'.(3 + $idx), $user->phone);
        }
    }
}
