<?php

// Retain requested facilities and distinguish hub invitations from rider applications.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_company_requests', function (Blueprint $table) {
            $table->foreignId('requested_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS rcr_one_pending_rider');
            DB::statement("CREATE UNIQUE INDEX rcr_one_pending_rider ON rider_company_requests (rider_id) WHERE status IN ('pending', 'offered')");
            DB::unprepared("CREATE TRIGGER rcr_guard_delete BEFORE DELETE ON rider_company_requests BEGIN SELECT RAISE(ABORT, 'Rider company requests retain their history.'); END");
            DB::unprepared('DROP TRIGGER IF EXISTS rcr_guard_insert;');
            DB::unprepared("CREATE TRIGGER rcr_guard_insert BEFORE INSERT ON rider_company_requests WHEN NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NEW.current_offer_id IS NOT NULL OR (NEW.invited_by_id IS NULL AND (NEW.sharing_acknowledged != 1 OR NEW.intake_version < 1)) OR (NEW.invited_by_id IS NOT NULL AND (NEW.requested_hub_id IS NULL OR NEW.sharing_acknowledged != 0)) BEGIN SELECT RAISE(ABORT, 'A request must begin as an application or an unaccepted hub invitation.'); END");
            DB::unprepared('DROP TRIGGER IF EXISTS rcr_guard_update;');
        }
        $this->requestGuard();
    }

    private function requestGuard(): void
    {
        $same = fn ($column) => DB::getDriverName() === 'pgsql' ? "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}" : "NEW.{$column} IS OLD.{$column}";
        $unchanged = implode(' AND ', array_map($same, ['id', 'reference', 'logistics_company_id', 'rider_id', 'request_token', 'submission_fingerprint', 'intake_version', 'company_snapshot', 'profile_snapshot', 'note', 'sharing_acknowledged', 'submitted_at', 'requested_hub_id', 'invited_by_id']));
        $transition = "((OLD.status = 'pending' AND NEW.status IN ('withdrawn', 'rejected', 'offered')) OR (OLD.status = 'offered' AND NEW.status IN ('offered', 'accepted', 'withdrawn', 'declined', 'cancelled', 'expired')))";
        $adultDate = DB::getDriverName() === 'pgsql'
            ? "((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date - INTERVAL '18 years')::date"
            : "date('now', '+8 hours', '-18 years')";
        $eligibleUser = fn (string $alias) => "{$alias}.role = 'logistics' AND {$alias}.status = 'active' AND {$alias}.closed_at IS NULL AND {$alias}.kyc_status IN ('approved', 'verified') AND (({$alias}.birthday IS NULL AND {$alias}.identity_version = 0) OR ({$alias}.birthday >= '0001-01-01' AND {$alias}.birthday <= {$adultDate}))";
        $manager = 'EXISTS (SELECT 1 FROM logistics_companies c JOIN users company_owner ON company_owner.id = c.user_id JOIN users decision_actor ON decision_actor.id = e.actor_id WHERE c.id = OLD.logistics_company_id AND c.is_active = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1')." AND c.status = 'active' AND ".$eligibleUser('company_owner').' AND (SELECT count(*) FROM logistics_companies owned WHERE owned.user_id = company_owner.id) = 1 AND '.$eligibleUser('decision_actor').' AND (c.user_id = decision_actor.id OR EXISTS (SELECT 1 FROM hub_handlers h JOIN logistics_hubs hub ON hub.id = h.hub_id WHERE h.user_id = decision_actor.id AND h.logistics_company_id = c.id AND h.is_active = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1').' AND hub.logistics_company_id = c.id AND hub.is_active = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1')." AND (h.job_role IS NULL OR h.job_role = 'all'))))";
        $actor = "((e.action IN ('withdrawn', 'accepted', 'declined') AND e.actor_id = OLD.rider_id) OR (e.action IN ('offered', 'rejected', 'cancelled') AND {$manager}) OR (e.action = 'expired' AND e.actor_id IS NULL))";
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
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION rcr_guard_mutation() RETURNS trigger AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Rider company requests retain their history.' USING ERRCODE = '23000'; ELSIF TG_OP = 'INSERT' THEN IF NEW.status != 'pending' OR NEW.version != 1 OR NEW.withdrawn_at IS NOT NULL OR NEW.current_offer_id IS NOT NULL OR (NEW.invited_by_id IS NULL AND (NOT NEW.sharing_acknowledged OR NEW.intake_version < 1)) OR (NEW.invited_by_id IS NOT NULL AND (NEW.requested_hub_id IS NULL OR NEW.sharing_acknowledged)) THEN RAISE EXCEPTION 'A rider company request must begin as an acknowledged pending submission.' USING ERRCODE = '23000'; END IF; ELSIF NOT ({$valid}) THEN RAISE EXCEPTION 'An evidenced withdrawal or authorized decision receipt must retain the original submission.' USING ERRCODE = '23000'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;");
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain hub-team rider decisions and consent history; use a reviewed forward migration.');
    }
};
