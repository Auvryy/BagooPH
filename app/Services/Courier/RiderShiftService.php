<?php

namespace App\Services\Courier;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\CourierProfile;
use App\Models\RiderShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RiderShiftService
{
    public function open(User $rider, ?CourierProfile $profile = null): RiderShift
    {
        $profile ??= $rider->courierProfile;
        if (! $rider->isEligibleCourier() || ! $profile?->logistics_company_id || ! $profile->assigned_hub_id) {
            throw new DomainException('A rider shift needs your approved company and physical hub assignment.');
        }

        return DB::transaction(function () use ($rider, $profile) {
            User::whereKey($rider->id)->lockForUpdate()->firstOrFail();

            return RiderShift::firstOrCreate(['active_user_id' => $rider->id], [
                'user_id' => $rider->id, 'logistics_company_id' => $profile->logistics_company_id,
                'hub_id' => $profile->assigned_hub_id, 'started_at' => now(),
                'cash_event_floor' => (int) CodCashEvent::max('id'),
            ]);
        });
    }

    public function ledger(User $rider): array
    {
        $shift = RiderShift::where('active_user_id', $rider->id)->first();
        $held = 0;
        $collected = 0;
        $remitted = 0;
        $unresolved = 0;
        $pending = 0;
        $accounts = [];
        $since = $shift?->started_at ?? now('Asia/Manila')->startOfDay()->utc();
        // Event IDs distinguish consecutive shifts even when both begin in the same second.
        $during = fn ($q) => $shift ? $q->where('id', '>', $shift->cash_event_floor) : $q->where('created_at', '>=', $since);
        $query = CodAccount::where('collector_id', $rider->id)->where(fn ($q) => $q->whereNull('reconciled_at')->orWhereHas('events', $during))
            ->with('latestEvent')->withSum(['events as shift_collected' => fn ($q) => $during($q)->where('event_type', 'rider_collection')], 'amount_cents')
            ->withSum(['events as shift_remitted' => fn ($q) => $during($q)->where('event_type', 'handover_received')->where('from_user_id', $rider->id)->where('from_stage', 'rider')->where('to_stage', 'hub')], 'amount_cents');
        foreach ($query->orderBy('id')->get() as $account) {
            $last = $account->latestEvent;
            if (! $last) {
                continue;
            }
            $state = $last->target_state;
            $own = (int) ($state['balances']['rider:'.$rider->id] ?? 0);
            $held += $own + (int) ($state['excess']['rider:'.$rider->id] ?? 0);
            $pending += (int) (($state['pending']['holder_id'] ?? null) === $rider->id);
            $unresolved += collect($state['discrepancies'])->filter(fn ($item) => ($item['holder_id'] ?? null) === $rider->id && ! $item['resolved_by'])->count();
            $collected += (int) $account->shift_collected;
            $remitted += (int) $account->shift_remitted;
            if ($own > 0 || $account->shift_collected || $account->shift_remitted || ($state['pending']['holder_id'] ?? null) === $rider->id) {
                $accounts[] = ['reference' => $account->reference, 'last_event_reference' => $last->reference, 'held_cents' => $own];
            }
        }

        return ['shift_id' => $shift?->id, 'started_at' => $shift?->started_at?->toIso8601String(),
            'collected_cents' => $collected, 'remitted_cents' => $remitted, 'held_cents' => $held,
            'pending_handovers' => $pending, 'unresolved_differences' => $unresolved,
            'can_close' => $held === 0 && $pending === 0 && $unresolved === 0, 'accounts' => $accounts];
    }

    public function resource(User $rider): ?array
    {
        foreach (['rider_shifts', 'cod_accounts', 'cod_cash_events'] as $table) {
            if (! Schema::hasTable($table)) {
                return null;
            }
        }
        $ledger = $this->ledger($rider);
        foreach (['collected_cents', 'remitted_cents', 'held_cents'] as $field) {
            $ledger[$field] = (string) $ledger[$field];
        }
        $ledger['started_at'] = $ledger['started_at'] ? CarbonImmutable::parse($ledger['started_at'])->utc()->format('Y-m-d\TH:i:s.v\Z') : null;
        $ledger['accounts'] = array_map(fn ($account) => [...$account, 'held_cents' => (string) $account['held_cents']], $ledger['accounts']);

        return $ledger;
    }

    public function close(User $rider): ?RiderShift
    {
        return DB::transaction(function () use ($rider) {
            User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            CodAccount::where('collector_id', $rider->id)->orderBy('id')->lockForUpdate()->get();
            $shift = RiderShift::where('active_user_id', $rider->id)->lockForUpdate()->first();
            $ledger = $this->ledger($rider);
            if (! $ledger['can_close']) {
                throw new DomainException('Your shift still has ₱'.number_format($ledger['held_cents'] / 100, 2).' in COD to hand over, or a cash handover/difference awaiting confirmation. Ask your hub to record the counted receipt before closing duty.');
            }
            $shift?->update(['active_user_id' => null, 'closed_at' => now(), 'closing_snapshot' => $ledger]);

            return $shift;
        });
    }
}
