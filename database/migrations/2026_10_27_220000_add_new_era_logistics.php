<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->unsignedInteger('base_shipping_cents')->default(6000);
            $table->unsignedInteger('included_weight_grams')->default(1000);
            $table->unsignedInteger('additional_kg_cents')->default(0);
            $table->string('shipping_earnings_model', 24)->default('dedicated_fleet');
            $table->unsignedInteger('tariff_version')->default(1);
        });
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->json('postal_codes')->nullable();
        });
        $this->reference('logistics_hubs', 'parent_hub_id', 'logistics_hubs');
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('logistics_flow_version')->default(1);
            $table->json('shipping_quote')->nullable();
        });
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('pickup_province', 100)->nullable();
            $table->string('pickup_postal_code', 4)->nullable();
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->json('route_hub_ids')->nullable();
            $table->boolean('same_hub_bypass')->default(false);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('temporary_password_expires_at')->nullable();
        });
        $this->reference('users', 'provisioned_by_id', 'users');
        $this->reference('users', 'provisioned_company_id', 'logistics_companies');
        Schema::create('company_staff_provisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('kind', 16);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('logistics_tariff_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['logistics_company_id', 'version']);
        });
        Schema::create('company_staff_login_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('mother_bags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('destination_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sealed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('manifest_id')->nullable()->constrained('logistics_manifests')->restrictOnDelete();
            $table->foreignId('loaded_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('creation_token');
            $table->timestamp('loaded_at')->nullable();
            $table->string('master_barcode_scanned', 80)->nullable();
            $table->string('seal_number', 80)->nullable();
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_id', 'creation_token']);
        });
        Schema::create('mother_bag_parcels', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('mother_bag_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('loaded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('barcode_scanned');
            $table->json('source_state');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['mother_bag_id', 'delivery_id']);
        });
        Schema::create('mother_bag_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('mother_bag_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('barcode_scanned')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('mother_bag_seals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('mother_bag_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->string('seal_number', 80);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['logistics_company_id', 'seal_number']);
        });
        Schema::create('logistics_earnings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_key', 160)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('checkpoint_id')->constrained('delivery_checkpoints')->restrictOnDelete();
            $table->string('role', 24);
            $table->string('pool', 24);
            $table->unsignedBigInteger('amount_cents');
            $table->bigInteger('net_amount_cents');
            $table->json('snapshot');
            $table->timestamp('earned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'role', 'earned_at']);
            $table->index(['logistics_company_id', 'role', 'earned_at']);
        });
        Schema::create('logistics_earning_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('logistics_earning_id')->constrained('logistics_earnings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 24);
            $table->string('source_reference', 160);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['logistics_earning_id', 'event_type', 'source_reference'], 'earning_source_event_unique');
        });
        Schema::create('rider_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->json('closing_snapshot')->nullable();
            $table->timestamps();
        });
        foreach (['mother_bag_events', 'mother_bag_seals', 'logistics_earning_events', 'logistics_tariff_events', 'company_staff_login_resets'] as $table) {
            $this->retain($table);
        }
        $this->retain('logistics_earnings', ['id', 'source_key', 'order_id', 'delivery_id', 'logistics_company_id', 'user_id', 'checkpoint_id', 'role', 'pool', 'amount_cents', 'net_amount_cents', 'snapshot', 'earned_at', 'created_at']);
        $this->retain('mother_bags', ['id', 'reference', 'logistics_company_id', 'source_hub_id', 'destination_hub_id', 'created_by_id', 'creation_token', 'created_at']);
        $this->retain('rider_shifts', ['id', 'user_id', 'logistics_company_id', 'hub_id', 'started_at', 'created_at']);
    }

    public function down(): void
    {
        throw new LogicException('Version-two route, earnings, staff and shift evidence must be retained. Roll forward with a reviewed repair.');
    }

    private function reference(string $table, string $column, string $related): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Adding a nullable reference directly avoids rebuilding a table used by retained evidence triggers.
            DB::statement('ALTER TABLE "'.$table.'" ADD COLUMN "'.$column.'" INTEGER REFERENCES "'.$related.'"("id") ON DELETE RESTRICT');
        } else {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId($column)->nullable()->constrained($related)->restrictOnDelete());
        }
    }

    private function retain(string $table, array $fixed = []): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $fixed ? ' WHEN '.implode(' OR ', array_map(fn ($field) => 'NEW.'.$field.' IS NOT OLD.'.$field, $fixed)) : '';
            DB::unprepared("CREATE TRIGGER era_{$table}_update BEFORE UPDATE ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Original logistics evidence is retained.'); END");
            DB::unprepared("CREATE TRIGGER era_{$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Original logistics evidence is retained.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $changed = $fixed ? 'ROW('.implode(', ', array_map(fn ($field) => 'NEW.'.$field.'::text', $fixed)).') IS DISTINCT FROM ROW('.implode(', ', array_map(fn ($field) => 'OLD.'.$field.'::text', $fixed)).')' : 'TRUE';
            DB::unprepared("CREATE FUNCTION era_retain_{$table}() RETURNS trigger AS \$\$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Original logistics evidence is retained.' USING ERRCODE = '23000'; END IF;
                IF {$changed} THEN RAISE EXCEPTION 'Original logistics evidence is retained.' USING ERRCODE = '23000'; END IF;
                RETURN NEW; END; \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER era_{$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION era_retain_{$table}();");
        }
    }
};
