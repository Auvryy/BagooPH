<?php

use App\Rules\PhilippineContact;
use App\Services\ApplicationIdentityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $install = fn () => DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE users, shops IN SHARE ROW EXCLUSIVE MODE');
            }
            $phones = [];
            $mobiles = [];
            foreach (DB::table('users')->orderBy('id')->get(['id', 'phone']) as $user) {
                $phone = $user->phone === null || trim($user->phone, ' ') === '' ? null : PhilippineContact::canonical($user->phone);
                if ($user->phone !== null && trim($user->phone, ' ') !== '' && $phone === null) {
                    throw new RuntimeException("Account {$user->id} has an invalid contact number. Correct it before retrying the migration; no records were changed.");
                }
                if ($phone !== null && str_starts_with($phone, '+639')) {
                    if (isset($mobiles[$phone])) {
                        throw new RuntimeException("Accounts {$mobiles[$phone]} and {$user->id} share a mobile number. Resolve ownership before retrying the migration; no records were changed.");
                    }
                    $mobiles[$phone] = $user->id;
                }
                $phones[$user->id] = $phone;
            }
            $names = [];
            $keys = [];
            foreach (DB::table('shops')->orderBy('id')->get(['id', 'name']) as $shop) {
                $key = ApplicationIdentityService::shopNameKey($shop->name);
                if (isset($names[$key])) {
                    throw new RuntimeException("Shops {$names[$key]} and {$shop->id} share a name. Resolve the name conflict before retrying the migration; no records were changed.");
                }
                $names[$key] = $shop->id;
                $keys[$shop->id] = $key;
            }
            Schema::table('shops', fn (Blueprint $table) => $table->string('name_key', 64)->nullable());
            foreach ($phones as $id => $phone) {
                DB::table('users')->where('id', $id)->update(['phone' => $phone]);
            }
            foreach ($keys as $id => $key) {
                DB::table('shops')->where('id', $id)->update(['name_key' => $key]);
            }
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('name_key', 64)->nullable(false)->change();
                $table->unique('name_key');
            });
            DB::statement("CREATE UNIQUE INDEX users_mobile_unique ON users (phone) WHERE phone LIKE '+639%'");
        });
        // SQLite rebuilds the populated shops table when making the key required.
        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints($install);
        } else {
            $install();
        }
    }

    public function down(): void
    {
        $remove = fn () => DB::transaction(function (): void {
            DB::statement('DROP INDEX IF EXISTS users_mobile_unique');
            Schema::table('shops', function (Blueprint $table): void {
                $table->dropUnique(['name_key']);
                $table->dropColumn('name_key');
            });
        });
        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints($remove);
        } else {
            $remove();
        }
    }
};
