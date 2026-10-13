<?php

namespace Tests\Feature\Auth;

use App\Models\Shop;
use App\Models\User;
use App\Services\ApplicationIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationContactUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public static function accountRoles(): array
    {
        return [['buyer'], ['seller'], ['courier'], ['logistics'], ['admin']];
    }

    #[DataProvider('accountRoles')]
    public function test_contact_edits_reject_other_accounts_but_allow_the_current_mobile(string $role): void
    {
        User::factory()->admin()->create(['phone' => '+639171234567']);
        $user = User::factory()->create(['role' => $role, 'birthday' => '2000-01-01', 'phone' => '+639181234567']);
        $before = $user->fresh()->getAttributes();
        $identity = $user->only(['name', 'email']);
        $this->actingAs($user)->patch('/profile', $identity + ['phone' => '0917 123 4567'])->assertSessionHasErrors('phone');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->patch('/profile', $identity + ['phone' => '0918 123 4567'])->assertSessionHasNoErrors();
        $this->assertSame('+639181234567', $user->fresh()->phone);
        $this->patch('/profile', $identity + ['phone' => '0919 123 4567'])->assertSessionHasNoErrors();
        $this->assertSame('+639191234567', $user->fresh()->phone);
        $this->assertSame($before['email'], $user->fresh()->email);
        $this->assertSame($before['status'], $user->fresh()->status);
        $this->assertSame($before['kyc_status'], $user->fresh()->kyc_status);
        $this->patch('/profile', $identity + ['phone' => '   '])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->phone);
    }

    public function test_model_writes_keep_mobiles_canonical_and_reserved_for_closed_accounts(): void
    {
        $owner = User::factory()->create(['phone' => '(0917) 123-4567']);
        $owner->forceFill(['closed_at' => now()])->save();
        $this->assertSame('+639171234567', $owner->phone);
        $this->expectException(ValidationException::class);
        User::factory()->seller()->create(['phone' => '639171234567']);
    }

    public function test_the_database_enforces_mobile_uniqueness_even_without_model_validation(): void
    {
        User::factory()->create(['phone' => '+639171234567']);
        $other = User::factory()->create();
        try {
            DB::table('users')->where('id', $other->id)->update(['phone' => '+639171234567']);
            $this->fail('The database must reject a duplicate canonical mobile.');
        } catch (QueryException $exception) {
            $this->assertArrayHasKey('phone', ApplicationIdentityService::conflictErrors($exception));
        }
        $this->assertNotSame('+639171234567', $other->fresh()->phone);
    }

    public function test_a_mobile_claimed_between_model_validation_and_insert_returns_a_field_error(): void
    {
        $owner = User::factory()->create(['phone' => null]);
        $original = User::getEventDispatcher();
        $events = clone $original;
        User::setEventDispatcher($events);
        $events->listen('eloquent.creating: '.User::class, function (User $applicant) use ($owner): void {
            DB::table('users')->where('id', $owner->id)->update(['phone' => $applicant->phone]);
        });
        try {
            User::factory()->create(['phone' => '+639171234567']);
            $this->fail('The final insert must reject the conflicting mobile.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
            $this->assertDatabaseCount('users', 1);
        } finally {
            User::setEventDispatcher($original);
        }
    }

    public function test_other_database_conflicts_are_not_misreported_as_mobile_errors(): void
    {
        $owner = User::factory()->create();
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => $owner->email]);
    }

    public static function equivalentShopNames(): array
    {
        return [[' bagoo   shop '], ['BAGOO SHOP'], ['Ｂａｇｏｏ Shop'], ["Bagoo\u{00a0}Shop"]];
    }

    #[DataProvider('equivalentShopNames')]
    public function test_shop_names_are_reserved_after_normalizing_case_spacing_and_unicode(string $name): void
    {
        Shop::factory()->create(['name' => 'Bagoo Shop']);
        $this->expectException(ValidationException::class);
        Shop::factory()->create(['name' => $name]);
    }

    public function test_a_shop_can_keep_its_name_and_cannot_forge_or_expose_its_uniqueness_key(): void
    {
        $shop = Shop::factory()->create(['name' => 'Bagoo Shop']);
        $shop->forceFill(['name' => 'Bagoo Shop', 'name_key' => str_repeat('a', 64), 'description' => 'Updated shop details.'])->save();
        $this->assertSame(ApplicationIdentityService::shopNameKey('Bagoo Shop'), $shop->fresh()->name_key);
        $this->assertArrayNotHasKey('name_key', $shop->toArray());
        $shop->update(['name' => 'Bagoo Second Shop']);
        $this->assertSame(ApplicationIdentityService::shopNameKey('Bagoo Second Shop'), $shop->fresh()->name_key);
    }

    public function test_the_database_enforces_shop_name_uniqueness_without_model_validation(): void
    {
        $shop = Shop::factory()->create(['name' => 'Bagoo Shop']);
        $other = Shop::factory()->create(['name' => 'Bagoo Other Shop']);
        try {
            DB::table('shops')->where('id', $other->id)->update(['name_key' => $shop->name_key]);
            $this->fail('The database must reject a duplicate shop name key.');
        } catch (QueryException $exception) {
            $this->assertArrayHasKey('shop_name', ApplicationIdentityService::conflictErrors($exception));
        }
        $this->assertSame('Bagoo Other Shop', $other->fresh()->name);
    }

    public function test_a_shop_name_claimed_between_validation_and_insert_returns_a_field_error(): void
    {
        $owner = Shop::factory()->create(['name' => 'Bagoo Original Shop']);
        $original = Shop::getEventDispatcher();
        $events = clone $original;
        Shop::setEventDispatcher($events);
        $events->listen('eloquent.creating: '.Shop::class, function (Shop $applicant) use ($owner): void {
            DB::table('shops')->where('id', $owner->id)->update(['name' => $applicant->name, 'name_key' => $applicant->name_key]);
        });
        try {
            Shop::factory()->create(['name' => 'Bagoo Proposed Shop']);
            $this->fail('The final insert must reject the conflicting name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shop_name', $exception->errors());
            $this->assertDatabaseCount('shops', 1);
        } finally {
            Shop::setEventDispatcher($original);
        }
    }
}
