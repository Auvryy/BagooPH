<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropRequestGuard();
        // SQLite rebuilds this table to change nullability. Restore its history guards afterwards.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER rider_company_request_events_no_update; DROP TRIGGER rider_company_request_events_no_delete;');
        }
        Schema::table('rider_company_request_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->text('reason')->nullable();
        });
        Schema::create('rider_company_offers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('rider_company_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('issuer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->foreignId('hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('logistics_fleet')->restrictOnDelete();
            $table->string('barangay')->nullable();
            $table->text('terms');
            $table->char('terms_hash', 64);
            $table->jsonb('sources');
            $table->char('source_hash', 64);
            $table->jsonb('display');
            $table->timestamp('issued_at', 6);
            $table->timestamp('expires_at', 6);
            $table->unique(['rider_company_request_id', 'version'], 'rco_request_version');
            $table->index('expires_at');
        });
        Schema::table('rider_company_requests', fn (Blueprint $table) => $table->foreignId('current_offer_id')->nullable()->constrained('rider_company_offers')->restrictOnDelete());
        Schema::table('rider_company_request_events', fn (Blueprint $table) => $table->foreignId('offer_id')->nullable()->constrained('rider_company_offers')->restrictOnDelete());
        Schema::create('rider_company_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained('rider_company_offers')->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('logistics_company_id')->constrained()->restrictOnDelete();
            $table->uuid('request_token');
            $table->char('fingerprint', 64);
            $table->char('terms_hash', 64);
            $table->char('source_hash', 64);
            $table->boolean('acknowledged');
            $table->timestamp('accepted_at', 6);
            $table->unique(['rider_id', 'request_token'], 'rcc_rider_token');
        });
        Schema::table('logistics_placement_records', fn (Blueprint $table) => $table->foreignId('rider_company_consent_id')->nullable()->unique()->constrained('rider_company_consents')->restrictOnDelete());
        if (DB::getDriverName() === 'sqlite') {
            $this->immutable('rider_company_request_events');
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'logistics_placements_no_'.strtolower($operation);
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}; CREATE TRIGGER {$name} BEFORE {$operation} ON logistics_placement_records BEGIN SELECT RAISE(ABORT, 'Logistics placement records are immutable.'); END");
            }
        }

        foreach (['rider_company_offers', 'rider_company_consents'] as $table) {
            $this->immutable($table);
        }
        $this->sourceVersions();
        DB::statement('DROP INDEX rcr_one_pending_rider');
        DB::statement("CREATE UNIQUE INDEX rcr_one_pending_rider ON rider_company_requests (rider_id) WHERE status IN ('pending', 'offered')");
        $this->requestGuard();
    }

    private function sourceVersions(): void
    {
        // Observe only assignment/identity fields. Contact, photo and ordinary branding edits do not stale an offer.
        Schema::create('rider_company_scope_changes', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 12);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('recorded_at', 6)->useCurrent();
            $table->index(['source_type', 'source_id', 'id'], 'rcs_source_version');
        });
        $this->immutable('rider_company_scope_changes');
        $sources = [
            'account' => ['users', ['name', 'first_name', 'last_name', 'middle_name', 'sex', 'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'birthday', 'closed_at', 'identity_version', 'restriction_version', 'email_verified_at', 'id_document_path', 'driver_license_path', 'or_cr_path']],
            'company' => ['logistics_companies', ['user_id', 'name', 'code', 'status', 'is_active', 'restriction_version', 'rider_intake_enabled', 'rider_intake_version']],
            'hub' => ['logistics_hubs', ['logistics_company_id', 'name', 'code', 'tier', 'address', 'province', 'city_municipality', 'barangay', 'is_active', 'coverage_barangays', 'restriction_version']],
            'fleet' => ['logistics_fleet', ['logistics_company_id', 'hub_id', 'assigned_driver_id', 'vehicle_type', 'plate_number', 'model', 'capacity_kg', 'status', 'restriction_version']],
            'profile' => ['courier_profiles', ['logistics_company_id', 'assigned_hub_id', 'assigned_barangay', 'vehicle_id', 'vehicle_type', 'plate_number', 'license_number', 'or_cr_status']],
        ];
        foreach ($sources as $kind => [$table, $columns]) {
            $same = implode(' AND ', array_map(fn ($column) => DB::getDriverName() === 'pgsql'
                ? ($column === 'coverage_barangays'
                    ? 'to_jsonb(NEW.coverage_barangays) IS NOT DISTINCT FROM to_jsonb(OLD.coverage_barangays)'
                    : "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}")
                : "NEW.{$column} IS OLD.{$column}", $columns));
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER rcs_watch_{$kind} AFTER UPDATE ON {$table} WHEN NOT ({$same}) BEGIN INSERT INTO rider_company_scope_changes (source_type, source_id) VALUES ('{$kind}', NEW.id); END");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION rcs_watch_{$kind}() RETURNS trigger AS \$\$ BEGIN IF NOT ({$same}) THEN INSERT INTO rider_company_scope_changes (source_type, source_id) VALUES ('{$kind}', NEW.id); END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER rcs_watch_{$kind} AFTER UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION rcs_watch_{$kind}();");
            }
        }
    }

    private function immutable(string $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Rider company history is immutable.'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION {$table}_immutable() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Rider company history is immutable.' USING ERRCODE = '23000'; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_immutable();");
        }
    }

    private function dropRequestGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER rcr_guard_update; DROP TRIGGER rcr_guard_delete; DROP TRIGGER rcr_guard_insert;');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER rcr_guard_mutation ON rider_company_requests; DROP FUNCTION rcr_guard_mutation();');
        }
    }

    private function requestGuard(): void
    {
        $same = fn ($column) => DB::getDriverName() === 'pgsql' ? "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}" : "NEW.{$column} IS OLD.{$column}";
        $unchanged = implode(' AND ', array_map($same, ['id', 'reference', 'logistics_company_id', 'rider_id', 'request_token', 'submission_fingerprint', 'intake_version', 'company_snapshot', 'profile_snapshot', 'note', 'sharing_acknowledged', 'submitted_at']));
        $transition = "((OLD.status = 'pending' AND NEW.status IN ('withdrawn', 'rejected', 'offered')) OR (OLD.status = 'offered' AND NEW.status IN ('offered', 'accepted', 'withdrawn', 'declined', 'cancelled', 'expired')))";
        $actor = "((e.action IN ('withdrawn', 'accepted', 'declined') AND e.actor_id = OLD.rider_id) OR (e.action IN ('offered', 'rejected', 'cancelled') AND e.actor_id = (SELECT user_id FROM logistics_companies WHERE id = OLD.logistics_company_id)) OR (e.action = 'expired' AND e.actor_id IS NULL))";
        $offer = "((NEW.status = 'offered' AND NEW.current_offer_id IS NOT NULL AND EXISTS (SELECT 1 FROM rider_company_offers o WHERE o.id = NEW.current_offer_id AND o.rider_company_request_id = OLD.id)) OR (NEW.status != 'offered' AND ".$same('current_offer_id').'))';
        $eventOffer = DB::getDriverName() === 'pgsql' ? 'e.offer_id IS NOT DISTINCT FROM NEW.current_offer_id' : 'e.offer_id IS NEW.current_offer_id';
        $accepted = "(NEW.status != 'accepted' OR EXISTS (SELECT 1 FROM rider_company_consents c JOIN logistics_placement_records p ON p.rider_company_consent_id = c.id WHERE c.offer_id = e.offer_id AND c.rider_id = OLD.rider_id AND c.logistics_company_id = OLD.logistics_company_id AND c.accepted_at = e.recorded_at AND c.request_token = e.request_token AND c.fingerprint = e.fingerprint AND p.user_id = OLD.rider_id AND p.logistics_company_id = OLD.logistics_company_id AND p.recorded_at = c.accepted_at))";
        $expiry = "(NEW.status != 'expired' OR EXISTS (SELECT 1 FROM rider_company_offers o WHERE o.id = e.offer_id AND o.expires_at <= e.recorded_at))";
        $reason = "(NEW.status NOT IN ('rejected', 'cancelled') OR (e.reason IS NOT NULL AND length(trim(e.reason)) BETWEEN 5 AND 500))";
        $issued = "(NEW.status != 'offered' OR EXISTS (SELECT 1 FROM rider_company_offers o WHERE o.id = e.offer_id AND o.issuer_id = e.actor_id AND o.issued_at = e.recorded_at AND o.version = COALESCE((SELECT version FROM rider_company_offers previous WHERE previous.id = OLD.current_offer_id), 0) + 1))";
        $receipt = "EXISTS (SELECT 1 FROM rider_company_request_events e WHERE e.rider_company_request_id = OLD.id AND e.request_version = NEW.version AND e.action = NEW.status AND {$actor} AND {$eventOffer} AND {$accepted} AND {$expiry} AND {$reason} AND {$issued})";
        $withdrawn = "((NEW.status = 'withdrawn' AND NEW.withdrawn_at IS NOT NULL AND NEW.withdrawn_at >= OLD.submitted_at AND EXISTS (SELECT 1 FROM rider_company_request_events e WHERE e.rider_company_request_id = OLD.id AND e.request_version = NEW.version AND e.recorded_at = NEW.withdrawn_at)) OR (NEW.status != 'withdrawn' AND NEW.withdrawn_at IS NULL))";
        $valid = "{$unchanged} AND {$transition} AND NEW.version = OLD.version + 1 AND {$receipt} AND {$offer} AND {$withdrawn}";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER rcr_guard_update BEFORE UPDATE ON rider_company_requests WHEN NOT ({$valid}) BEGIN SELECT RAISE(ABORT, 'An evidenced withdrawal or authorized decision receipt must retain the original submission.'); END");
            DB::unprepared("CREATE TRIGGER rcr_guard_delete BEFORE DELETE ON rider_company_requests BEGIN SELECT RAISE(ABORT, 'Rider company requests retain their history.'); END");
            DB::unprepared("CREATE TRIGGER rcr_guard_insert BEFORE INSERT ON rider_company_requests WHEN NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NEW.current_offer_id IS NOT NULL OR NEW.sharing_acknowledged != 1 OR NEW.intake_version < 1 BEGIN SELECT RAISE(ABORT, 'A rider company request must begin as an acknowledged pending submission.'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION rcr_guard_mutation() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Rider company requests retain their history.' USING ERRCODE = '23000'; ELSIF TG_OP = 'INSERT' THEN IF NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NEW.current_offer_id IS NOT NULL OR NOT NEW.sharing_acknowledged OR NEW.intake_version < 1 THEN RAISE EXCEPTION 'A rider company request must begin as an acknowledged pending submission.' USING ERRCODE = '23000'; END IF; ELSIF NOT ({$valid}) THEN RAISE EXCEPTION 'An evidenced withdrawal or authorized decision receipt must retain the original submission.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER rcr_guard_mutation BEFORE INSERT OR UPDATE OR DELETE ON rider_company_requests FOR EACH ROW EXECUTE FUNCTION rcr_guard_mutation();");
        }
    }

    public function down(): void
    {
        // Historical applications may already depend on these decisions or placements.
        // A reviewed forward repair is safer than removing that provenance on rollback.
        throw new LogicException('Retain rider company offers, consent and placement provenance; use a reviewed forward migration.');
    }
};
