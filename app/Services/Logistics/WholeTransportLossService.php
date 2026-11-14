<?php

namespace App\Services\Logistics;

use App\Models\BuyerReturnManifestParcel;
use App\Models\HandoverManifestReceipt;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\LogisticsTransportTrip;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\MotherBagReturnParcel;
use App\Models\User;
use App\Models\WholeTransportLossConfirmation;
use App\Rules\ApplicationText;
use App\Services\Finance\ShipmentFinanceFiles;
use App\Services\Finance\ShipmentLossAccountingService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class WholeTransportLossService
{
    public const FLOOR_REASON = 'Confirmed unarrived physical transport or unopened sack loss accountability.';

    public function available(): bool
    {
        return Schema::hasTable('whole_transport_loss_confirmations')
            && Schema::hasTable('whole_transport_loss_manifests')
            && Schema::hasTable('whole_transport_loss_bags');
    }

    public function hasWholeTransportLoss(LogisticsManifest $manifest): bool
    {
        if (! $this->available()) {
            return false;
        }

        return WholeTransportLossConfirmation::where('scope_kind', 'whole_transport')
            ->whereHas('manifests', fn ($query) => $query->where('manifest_id', $manifest->id))
            ->get()
            ->contains(fn (WholeTransportLossConfirmation $record) => $this->verified($record));
    }

    public function manifestConfirmed(LogisticsManifest $manifest): bool
    {
        if (! $this->available()) {
            return false;
        }

        return WholeTransportLossConfirmation::whereHas('manifests', fn ($query) => $query->where('manifest_id', $manifest->id))
            ->get()
            ->contains(fn (WholeTransportLossConfirmation $record) => $this->verified($record));
    }

    public function tripConfirmed(LogisticsTransportTrip $trip): bool
    {
        if (! $this->available()) {
            return false;
        }

        return WholeTransportLossConfirmation::where('trip_id', $trip->id)
            ->get()
            ->contains(fn (WholeTransportLossConfirmation $record) => $this->verified($record));
    }

    public function bagConfirmed(MotherBag $bag): bool
    {
        if (! $this->available() || $bag->status !== 'lost' || $bag->opened_at !== null || $bag->opened_by_id !== null) {
            return false;
        }
        $confirmationId = DB::table('whole_transport_loss_bags')->where('mother_bag_id', $bag->id)->value('whole_transport_loss_confirmation_id');
        $record = $confirmationId ? WholeTransportLossConfirmation::find($confirmationId) : null;

        return $record !== null && $this->verified($record);
    }

    public function presentation(User $actor, LogisticsManifest $manifest, string $basePath = '/hub/manifests'): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $eval = $this->evaluate($manifest);
        $record = $this->latestConfirmation($manifest, $eval['trip']);
        $isAdmin = $actor->isAdmin();
        $eligible = $this->isEligibleAdmin($actor) && ! $this->isSourceActor($actor, $eval['manifests'], $eval['bags']);
        $canRecord = $eligible && $eval['can_record'];

        if (! $record && ! $canRecord) {
            return null;
        }

        return [
            'can_record' => $canRecord,
            'eligible_scope_kind' => $canRecord ? $eval['scope_kind'] : null,
            'unarrived_bag_references' => $canRecord ? $eval['unarrived_bags']->pluck('reference')->values()->all() : [],
            'record' => $record ? [
                'reference' => $record->reference,
                'scope_kind' => $record->scope_kind,
                'verified' => $this->verified($record),
                'lost_bags_count' => $record->bags()->count(),
                'lost_bag_references' => $record->bags()->orderBy('mother_bags.created_at')->pluck('mother_bags.reference')->values()->all(),
                'created_at' => $record->created_at?->toIso8601String(),
                'reason' => $isAdmin ? $record->reason : null,
                'proof_url' => $isAdmin ? "{$basePath}/{$manifest->id}/whole-transport-loss/proof" : null,
            ] : null,
        ];
    }

    public function proofResponse(User $original, LogisticsManifest $manifest): BinaryFileResponse
    {
        abort_unless($this->available(), 409, 'Whole-transport loss accountability is currently unavailable.');
        $actor = $this->requireAdminActor($original);
        $trip = $this->tripForManifest($manifest);
        $record = $this->latestConfirmation($manifest, $trip);
        abort_unless($record && $this->verified($record), 404, 'Verified whole-transport-loss evidence is not available for this manifest.');
        unset($actor);

        return response()->file(Storage::disk('local')->path($record->proof_path), [
            'Content-Type' => $record->proof_mime,
            'Content-Disposition' => 'attachment; filename="whole-transport-loss-evidence"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirm(User $original, LogisticsManifest $snapshot, array $input, ?UploadedFile $proof): WholeTransportLossConfirmation
    {
        abort_unless($this->available(), 409, 'Whole-transport loss accountability is currently unavailable.');
        $actor = $this->requireAdminActor($original);

        $allowed = ['expected_version', 'request_token', 'reason', 'transport_loss_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']) + [
            'expected_version' => ['required', 'integer', 'min:1'],
            'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'transport_loss_confirmed' => ['required', 'accepted'],
        ];
        Validator::make($input, $rules)->validate();
        Validator::make(['proof' => $proof], ['proof' => ['required', 'file', 'max:5120']])->validate();

        $info = app(ShipmentFinanceFiles::class)->inspect($proof);
        $reason = trim((string) $input['reason']);
        $tokenHash = hash('sha256', (string) $input['request_token']);
        $fingerprint = hash('sha256', json_encode([
            'actor_id' => $actor->id,
            'manifest_id' => $snapshot->id,
            'expected_version' => (int) $input['expected_version'],
            'reason' => $reason,
            'proof_hash' => $info['proof_hash'],
            'transport_loss_confirmed' => true,
        ], JSON_THROW_ON_ERROR));

        $storedPath = null;
        try {
            return DB::transaction(function () use ($actor, $snapshot, $input, $proof, $info, $reason, $tokenHash, $fingerprint, &$storedPath) {
                $manifest = LogisticsManifest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $trip = $this->tripForManifest($manifest, lock: true);

                if ($prior = WholeTransportLossConfirmation::where('request_token_hash', $tokenHash)->first()) {
                    $this->requireState(
                        $prior->manifest_id === $manifest->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $fingerprint),
                        'This request reference was already used for different whole-transport-loss details.'
                    );

                    return $prior;
                }

                $this->requireState((int) $input['expected_version'] === (int) $manifest->version, 'This manifest changed while you reviewed it. Refresh and verify its current state.');

                $eval = $this->evaluate($manifest, $trip, lock: true);
                $this->requireState($eval['can_record'], $eval['blocker']);
                abort_if($this->isSourceActor($actor, $eval['manifests'], $eval['bags']), 403, 'A separate administrator independent from the originating company, handlers and driver must confirm whole-transport loss.');

                $manifestIds = $eval['scope_kind'] === 'whole_transport'
                    ? $eval['manifests']->pluck('id')->all()
                    : $eval['covered_manifest_ids']->values()->all();
                $coveredManifests = $eval['manifests']->whereIn('id', $manifestIds)->values();
                $lostBags = $eval['unarrived_bags']->values();

                $storedPath = $proof->storeAs('whole-transport-losses', Str::uuid().'.'.$info['extension'], 'local');
                abort_unless($storedPath && Storage::disk('local')->exists($storedPath), 503, 'The whole-transport loss evidence could not be saved.');
                DB::afterRollback(function () use ($storedPath): void {
                    if ($storedPath && ! WholeTransportLossConfirmation::where('proof_path', $storedPath)->exists()) {
                        Storage::disk('local')->delete($storedPath);
                    }
                });

                $confirmation = WholeTransportLossConfirmation::create([
                    'scope_kind' => $eval['scope_kind'],
                    'logistics_company_id' => $manifest->logistics_company_id,
                    'trip_id' => $trip?->id,
                    'manifest_id' => $manifest->id,
                    'actor_id' => $actor->id,
                    'driver_id' => $manifest->driver_id,
                    'vehicle_id' => $manifest->vehicle_id,
                    'manifest_version' => $manifest->version,
                    'trip_version' => $trip?->version,
                    'reason' => $reason,
                    'proof_path' => $storedPath,
                    'proof_mime' => $info['proof_mime'],
                    'proof_bytes' => $info['proof_bytes'],
                    'proof_hash' => $info['proof_hash'],
                    'request_token_hash' => $tokenHash,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);

                foreach ($coveredManifests as $covered) {
                    DB::table('whole_transport_loss_manifests')->insert([
                        'whole_transport_loss_confirmation_id' => $confirmation->id,
                        'manifest_id' => $covered->id,
                        'trip_id' => $trip?->id,
                        'whole_transport_key' => $eval['scope_kind'] === 'whole_transport' ? 'manifest:'.$covered->id : null,
                    ]);
                }

                $lostBagIds = [];
                foreach ($lostBags as $bag) {
                    DB::table('whole_transport_loss_bags')->insert([
                        'whole_transport_loss_confirmation_id' => $confirmation->id,
                        'mother_bag_id' => $bag->id,
                        'manifest_id' => $bag->manifest_id,
                        'return_trip_id' => $bag->return_trip_id,
                        'original_status' => $bag->status,
                        'physical_tag' => $bag->physical_tag,
                        'seal_number' => $bag->seal_number,
                        'active_physical_key' => $bag->active_physical_key,
                    ]);
                    $bag->update(['status' => 'lost', 'version' => $bag->version + 1]);
                    DB::table('mother_bag_events')->insert([
                        'mother_bag_id' => $bag->id,
                        'actor_id' => $actor->id,
                        'event_type' => 'whole-transport-loss',
                        'barcode_scanned' => null,
                        'payload' => json_encode([
                            'confirmation_reference' => $confirmation->reference,
                            'scope_kind' => $eval['scope_kind'],
                            'manifest_id' => $bag->manifest_id,
                            'return_trip_id' => $bag->return_trip_id,
                            'reason' => self::FLOOR_REASON,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                    $lostBagIds[] = $bag->id;
                }

                foreach ($coveredManifests as $covered) {
                    $covered->events()->create([
                        'manifest_parcel_id' => null,
                        'actor_id' => $actor->id,
                        'hub_id' => $covered->destination_hub_id,
                        'event_type' => 'whole-transport-loss',
                        'barcode_scanned' => null,
                        'reason' => self::FLOOR_REASON,
                        'source_state' => $covered->state(),
                        'target_state' => $covered->state(),
                        'payload' => [
                            'confirmation_reference' => $confirmation->reference,
                            'scope_kind' => $eval['scope_kind'],
                            'trip_id' => $trip?->id,
                            'lost_bag_ids' => $lostBagIds,
                        ],
                        'request_token' => $covered->id === $manifest->id ? (string) $input['request_token'] : null,
                        'request_fingerprint' => $fingerprint,
                    ]);
                }

                if ($trip) {
                    $trip->update(['version' => $trip->version + 1]);
                    DB::table('logistics_trip_events')->insert([
                        'trip_id' => $trip->id,
                        'actor_id' => $actor->id,
                        'event_type' => 'transport-loss',
                        'sequence' => $trip->version,
                        'request_token' => (string) $input['request_token'],
                        'fingerprint' => $fingerprint,
                        'context' => json_encode([
                            'confirmation_reference' => $confirmation->reference,
                            'scope_kind' => $eval['scope_kind'],
                            'manifest_ids' => $manifestIds,
                            'lost_bag_ids' => $lostBagIds,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }

                $this->requireState($this->verified($confirmation->fresh()), 'Whole-transport loss integrity check failed.');

                return $confirmation->fresh();
            });
        } catch (Throwable $exception) {
            if ($storedPath && ! WholeTransportLossConfirmation::where('proof_path', $storedPath)->exists()) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function requireState(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }

    public function verified(WholeTransportLossConfirmation $record): bool
    {
        if (! $record->actor?->isAdmin() || trim((string) $record->reason) === '') {
            return false;
        }
        if (! $record->proof_path || ! str_starts_with($record->proof_path, 'whole-transport-losses/') || str_contains($record->proof_path, '..')) {
            return false;
        }
        if ($record->proof_bytes <= 0 || $record->proof_bytes > 5242880 || ! Storage::disk('local')->exists($record->proof_path)) {
            return false;
        }
        $diskBytes = Storage::disk('local')->size($record->proof_path);
        $diskHash = hash_file('sha256', Storage::disk('local')->path($record->proof_path));
        if ($diskBytes !== $record->proof_bytes || ! hash_equals($record->proof_hash, $diskHash)) {
            return false;
        }

        $manifestLinks = DB::table('whole_transport_loss_manifests')->where('whole_transport_loss_confirmation_id', $record->id)->get();
        if ($manifestLinks->isEmpty()) {
            return false;
        }
        foreach ($manifestLinks as $link) {
            $hasEvent = LogisticsManifestEvent::where('manifest_id', $link->manifest_id)
                ->where('event_type', 'whole-transport-loss')
                ->where('actor_id', $record->actor_id)
                ->where('payload->confirmation_reference', $record->reference)
                ->exists();
            if (! $hasEvent) {
                return false;
            }
        }

        $bagLinks = DB::table('whole_transport_loss_bags')->where('whole_transport_loss_confirmation_id', $record->id)->get();
        foreach ($bagLinks as $bagLink) {
            $bag = MotherBag::find($bagLink->mother_bag_id);
            if (! $bag || $bag->status !== 'lost' || $bag->opened_at !== null || $bag->opened_by_id !== null
                || $bag->active_physical_key !== $bagLink->active_physical_key
                || $bag->physical_tag !== $bagLink->physical_tag
                || $bag->seal_number !== $bagLink->seal_number) {
                return false;
            }
            $bagEvent = DB::table('mother_bag_events')
                ->where('mother_bag_id', $bag->id)
                ->where('event_type', 'whole-transport-loss')
                ->where('actor_id', $record->actor_id)
                ->orderByDesc('id')
                ->first();
            $payload = $bagEvent ? json_decode((string) $bagEvent->payload, true) : null;
            if (! is_array($payload) || ($payload['confirmation_reference'] ?? null) !== $record->reference) {
                return false;
            }
        }

        if ($record->trip_id !== null) {
            $tripEvent = DB::table('logistics_trip_events')
                ->where('trip_id', $record->trip_id)
                ->where('event_type', 'transport-loss')
                ->where('actor_id', $record->actor_id)
                ->where('request_token', '!=', '')
                ->get()
                ->first(function ($row) use ($record) {
                    $context = json_decode((string) $row->context, true);

                    return is_array($context) && ($context['confirmation_reference'] ?? null) === $record->reference;
                });
            if (! $tripEvent) {
                return false;
            }
        }

        return true;
    }

    private function evaluate(LogisticsManifest $manifest, ?LogisticsTransportTrip $trip = null, bool $lock = false): array
    {
        $trip ??= $this->tripForManifest($manifest, $lock);
        $manifests = $trip
            ? $trip->manifests()->orderBy('logistics_manifests.id')->when($lock, fn ($q) => $q->lockForUpdate())->get()
            : collect([$manifest]);
        if ($manifests->isEmpty()) {
            $manifests = collect([$manifest]);
        }

        $manifestIds = $manifests->pluck('id')->all();
        $forwardBags = MotherBag::whereIn('manifest_id', $manifestIds)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $returnBags = ($trip && Schema::hasColumn('mother_bags', 'return_trip_id'))
            ? MotherBag::where('return_trip_id', $trip->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()
            : collect();
        $bags = $forwardBags->concat($returnBags)->unique('id')->values();

        $fail = fn (string $message) => [
            'can_record' => false,
            'blocker' => $message,
            'scope_kind' => null,
            'trip' => $trip,
            'manifests' => $manifests,
            'bags' => $bags,
            'unarrived_bags' => collect(),
            'covered_manifest_ids' => collect(),
        ];

        if (! $manifest->dispatched_at || in_array($manifest->status, ['draft', 'sealed', 'open'], true)) {
            return $fail('Whole-transport loss accountability applies only to dispatched transport.');
        }
        if ($trip && $trip->status === 'closed') {
            return $fail('This shared trip is already closed.');
        }
        if ($trip && $trip->status !== 'dispatched') {
            return $fail('This shared trip must still be dispatched to confirm whole-transport loss.');
        }
        foreach ($manifests as $memberManifest) {
            if (! $memberManifest->dispatched_at || in_array($memberManifest->status, ['draft', 'sealed', 'open'], true)) {
                return $fail('Every manifest on this transport must have departed before confirming whole-transport loss.');
            }
        }

        $hasPhysicalArrival = $this->hasAnyPhysicalArrival($manifests, $bags);
        $hasWholeTransport = WholeTransportLossConfirmation::where('scope_kind', 'whole_transport')
            ->whereHas('manifests', fn ($q) => $q->whereIn('manifest_id', $manifestIds))
            ->exists();
        $unarrivedBags = $bags->filter(fn (MotherBag $bag) => $bag->status === 'in_transit' && $bag->opened_at === null)->values();
        $hasActiveUnfinishedBag = $bags->contains(fn (MotherBag $bag) => in_array($bag->status, ['open', 'sealed', 'opened'], true));

        if ($hasActiveUnfinishedBag) {
            return $fail('Finish all open or partially received bags before confirming remaining unarrived transport or bag losses.');
        }

        $accounting = app(ShipmentLossAccountingService::class);
        $manifestService = app(LogisticsManifestService::class);
        $accountingByManifest = [];
        $hasAccountedLoss = false;
        foreach ($manifests as $memberManifest) {
            $members = $accounting->members($memberManifest);
            $accountingByManifest[$memberManifest->id] = $members;
            if ($members->isNotEmpty()) {
                $hasAccountedLoss = true;
            }
        }

        if (! $hasPhysicalArrival) {
            if ($hasWholeTransport) {
                return $fail('Whole-transport loss accountability has already been recorded for this transport.');
            }
            foreach ($manifests as $memberManifest) {
                if ($manifestService->openDiscrepancies($memberManifest)->isNotEmpty()) {
                    return $fail('Resolve open discrepancies across all manifests on this transport before confirming whole-transport loss.');
                }
                if (! $this->allManifestMembersAccounted($memberManifest, $accountingByManifest[$memberManifest->id])) {
                    return $fail('Every parcel on this transport must have confirmed loss accounting or completed recovery before confirming whole-transport loss.');
                }
            }
            if (! $hasAccountedLoss && $unarrivedBags->isEmpty()) {
                return $fail('This manifest has no confirmed parcel loss or unarrived bag awaiting whole-transport accountability.');
            }

            return [
                'can_record' => true,
                'blocker' => '',
                'scope_kind' => 'whole_transport',
                'trip' => $trip,
                'manifests' => $manifests,
                'bags' => $bags,
                'unarrived_bags' => $unarrivedBags,
                'covered_manifest_ids' => $manifests->pluck('id')->values(),
            ];
        }

        if ($unarrivedBags->isEmpty()) {
            return $fail('Physical arrival or opening has already been recorded and no unarrived bags remain.');
        }

        $coveredManifestIds = collect([$manifest->id]);
        foreach ($unarrivedBags as $bag) {
            if ($bag->manifest_id !== null && $bag->return_trip_id === null) {
                $coveredManifestIds->push($bag->manifest_id);
                $bagManifest = $manifests->firstWhere('id', $bag->manifest_id);
                if (! $bagManifest || $manifestService->openDiscrepancies($bagManifest)->isNotEmpty()) {
                    return $fail('Resolve open discrepancies before confirming unarrived bag loss.');
                }
                $bagParcels = MotherBagParcel::where('mother_bag_id', $bag->id)->whereNull('removed_at')->get();
                $members = LogisticsManifestParcel::where('manifest_id', $bag->manifest_id)
                    ->whereIn('delivery_id', $bagParcels->pluck('delivery_id'))
                    ->where('included', true)
                    ->get();
                foreach ($members as $member) {
                    if (! $this->forwardMemberAccounted($member, $accountingByManifest[$bag->manifest_id] ?? collect())) {
                        return $fail('Every parcel inside each unarrived Mother Bag must have confirmed loss accounting or completed recovery.');
                    }
                }
            } elseif ($bag->return_trip_id !== null) {
                $bagReturnParcels = MotherBagReturnParcel::where('mother_bag_id', $bag->id)->whereNull('removed_at')->get();
                $returnMembers = BuyerReturnManifestParcel::whereIn('id', $bagReturnParcels->pluck('buyer_return_manifest_parcel_id'))
                    ->where('included', true)
                    ->get();
                foreach ($returnMembers as $member) {
                    $coveredManifestIds->push($member->manifest_id);
                    $memberManifest = $manifests->firstWhere('id', $member->manifest_id);
                    if ($memberManifest && $manifestService->openDiscrepancies($memberManifest)->isNotEmpty()) {
                        return $fail('Resolve open discrepancies before confirming unarrived return sack loss.');
                    }
                    $accounted = $accountingByManifest[$member->manifest_id] ?? collect();
                    if (! $accounted->has($member->id) && $member->received_at === null) {
                        return $fail('Every return parcel inside each unarrived return sack must have confirmed loss accounting or completed recovery.');
                    }
                }
            }
        }

        return [
            'can_record' => true,
            'blocker' => '',
            'scope_kind' => 'unarrived_bags',
            'trip' => $trip,
            'manifests' => $manifests,
            'bags' => $bags,
            'unarrived_bags' => $unarrivedBags,
            'covered_manifest_ids' => $coveredManifestIds->unique()->values(),
        ];
    }

    private function allManifestMembersAccounted(LogisticsManifest $manifest, Collection $accounted): bool
    {
        if ($manifest->direction === 'buyer_return') {
            $members = $manifest->buyerReturnParcels()->where('included', true)->get();
            foreach ($members as $member) {
                if (! $accounted->has($member->id) && $member->received_at === null) {
                    return false;
                }
            }

            return true;
        }

        $members = $manifest->parcels()->where('included', true)->get();
        foreach ($members as $member) {
            if (! $this->forwardMemberAccounted($member, $accounted)) {
                return false;
            }
        }

        return true;
    }

    private function forwardMemberAccounted(LogisticsManifestParcel $member, Collection $accounted): bool
    {
        if ($accounted->has($member->id) || $member->received_at !== null) {
            return true;
        }

        return Schema::hasTable('handover_manifest_receipts')
            && HandoverManifestReceipt::where('manifest_parcel_id', $member->id)->exists();
    }

    private function hasAnyPhysicalArrival(Collection $manifests, Collection $bags): bool
    {
        if ($bags->contains(fn (MotherBag $bag) => $bag->opened_at !== null)) {
            return true;
        }

        foreach ($manifests as $manifest) {
            $arrived = $manifest->events()->where(function ($query) use ($manifest) {
                $query->where(fn ($vehicle) => $vehicle->where('event_type', 'transport-arrived')
                    ->where('actor_role', 'logistics')
                    ->where('hub_id', $manifest->destination_hub_id)
                    ->whereNull('manifest_parcel_id')
                    ->whereNull('barcode_scanned'))
                    ->orWhere(fn ($forward) => $forward->where('event_type', 'parcel_received')
                        ->whereNull('payload->parcel_recovery_reference'))
                    ->orWhere(fn ($reverse) => $reverse->where('event_type', 'buyer-return-received')
                        ->whereNull('payload->recovery_scan_id'));
            })->exists();
            if ($arrived) {
                return true;
            }
        }

        return false;
    }

    private function tripForManifest(LogisticsManifest $manifest, bool $lock = false): ?LogisticsTransportTrip
    {
        if (! Schema::hasTable('logistics_trip_manifests') || ! Schema::hasTable('logistics_transport_trips')) {
            return null;
        }
        $tripId = DB::table('logistics_trip_manifests')->where('manifest_id', $manifest->id)->orderByDesc('id')->value('trip_id');

        return $tripId ? LogisticsTransportTrip::whereKey($tripId)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
    }

    private function latestConfirmation(LogisticsManifest $manifest, ?LogisticsTransportTrip $trip): ?WholeTransportLossConfirmation
    {
        return WholeTransportLossConfirmation::whereHas('manifests', fn ($q) => $q->where('manifest_id', $manifest->id))
            ->when($trip, fn ($q) => $q->orWhere('trip_id', $trip->id))
            ->orderByDesc('id')
            ->first();
    }

    private function requireAdminActor(User $original): User
    {
        $actor = User::findOrFail($original->id);
        abort_unless($this->isEligibleAdmin($actor), 403, 'Only a verified active portal administrator may confirm whole-transport loss accountability.');

        return $actor;
    }

    private function isEligibleAdmin(User $actor): bool
    {
        return $actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at !== null;
    }

    private function isSourceActor(User $actor, Collection $manifests, Collection $bags): bool
    {
        $sourceIds = collect();
        foreach ($manifests as $manifest) {
            $sourceIds->push(
                $manifest->driver_id,
                $manifest->created_by_id,
                $manifest->dispatcher_id,
                $manifest->receiver_id,
                $manifest->company?->user_id
            );
            $manifestSourceActors = $manifest->events()
                ->whereIn('actor_role', ['logistics', 'courier'])
                ->pluck('actor_id');
            $sourceIds = $sourceIds->concat($manifestSourceActors);
        }
        foreach ($bags as $bag) {
            $sourceIds->push($bag->created_by_id, $bag->sealed_by_id, $bag->loaded_by_id, $bag->opened_by_id);
        }

        return $sourceIds->filter()->unique()->contains($actor->id);
    }
}
