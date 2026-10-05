<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_handlers', function (Blueprint $table) {
            $table->foreignId('logistics_company_id')->nullable()->constrained()->restrictOnDelete();
        });
        // Preserve the existing placement's company. This grants no account or company approval.
        $placements = DB::table('hub_handlers')->join('logistics_hubs', 'logistics_hubs.id', '=', 'hub_handlers.hub_id')
            ->select('hub_handlers.id', 'logistics_hubs.logistics_company_id')->get();
        foreach ($placements as $placement) {
            DB::table('hub_handlers')->where('id', $placement->id)->update(['logistics_company_id' => $placement->logistics_company_id]);
        }
    }

    public function down(): void
    {
        Schema::table('hub_handlers', fn (Blueprint $table) => $table->dropConstrainedForeignId('logistics_company_id'));
    }
};
