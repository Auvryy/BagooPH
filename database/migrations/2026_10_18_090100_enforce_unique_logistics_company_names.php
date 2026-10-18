<?php

use App\Services\ApplicationIdentityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE logistics_companies IN SHARE ROW EXCLUSIVE MODE');
            }
            $owners = [];
            $keys = [];
            foreach (DB::table('logistics_companies')->orderBy('id')->get(['id', 'name']) as $company) {
                $key = ApplicationIdentityService::shopNameKey($company->name);
                if (isset($owners[$key])) {
                    throw new RuntimeException("Logistics companies {$owners[$key]} and {$company->id} share a name. Resolve the conflict before retrying; no company records were changed.");
                }
                $owners[$key] = $company->id;
                $keys[$company->id] = $key;
            }
            Schema::table('logistics_companies', fn (Blueprint $table) => $table->string('name_key', 64)->default(''));
            foreach ($keys as $id => $key) {
                DB::table('logistics_companies')->where('id', $id)->update(['name_key' => $key]);
            }
            Schema::table('logistics_companies', fn (Blueprint $table) => $table->unique('name_key'));
        });
    }

    public function down(): void
    {
        Schema::table('logistics_companies', fn (Blueprint $table) => $table->dropUnique(['name_key']));
        DB::statement('ALTER TABLE logistics_companies DROP COLUMN name_key');
    }
};
