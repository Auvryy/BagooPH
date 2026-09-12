<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
    }

    public function test_buyer_registration_with_demographic_fields(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'email' => 'maria.delacruz@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'sex' => 'Female',
            'birthday' => '2000-05-15',
            'phone' => '+63 917 123 4567',
            'city' => 'Quezon City',
            'address' => 'Unit 401 Katipunan Ave',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));

        $user = \App\Models\User::where('email', 'maria.delacruz@example.com')->first();
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
        $this->assertEquals('+63 917 123 4567', $user->phone);
    }

    public function test_buyer_registration_accepts_different_ph_phone_formats(): void
    {
        $phoneFormats = [
            '+63 918 222 3333',
            '09182223333',
            '9182223333',
            '+639182223333',
        ];

        foreach ($phoneFormats as $idx => $phone) {
            $email = "buyer.phone.{$idx}@example.com";
            $response = $this->post('/register', [
                'name' => "Buyer {$idx}",
                'email' => $email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'phone' => $phone,
                'birthday' => '1998-01-01',
                'sex' => 'Male',
                'city' => 'Manila',
                'address' => 'Sample Street',
            ]);

            $response->assertSessionHasNoErrors();
            $user = \App\Models\User::where('email', $email)->first();
            $this->assertNotNull($user);
            $this->assertEquals($phone, $user->phone);
        }
    }
}
