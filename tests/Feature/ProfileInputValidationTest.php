<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function profilePortals(): array
    {
        return [
            'buyer' => ['buyer', 'POST', '/buyer/profile'],
            'seller' => ['seller', 'POST', '/seller/profile'],
            'seller subdomain' => ['seller', 'POST', 'http://seller.localhost/profile'],
            'courier' => ['courier', 'PATCH', '/courier/profile/account'],
            'courier subdomain' => ['courier', 'PATCH', 'http://courier.localhost/profile/account'],
        ];
    }

    #[DataProvider('profilePortals')]
    public function test_invalid_phone_text_is_rejected_without_changes(string $role, string $method, string $url): void
    {
        $user = $this->account($role);
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->json($method, $url, [
            'name' => $user->name, 'email' => $user->email, 'phone' => 'letters09171234567',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    #[DataProvider('profilePortals')]
    public function test_edge_controls_are_not_trimmed_into_a_valid_phone(string $role, string $method, string $url): void
    {
        $user = $this->account($role);
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->json($method, $url, [
            'name' => $user->name, 'email' => $user->email, 'phone' => "\t09171234567\n",
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    #[DataProvider('profilePortals')]
    public function test_valid_personal_contact_is_stored_canonically(string $role, string $method, string $url): void
    {
        $user = $this->account($role);
        $this->actingAs($user)->json($method, $url, [
            'name' => $user->name, 'email' => $user->email, 'phone' => '0917 123 4567',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('+639171234567', $user->fresh()->phone);
        $this->assertSame('Juan Santos', $user->fresh()->name);
        $this->assertSame($role, $user->fresh()->role);
    }

    public function test_case_insensitive_email_collision_is_rejected(): void
    {
        User::factory()->create(['email' => 'OTHER@example.test']);
        $user = $this->account('seller');
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->postJson('/seller/profile', [
            'name' => $user->name, 'email' => 'other@example.test', 'phone' => $user->phone,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public static function restrictedAccounts(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach (['inactive', 'suspended', 'unknown'] as $status) {
                $cases[$role.' '.$status] = [$role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedAccounts')]
    public function test_generic_profile_cannot_bypass_current_portal_eligibility(string $role, string $status): void
    {
        $user = $this->account($role);
        $user->update(['status' => $status]);
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->patchJson('/profile', [
            'name' => $user->name, 'email' => 'replacement@example.test',
        ])->assertForbidden();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_invalid_legacy_name_needs_review_instead_of_silent_profile_success(): void
    {
        $user = $this->account('buyer');
        $user->update(['name' => '<b>Juan Santos</b>']);
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->postJson('/buyer/profile', [
            'name' => $user->name, 'phone' => $user->phone,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public static function malformedNames(): array
    {
        return ['digits' => ['Juan123'], 'markup' => ['<b>Juan</b>'], 'punctuation' => ['---'],
            'control' => ["\tJuan Santos\n"], 'hidden' => ["Juan\u{200B} Santos"], 'too long' => [str_repeat('J', 101)]];
    }

    #[DataProvider('malformedNames')]
    public function test_generic_profile_rejects_malformed_names(string $name): void
    {
        $user = $this->account('buyer');
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->patchJson('/profile', ['name' => $name, 'email' => $user->email])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_generic_profile_rejects_case_insensitive_email_collision_without_a_server_error(): void
    {
        User::factory()->create(['email' => 'OTHER@example.test']);
        $user = $this->account('buyer');
        $before = $user->fresh()->getRawOriginal();
        $this->actingAs($user)->patchJson('/profile', ['name' => $user->name, 'email' => 'other@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_generic_profile_rejects_a_normalized_replacement_and_preserves_reviewed_identity(): void
    {
        $user = $this->account('buyer');
        $identity = $user->only(['name', 'birthday', 'role', 'kyc_status', 'identity_version', 'email']);
        $this->actingAs($user)->patchJson('/profile', ['name' => $user->name, 'email' => '  Contact@EXAMPLE.TEST  '])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertEquals($identity, $user->fresh()->only(array_keys($identity)));
    }

    #[DataProvider('restrictedAccounts')]
    public function test_generic_profile_read_cannot_bypass_portal_eligibility(string $role, string $status): void
    {
        $user = $this->account($role);
        $user->update(['status' => $status]);
        $this->actingAs($user)->getJson('/profile')->assertForbidden();
    }

    public static function avatarPortals(): array
    {
        return ['buyer' => ['buyer', '/buyer/profile'], 'seller' => ['seller', '/seller/profile']];
    }

    #[DataProvider('avatarPortals')]
    public function test_invalid_contact_cannot_remove_or_replace_an_avatar(string $role, string $url): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/retained.png', 'retained avatar');
        $user = $this->account($role);
        $user->update(['avatar' => '/storage/avatars/retained.png']);
        $before = $user->fresh()->getRawOriginal();

        foreach ([['remove_avatar' => true], ['avatar' => UploadedFile::fake()->image('replacement.png')]] as $change) {
            $this->actingAs($user)->postJson($url, $change + ['name' => $user->name, 'email' => $user->email, 'phone' => 'letters09171234567'])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
            $this->assertSame($before, $user->fresh()->getRawOriginal());
            $this->assertSame('retained avatar', Storage::disk('public')->get('avatars/retained.png'));
            $this->assertSame(['avatars/retained.png'], Storage::disk('public')->allFiles('avatars'));
        }
    }

    public function test_valid_profile_edit_preserves_order_and_waybill_contacts(): void
    {
        $user = $this->account('buyer');
        $order = Order::factory()->create(['buyer_id' => $user->id, 'status' => 'placed', 'recipient_name' => 'Recorded Buyer', 'recipient_phone' => '+639181234567']);
        $delivery = Delivery::factory()->create(['order_id' => $order->id, 'delivery_recipient_name' => $order->recipient_name, 'delivery_phone' => $order->recipient_phone]);
        $before = [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal()];
        $this->actingAs($user)->postJson('/buyer/profile', ['name' => $user->name, 'phone' => '0917 123 4567'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('+639171234567', $user->fresh()->phone);
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal()]);
    }

    private function account(string $role): User
    {
        return User::factory()->create([
            'role' => $role, 'name' => 'Juan Santos', 'phone' => '+639189876543',
            'birthday' => '2000-01-01', 'status' => 'active', 'kyc_status' => 'approved',
        ]);
    }
}
