<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\RegistrationDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class HandoverIssueService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function actor(User $user): User
    {
        $actor = User::find($user->id);
        abort_unless($actor?->canAccessPortal() && in_array($actor->role, ['seller', 'courier', 'logistics', 'admin'], true), 403);

        return $actor;
    }

    public function parcels(User $user): Builder
    {
        $actor = $this->actor($user);
        $query = Delivery::query();
        if ($actor->isAdmin()) {
            return $query;
        }
        if ($actor->isSeller()) {
            return $query->whereHas('order', fn (Builder $order) => $order
                ->whereHas('items.shop', fn (Builder $shop) => $shop->where('user_id', $actor->id))
                ->whereDoesntHave('items', fn (Builder $item) => $item->whereDoesntHave('shop', fn (Builder $shop) => $shop->where('user_id', $actor->id))));
        }
        if ($actor->isCourier()) {
            $profile = CourierProfile::operational()->where('user_id', $actor->id)->first();
            if (! $profile) {
                return $query->whereKey(0);
            }

            return $query->where('logistics_company_id', $profile->logistics_company_id)->where(function (Builder $parcel) use ($actor, $profile) {
                $parcel->where(fn (Builder $pickup) => $pickup->where('courier_id', $actor->id)->where('origin_bayan_hub_id', $profile->assigned_hub_id)
                    ->whereRaw("deliveries.status IN ('assigned_pickup', 'picked_up')"))
                    ->orWhere(fn (Builder $lastMile) => $lastMile->where('assigned_rider_id', $actor->id)->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                        ->whereRaw("deliveries.status IN ('assigned_to_rider', 'out_for_delivery', 'delivery_failed')"));
            });
        }
        $companyIds = LogisticsCompany::eligible()->where('user_id', $actor->id)->select('id');
        $hubIds = HubHandler::eligible()->where('user_id', $actor->id)->select('hub_id');

        return $query->where(fn (Builder $parcel) => $parcel->whereIn('logistics_company_id', $companyIds)
            ->orWhere(fn (Builder $handled) => $handled->whereIn('current_hub_id', $hubIds)
                ->whereHas('currentHub', fn (Builder $hub) => $hub->whereColumn('logistics_hubs.logistics_company_id', 'deliveries.logistics_company_id'))));
    }

    public function readable(User $user): Builder
    {
        $actor = $this->actor($user);
        $query = HandoverIssue::query();
        if ($actor->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $issue) use ($actor) {
            $issue->where('reporter_id', $actor->id)->orWhereIn('delivery_id', $this->parcels($actor)->select('deliveries.id'));
            if ($actor->isCourier()) {
                $issue->orWhereHas('recoveryGrants', fn (Builder $grants) => $grants->where('holder_id', $actor->id));
            }
            if ($actor->isLogistics()) {
                $issue->orWhereIn('logistics_company_id', LogisticsCompany::eligible()->where('user_id', $actor->id)->select('id'));
                $issue->orWhereHas('recoveryGrants', fn (Builder $grants) => $grants->whereIn('hub_id', HubHandler::eligible()->where('user_id', $actor->id)->select('hub_id')));
            }
        });
    }

    public function canReview(User $user, HandoverIssue $issue): bool
    {
        $actor = $this->actor($user);

        return $actor->id !== $issue->reporter_id && ($actor->isAdmin()
            || ($actor->isLogistics() && $issue->logistics_company_id !== null
                && $this->eligibility->isCompanyAdministrator($actor, $issue->logistics_company_id)
                && $issue->delivery->logistics_company_id === $issue->logistics_company_id));
    }

    public function snapshot(Delivery $parcel): array
    {
        $parcel->loadMissing('order');
        $source = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
        $custody = $source?->custody_after ?? ['kind' => 'unknown', 'reason' => 'No recorded custody evidence.'];
        $label = match ($custody['kind'] ?? 'unknown') {
            'hub' => 'Hub: '.(LogisticsHub::whereKey($custody['hub_id'] ?? 0)->value('name') ?? 'Recorded facility'),
            'courier' => 'Rider: '.(User::whereKey($custody['user_id'] ?? 0)->value('name') ?? 'Recorded rider'),
            'seller' => 'Seller',
            'buyer' => 'Buyer receipt recorded',
            'transport' => 'In recorded hub transport',
            default => 'Custody not established',
        };

        return [...DeliveryCheckpoint::state($parcel), 'checkpoint_id' => $source?->id, 'checkpoint_reference' => $source?->record_reference,
            'custody' => $custody, 'custody_label' => $label, 'origin_bayan_hub_id' => $parcel->origin_bayan_hub_id,
            'origin_mother_hub_id' => $parcel->origin_mother_hub_id, 'destination_mother_hub_id' => $parcel->destination_mother_hub_id,
            'destination_bayan_hub_id' => $parcel->destination_bayan_hub_id];
    }

    public function sourceToken(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function evidencePath(User $actor, HandoverIssue $issue): string
    {
        abort_unless($this->readable($actor)->whereKey($issue->id)->exists(), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($issue->evidence_path), 404, 'The supporting document is unavailable.');
        $path = $disk->path($issue->evidence_path);
        abort_unless(is_readable($path), 503, 'The supporting document is temporarily unavailable.');
        $hash = hash_file('sha256', $path);
        abort_unless(is_string($hash), 503, 'The supporting document is temporarily unavailable.');
        abort_unless(hash_equals($issue->evidence_hash, $hash), 409, 'The document no longer matches the saved evidence.');

        return $path;
    }

    public function report(User $user, array $input): HandoverIssue
    {
        $actor = $this->actor($user);
        abort_if($actor->isAdmin(), 403, 'An involved seller, rider or logistics account must report the observation.');
        $scanInput = app(WaybillScanInputService::class);
        $input = $scanInput->normalize($input);
        $this->normalizeText($input, ['reported_holder', 'reported_location', 'notes']);
        $data = Validator::make($input, [
            'barcode' => $scanInput->barcodeRules(), 'kind' => ['required', Rule::in(['missed_ready', 'missed_scan'])],
            'reported_holder' => ['required', new ApplicationText('notes', 2, 100)],
            'reported_location' => ['required', new ApplicationText('notes', 5, 255)],
            'notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'evidence' => ['bail', 'required', 'file', new RegistrationDocument], 'request_token' => ['required', 'uuid'],
        ])->validate();
        $file = $data['evidence'];
        $fingerprint = $this->sourceToken([...(array_diff_key($data, ['evidence' => true, 'request_token' => true])),
            'file_hash' => hash_file('sha256', $file->getPathname()), 'file_extension' => strtolower($file->getClientOriginalExtension())]);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $data, $file, $fingerprint, &$stored) {
                $previous = HandoverIssue::where('reporter_id', $actor->id)->where('request_token', $data['request_token'])->first();
                if ($previous) {
                    abort_unless($previous->request_fingerprint === $fingerprint, 409, 'This request was already used for a different report.');

                    return $previous;
                }
                $id = $this->parcels($actor)->where('tracking_number', $data['barcode'])->value('id');
                abort_unless($id, 404, 'No involved parcel matches this waybill.');
                $parcel = $this->lockParcel($id, $actor);
                $this->actor($actor);
                abort_unless($this->parcels($actor)->whereKey($parcel->id)->exists(), 404);
                $previous = HandoverIssue::where('reporter_id', $actor->id)->where('request_token', $data['request_token'])->first();
                if ($previous) {
                    abort_unless($previous->request_fingerprint === $fingerprint, 409, 'This request was already used for a different report.');

                    return $previous;
                }
                if (in_array($parcel->order->status, ['cancelled', 'completed', 'returned'], true)) {
                    throw ValidationException::withMessages(['barcode' => 'This order is closed. Use its existing support or return process.']);
                }
                if (HandoverIssue::where('active_delivery_id', $parcel->id)->exists()) {
                    throw ValidationException::withMessages(['barcode' => 'This parcel already has an open handover report. Review the existing issue.']);
                }
                if ($data['kind'] === 'missed_ready' && (! $actor->isSeller()
                    || ! in_array($parcel->order->status, ['placed', 'confirmed', 'preparing'], true))) {
                    throw ValidationException::withMessages(['kind' => 'Only the owning seller can report a missed readiness step before Ready for Pickup.']);
                }
                $snapshot = $this->snapshot($parcel);
                $stored = $file->store('handover-issue-evidence', 'local');
                abort_unless(is_string($stored) && Storage::disk('local')->exists($stored), 503, 'The evidence could not be saved. Please retry.');
                $issue = HandoverIssue::create([
                    'delivery_id' => $parcel->id, 'active_delivery_id' => $parcel->id, 'logistics_company_id' => $parcel->logistics_company_id,
                    'reporter_id' => $actor->id, 'reporter_role' => $actor->role, 'kind' => $data['kind'],
                    'reported_holder' => $data['reported_holder'], 'reported_location' => $data['reported_location'], 'notes' => $data['notes'],
                    'source_snapshot' => $snapshot, 'evidence_path' => $stored, 'evidence_hash' => hash_file('sha256', $file->getPathname()),
                    'evidence_extension' => strtolower($file->getClientOriginalExtension()), 'evidence_mime' => $file->getMimeType(),
                    'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint, 'state' => 'pending_review', 'version' => 1,
                ]);
                $issue->events()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role, 'event_type' => 'reported',
                    'notes' => $data['notes'], 'parcel_snapshot' => $snapshot, 'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint]);

                return $issue;
            });
        } catch (Throwable $error) {
            if (is_string($stored)) {
                Storage::disk('local')->delete($stored);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                abort(409, 'An open report or this request already exists. Refresh the issue list before retrying.');
            }
            throw $error;
        }
    }

    public function review(User $user, HandoverIssue $issue, array $input): HandoverIssue
    {
        $actor = $this->actor($user);
        abort_unless($this->readable($actor)->whereKey($issue->id)->exists(), 404);
        $this->normalizeText($input, ['notes']);
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['confirm', 'dismiss'])],
            'notes' => ['required', new ApplicationText('notes', 10, 1000)], 'evidence_reviewed' => ['required', 'accepted'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:1000000'],
            'source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'request_token' => ['required', 'uuid'],
        ])->validate();
        $fingerprint = $this->sourceToken($data);

        return DB::transaction(function () use ($actor, $issue, $data, $fingerprint) {
            $parcel = $this->lockParcel($issue->delivery_id, $actor);
            $locked = HandoverIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('delivery', $parcel);
            abort_unless($this->canReview($actor, $locked), 403, 'A different company administrator or platform admin must review this report.');
            $previous = $locked->events()->where('actor_id', $actor->id)->where('request_token', $data['request_token'])->first();
            if ($previous) {
                abort_unless($previous->request_fingerprint === $fingerprint, 409, 'This request was already used for a different decision.');

                return $locked;
            }
            $snapshot = $this->snapshot($parcel);
            abort_unless($locked->state === 'pending_review' && $locked->version === (int) $data['expected_version']
                && hash_equals($this->sourceToken($snapshot), $data['source_token']), 409, 'The report or parcel changed. Refresh and review the current facts.');
            $this->evidencePath($actor, $locked);
            $confirmed = $data['decision'] === 'confirm';
            $locked->update(['state' => $confirmed ? 'needs_recovery' : 'dismissed', 'version' => $locked->version + 1,
                'active_delivery_id' => $confirmed ? $parcel->id : null]);
            $locked->events()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role, 'event_type' => $confirmed ? 'confirmed' : 'dismissed',
                'notes' => $data['notes'], 'parcel_snapshot' => $snapshot, 'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint]);

            return $locked->fresh();
        }, 3);
    }

    private function lockParcel(int $id, User $actor): Delivery
    {
        $orderId = Delivery::whereKey($id)->value('order_id');
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($id)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);
        $ownerId = LogisticsCompany::whereKey($parcel->logistics_company_id)->value('user_id');
        User::whereIn('id', array_filter([$actor->id, $ownerId]))->orderBy('id')->lockForUpdate()->get();
        LogisticsCompany::whereKey($parcel->logistics_company_id)->lockForUpdate()->first();
        HubHandler::where('user_id', $actor->id)->orderBy('id')->lockForUpdate()->get();
        CourierProfile::where('user_id', $actor->id)->lockForUpdate()->first();

        return $parcel;
    }

    private function normalizeText(array &$input, array $fields): void
    {
        foreach ($fields as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim(\Normalizer::normalize($input[$field], \Normalizer::FORM_KC) ?: $input[$field], ' ');
            }
        }
    }
}
