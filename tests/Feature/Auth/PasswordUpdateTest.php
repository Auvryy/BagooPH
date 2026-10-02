<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_in_every_role_can_update_their_password(): void
    {
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $response = $this
                ->actingAs($user)
                ->from('/profile')
                ->put('/password', [
                    'current_password' => 'password',
                    'password' => 'NewPassword2026!',
                    'password_confirmation' => 'NewPassword2026!',
                ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect('/profile');

            $this->assertTrue(Hash::check('NewPassword2026!', $user->refresh()->password));
        }
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassword2026!',
                'password_confirmation' => 'NewPassword2026!',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');
    }

    public function test_email_must_be_verified_before_password_can_be_updated(): void
    {
        $user = User::factory()->unverified()->courier()->create();

        $response = $this
            ->actingAs($user)
            ->from('/courier/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NewPassword2026!',
                'password_confirmation' => 'NewPassword2026!',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect('/courier/profile');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_new_password_must_follow_the_12_character_minimum(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'TooShort1!',
                'password_confirmation' => 'TooShort1!',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_user_implements_the_email_verification_contract(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, User::factory()->make());
    }
}
