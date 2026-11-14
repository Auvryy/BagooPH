<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnEvidence;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\BuyerReturnRecovery;
use App\Models\BuyerReturnRecoveryEvent;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\MotherBagReturnParcel;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\ShipmentLossCustody;
use App\Services\Finance\ShipmentLossReconciliationService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\WaybillScanInputService;
use App\Services\Notifications\NotificationDeliveryService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class BuyerReturnRecoveryService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function available(): bool
    {
        return Schema::hasTable('buyer_return_recoveries');
    }

    public function active(BuyerReturnParcel $parcel, bool $lock = false): ?BuyerReturnRecovery
    {
        return $this->available() ? BuyerReturnRecovery::where('active_return_parcel_id', $parcel->id)->when($lock, fn ($query) => $query->lockForUpdate())->first() : null;
    }

    public function context(BuyerReturnParcel $parcel): array
    {
        $recovery = $this->active($parcel);
        if ($recovery && in_array($recovery->state, ['held', 'in_transit'], true)) {
            return ['hub_ids' => $recovery->hub_ids, 'position' => $recovery->leg_index, 'recovery_id' => $recovery->id, 'loss_reconciliation_id' => null];
        }
        $loss = app(ShipmentLossReconciliationService::class)->activeForParcel($parcel);
        if ($loss?->outcome_path === 'post_delivery_partial_return') {
            $hubIds = $loss->return_route_hub_ids ?? [];
            $completed = $loss->returnRoutes()
                ->with('manifest.buyerReturnParcels')
                ->orderBy('leg_index')
                ->get()
                ->filter(fn ($route) => $route->manifest
                    && in_array($route->manifest->status, ['received', 'closed'], true)
                    && $route->manifest->buyerReturnParcels->contains(fn ($member) => $member->buyer_return_parcel_id === $parcel->id && $member->received_at !== null))
                ->count();

            return [
                'hub_ids' => $hubIds,
                'position' => min($completed, max(0, count($hubIds) - 1)),
                'recovery_id' => null,
                'loss_reconciliation_id' => $loss->id,
            ];
        }

        return ['hub_ids' => $parcel->hub_ids, 'position' => $parcel->route_position, 'recovery_id' => null, 'loss_reconciliation_id' => null];
    }

    public function scope(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && $actor->email_verified_at, 403);
        $query = BuyerReturnRecovery::query();
        if ($actor->isAdmin()) {
            return $query;
        }
        abort_unless($actor->isLogistics(), 403);
        $company = LogisticsCompany::eligible()->where('user_id', $actor->id)->first();
        if ($company) {
            return $query->where('logistics_company_id', $company->id);
        }
        $hubs = $this->eligibility->accessibleHubs($actor)->pluck('id')->all();

        return $query->where(function ($query) use ($hubs) {
            $query->whereIn('observed_hub_id', $hubs)->orWhereIn('expected_hub_id', $hubs);
            foreach ($hubs as $hub) {
                $query->orWhereJsonContains('hub_ids', $hub);
            }
        });
    }

    private function administrator(User $actor, int $companyId): bool
    {
        return $actor->canAccessPortal() && $actor->email_verified_at && ($actor->isAdmin()
            || $actor->isLogistics() && LogisticsCompany::eligible()->whereKey($companyId)->where('user_id', $actor->id)->exists());
    }

    private function source(BuyerReturnParcel $parcel): BuyerReturnManifestParcel
    {
        $member = BuyerReturnManifestParcel::where('active_return_parcel_id', $parcel->id)->where('included', true)->whereNull('received_at')->with('manifest.buyerReturnRoute')->first();
        $manifest = $member?->manifest;
        $departure = $manifest?->events()->where('event_type', 'buyer-return-departed')->where('barcode_scanned', $parcel->barcode)->first();
        $this->requireState($member && $manifest->direction === 'buyer_return' && $manifest->buyerReturnRoute
            && $manifest->status === 'dispatched' && $parcel->status === 'in_transit' && $parcel->current_hub_id === null
            && ($parcel->custody['manifest_id'] ?? null) === $manifest->id && $member->leg_index === $parcel->route_position
            && $departure && ($departure->payload['return_member_id'] ?? null) === $member->id && $departure->target_state === $parcel->state(),
            'Recovery needs the original dispatched return, its current holder and actual departure evidence.');

        return $member;
    }

    private function path(LogisticsHub $observed, LogisticsHub $expected): array
    {
        $routing = app(LogisticsRoutingEngine::class);
        $a = $observed->isMotherHub() ? $observed : $routing->resolveMotherHubForBayanHub($observed);
        $b = $expected->isMotherHub() ? $expected : $routing->resolveMotherHubForBayanHub($expected);
        $this->requireState($a && $b, 'Configure the actual and expected hub parents before authorizing recovery.');
        $ids = array_values(array_unique([$observed->id, $a->id, $b->id, $expected->id]));
        $hubs = LogisticsHub::eligible()->where('logistics_company_id', $observed->logistics_company_id)->whereIn('id', $ids)->get()->keyBy('id');
        $this->requireState($hubs->count() === count($ids) && $expected->logistics_company_id === $observed->logistics_company_id, 'Every recovery hub must be active in the original company.');
        for ($i = 1; $i < count($ids); $i++) {
            app(LogisticsManifestService::class)->legType($hubs[$ids[$i - 1]], $hubs[$ids[$i]]);
        }

        return $ids;
    }

    public function observe(User $actor, BuyerReturnParcel $snapshot, LogisticsHub $hub): ?BuyerReturnRecovery
    {
        if (! $this->available()) {
            return null;
        }
        abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $hub->logistics_company_id === $snapshot->logistics_company_id
            && $this->eligibility->canScan($actor, $hub), 403);
        app(ShipmentLossCustody::class)->assertMovable($snapshot);
        if ($active = $this->active($snapshot)) {
            abort_unless($this->scope($actor)->whereKey($active->id)->exists(), 403);

            return $active;
        }
        if ($snapshot->status !== 'in_transit') {
            return null;
        }

        return DB::transaction(function () use ($actor, $snapshot, $hub) {
            [$case, $parcel] = $this->lock($snapshot, $actor, [$hub->id]);
            if ($active = $this->active($parcel, true)) {
                return $active;
            }
            $member = $this->source($parcel);
            $manifest = LogisticsManifest::whereKey($member->manifest_id)->lockForUpdate()->firstOrFail();
            if ($hub->id === $manifest->destination_hub_id) {
                return null;
            }
            $path = $this->path($hub, $manifest->destinationHub);
            $observation = $manifest->events()->create(['actor_id' => $actor->id, 'hub_id' => $hub->id, 'event_type' => 'report-discrepancy',
                'barcode_scanned' => $parcel->barcode, 'source_state' => $parcel->state(), 'target_state' => $parcel->state(),
                'reason' => 'Return barcode observed at an unexpected hub. Review the actual source before intake.',
                'payload' => ['kind' => 'wrong_hub', 'return_member_id' => $member->id, 'return_parcel_id' => $parcel->id, 'expected_hub_id' => $manifest->destination_hub_id]]);
            $manifest->increment('version');
            $recovery = BuyerReturnRecovery::create(['buyer_return_case_id' => $case->id, 'buyer_return_parcel_id' => $parcel->id,
                'active_return_parcel_id' => $parcel->id, 'original_member_id' => $member->id,
                'original_bag_member_id' => MotherBagReturnParcel::where('active_return_parcel_id', $parcel->id)->value('id'),
                'observation_event_id' => $observation->id, 'logistics_company_id' => $parcel->logistics_company_id,
                'observed_hub_id' => $hub->id, 'expected_hub_id' => $manifest->destination_hub_id, 'observed_by_id' => $actor->id,
                'source_state' => $parcel->state(), 'hub_ids' => $path, 'state' => 'observed', 'version' => 1, 'leg_index' => 0]);
            $event = $this->event($recovery, $actor, 'observed', 'Unexpected-hub observation; physical custody is unchanged.', ['source_state' => $parcel->state(), 'target_state' => $parcel->state()]);
            $this->parentEvent($case, $actor, $event, (string) Str::uuid(), hash('sha256', $observation->reference));
            $owner = LogisticsCompany::findOrFail($parcel->logistics_company_id)->user_id;
            app(NotificationDeliveryService::class)->record($owner, 'buyer-return', 'return-recovery:'.$recovery->id, [
                'title' => 'A return needs route review', 'body' => 'Open '.$case->reference.' to review its unexpected-hub observation.',
                'order_id' => $case->order_id, 'return_id' => $case->id, 'target' => 'buyer-return']);

            return $recovery;
        }, 3);
    }

    public function sourceToken(BuyerReturnRecovery $recovery): string
    {
        return hash_hmac('sha256', json_encode([$recovery->id, $recovery->source_state, $recovery->hub_ids, $recovery->original_member_id, $recovery->version], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function present(User $actor, BuyerReturnRecovery $snapshot): array
    {
        $recovery = $this->scope($actor)->whereKey($snapshot->id)->with(['parcel', 'originalMember.manifest', 'events', 'routes.manifest'])->firstOrFail();
        $actor = User::findOrFail($actor->id);
        $actions = [];
        if ($this->administrator($actor, $recovery->logistics_company_id)) {
            $actions = $recovery->state === 'observed' ? ['authorize', 'withdraw'] : ($recovery->state === 'authorized' ? ['withdraw'] : []);
        }
        $observed = LogisticsHub::findOrFail($recovery->observed_hub_id);
        if ($recovery->state === 'authorized' && $actor->isLogistics() && $this->eligibility->canScan($actor, $observed)) {
            $actions[] = 'intake';
        }
        $hubs = LogisticsHub::whereIn('id', $recovery->hub_ids)->get()->keyBy('id');
        $base = request()->is('hub/*') || ! str_starts_with(request()->getHost(), 'hub.') ? '/hub/manifests/' : '/manifests/';
        $current = $recovery->routes->firstWhere('leg_index', $recovery->leg_index)?->manifest;

        return [...$recovery->only(['id', 'reference', 'state', 'version', 'leg_index', 'authorization_reason']),
            'barcode' => $recovery->parcel->barcode, 'caseUrl' => '/buyer-returns/'.$recovery->buyer_return_case_id,
            'originalManifestUrl' => app(LogisticsManifestService::class)->readable($actor)->whereKey($recovery->originalMember->manifest_id)->exists()
                ? $base.$recovery->originalMember->manifest_id : null,
            'sourceToken' => $this->sourceToken($recovery), 'actions' => $actions,
            'hubs' => array_map(fn ($id) => $hubs->get($id)?->only(['id', 'name', 'code', 'tier']), $recovery->hub_ids),
            'currentHubId' => in_array($recovery->state, ['held', 'completed'], true) && $recovery->parcel->status === 'at_hub'
                && $recovery->parcel->current_hub_id === $recovery->hub_ids[$recovery->leg_index] ? $recovery->parcel->current_hub_id : null,
            'manifestUrl' => $current && app(LogisticsManifestService::class)->readable($actor)->whereKey($current->id)->exists() ? $base.$current->id : null,
            'canPrepare' => $recovery->state === 'held' && $actor->isLogistics()
                && $this->eligibility->canScan($actor, LogisticsHub::findOrFail($recovery->hub_ids[$recovery->leg_index])) && ! $current,
            'history' => $recovery->events->map(fn ($event) => $event->only(['reference', 'event_type', 'actor_role', 'reason', 'created_at']))];
    }

    public function command(User $actor, BuyerReturnRecovery $snapshot, string $action, array $input, ?UploadedFile $photo = null): BuyerReturnRecovery
    {
        abort_unless(in_array($action, ['authorize', 'withdraw', 'intake'], true), 422);
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input) + ['photo' => $photo], [
            'version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => 'required|uuid',
            'notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'source_token' => [$action === 'authorize' ? 'required' : 'prohibited', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'barcode' => app(WaybillScanInputService::class)->barcodeRules($action === 'intake'),
            'photo' => [$action === 'intake' ? 'required' : 'prohibited', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();
        unset($data['photo']);
        $data['version'] = (int) $data['version'];
        $files = app(BuyerReturnFiles::class);
        $info = $photo ? $files->inspect($photo, 'photo') : null;
        $hash = hash('sha256', json_encode([$snapshot->id, $actor->id, $action, $data, $info['content_hash'] ?? null], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $snapshot, $action, $data, $hash, $photo, $info, $files, &$paths) {
                [$case, $parcel] = $this->lock($snapshot->parcel, $actor, $snapshot->hub_ids);
                $recovery = BuyerReturnRecovery::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $actor = User::findOrFail($actor->id);
                abort_unless($this->scope($actor)->whereKey($recovery->id)->exists(), 404);
                if ($prior = BuyerReturnRecoveryEvent::where('request_token', $data['request_token'])->first()) {
                    $this->requireState($prior->recovery_id === $recovery->id && $prior->actor_id === $actor->id && hash_equals($prior->fingerprint, $hash), 'This request already recorded different recovery evidence.');

                    return $recovery;
                }
                $this->requireState(! BuyerReturnEvent::where('request_token_hash', hash('sha256', $data['request_token']))->exists(), 'This token belongs to another return action.');
                $this->requireState($recovery->version === $data['version'] && $recovery->active_return_parcel_id === $parcel->id, 'This recovery changed. Reload its actual state.');
                $original = $this->source($parcel);
                $this->requireState($original->id === $recovery->original_member_id && $parcel->state() === $recovery->source_state, 'The original transport custody changed before this recovery intake.');
                if ($action !== 'intake') {
                    abort_unless($this->administrator($actor, $recovery->logistics_company_id), 403);
                }
                $before = $parcel->state();
                if ($action === 'authorize') {
                    $this->requireState($recovery->state === 'observed' && hash_equals($this->sourceToken($recovery), $data['source_token']), 'Review the current observed return and route before authorizing recovery.');
                    $this->requireState($recovery->hub_ids === $this->path(LogisticsHub::findOrFail($recovery->observed_hub_id), LogisticsHub::findOrFail($recovery->expected_hub_id)), 'The configured recovery corridor changed. Withdraw this unstarted observation and review a fresh scan.');
                    $recovery->fill(['state' => 'authorized', 'authorized_by_id' => $actor->id, 'authorization_reason' => $data['notes']]);
                } elseif ($action === 'withdraw') {
                    $this->requireState(in_array($recovery->state, ['observed', 'authorized'], true), 'Only an unreceived observation may be withdrawn. Actual intake must follow its recorded recovery.');
                    $recovery->fill(['state' => 'withdrawn', 'active_return_parcel_id' => null]);
                } else {
                    $hub = LogisticsHub::findOrFail($recovery->observed_hub_id);
                    abort_unless($actor->isLogistics() && $this->eligibility->canScan($actor, $hub), 403);
                    $this->requireState($recovery->state === 'authorized' && $data['barcode'] === $parcel->barcode
                        && $actor->id !== $original->manifest->dispatcher_id, 'The distinct observing-hub handler must scan this authorized return’s actual separate barcode.');
                    $original->update(['active_return_parcel_id' => null]);
                    $parcel->fill(['status' => 'at_hub', 'current_hub_id' => $hub->id, 'version' => $parcel->version + 1,
                        'custody' => ['kind' => 'hub', 'hub_id' => $hub->id, 'received_by_id' => $actor->id, 'return_recovery_id' => $recovery->id]])->save();
                    $recovery->state = 'held';
                }
                $recovery->version++;
                $recovery->save();
                $event = $this->event($recovery, $actor, $action, $data['notes'], ['source_state' => $before, 'target_state' => $parcel->state()], $data['request_token'], $hash);
                $parent = $this->parentEvent($case, $actor, $event, $data['request_token'], $hash);
                if ($photo) {
                    $paths[] = $path = $files->store($photo, $info);
                    BuyerReturnEvidence::create(['buyer_return_case_id' => $case->id, 'buyer_return_event_id' => $parent->id, 'purpose' => 'recovery-intake',
                        'path' => $path, 'content_hash' => $info['content_hash'], 'mime' => $info['mime'], 'bytes' => $info['bytes'], 'created_at' => now()]);
                }
                if ($action === 'intake' && $recovery->original_bag_member_id) {
                    DB::table('buyer_return_bag_extractions')->insert(['original_bag_member_id' => $recovery->original_bag_member_id,
                        'recovery_id' => $recovery->id, 'intake_event_id' => $event->id, 'return_event_id' => $parent->id, 'actor_id' => $actor->id, 'created_at' => now()]);
                    $bagMember = MotherBagReturnParcel::whereKey($recovery->original_bag_member_id)->lockForUpdate()->firstOrFail();
                    $this->requireState($bagMember->active_return_parcel_id === $parcel->id && $bagMember->buyer_return_manifest_parcel_id === $original->id, 'The original sack membership changed before the evidenced extraction.');
                    $bagMember->update(['active_return_parcel_id' => null]);
                }

                return $recovery;
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }

    public function assertManifest(BuyerReturnParcel $parcel, LogisticsManifest $manifest, bool $movement = true): ?BuyerReturnRecovery
    {
        if (! $this->available()) {
            return null;
        }
        $active = $this->active($parcel, true);
        $route = $manifest->buyerReturnRecoveryRoute;
        $this->requireState(! $route || ! $manifest->buyerReturnRoute, 'A manifest cannot use two return-route authorities.');
        if ($route) {
            if (! $movement) {
                return $route->recovery;
            }
            $this->requireState($active && $active->id === $route->recovery_id && in_array($active->state, ['held', 'in_transit'], true)
                && $route->leg_index === $active->leg_index, 'Use only this recovery’s actual next authorized transfer.');

            return $active;
        }
        $this->requireState(! $movement || ! $active, 'Complete or withdraw the recorded return recovery before ordinary movement resumes.');

        return null;
    }

    public function transfer(BuyerReturnRecovery $snapshot, LogisticsManifest $manifest, BuyerReturnParcel $parcel, User $actor, LogisticsManifestEvent $scan, bool $received): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $recovery = BuyerReturnRecovery::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $this->requireState($recovery->active_return_parcel_id === $parcel->id && $manifest->buyerReturnRecoveryRoute?->recovery_id === $recovery->id
            && $recovery->leg_index === $manifest->buyer_return_leg_index && $scan->manifest_id === $manifest->id
            && $scan->barcode_scanned === $parcel->barcode && $scan->actor_id === $actor->id, 'The actual recovery scan does not match its retained authority.');
        if ($received) {
            $this->requireState($recovery->state === 'in_transit' && $parcel->current_hub_id === $recovery->hub_ids[$recovery->leg_index + 1], 'Record the actual next recovery receipt.');
            $recovery->leg_index++;
            $recovery->state = 'held';
        } else {
            $this->requireState($recovery->state === 'held' && ($parcel->custody['manifest_id'] ?? null) === $manifest->id, 'Record the actual recovery departure.');
            $recovery->state = 'in_transit';
        }
        $recovery->version++;
        $recovery->save();
        $this->event($recovery, $actor, $received ? 'transfer_received' : 'transfer_departed', $scan->reason ?? 'Actual protected recovery transfer.', ['manifest_scan_id' => $scan->id, 'source_state' => $scan->source_state, 'target_state' => $scan->target_state]);
        if ($received && $recovery->leg_index === count($recovery->hub_ids) - 1) {
            $this->complete($recovery, $parcel, $actor, $scan);
        }
    }

    private function complete(BuyerReturnRecovery $recovery, BuyerReturnParcel $parcel, User $actor, LogisticsManifestEvent $lastScan): void
    {
        $this->requireState($parcel->status === 'at_hub' && $parcel->current_hub_id === $recovery->expected_hub_id && $recovery->state === 'held', 'Actual receipt at the original expected hub is required.');
        $this->verifyChain($recovery);
        $member = $recovery->originalMember()->lockForUpdate()->firstOrFail();
        $manifest = LogisticsManifest::whereKey($member->manifest_id)->lockForUpdate()->firstOrFail();
        $this->requireState($member->received_at === null && $member->active_return_parcel_id === null && $member->leg_index === $parcel->route_position
            && $manifest->destination_hub_id === $recovery->expected_hub_id && $manifest->status === 'dispatched', 'The original expected return receipt changed.');
        $before = $parcel->state();
        $parcel->fill(['route_position' => $parcel->route_position + 1, 'version' => $parcel->version + 1,
            'custody' => ['kind' => 'hub', 'hub_id' => $recovery->expected_hub_id, 'received_by_id' => $actor->id, 'manifest_id' => $manifest->id]])->save();
        $member->update(['received_at' => now(), 'received_by_id' => $actor->id, 'receipt_condition' => 'recovered']);
        $receipt = $manifest->events()->create(['actor_id' => $actor->id, 'hub_id' => $recovery->expected_hub_id, 'event_type' => 'buyer-return-received',
            'barcode_scanned' => $parcel->barcode, 'source_state' => $before, 'target_state' => $parcel->state(), 'reason' => 'Actual expected-hub receipt through the authorized recovery path.',
            'payload' => ['return_member_id' => $member->id, 'return_recovery_id' => $recovery->id, 'recovery_scan_id' => $lastScan->id]]);
        $manifest->update(['status' => 'received', 'received_at' => now(), 'receiver_id' => $actor->id, 'version' => $manifest->version + 1]);
        $recovery->update(['state' => 'completed', 'active_return_parcel_id' => null, 'version' => $recovery->version + 1]);
        $this->event($recovery, $actor, 'completed', 'Original expected hub received the actual recovered return.', ['manifest_scan_id' => $lastScan->id, 'original_receipt_id' => $receipt->id]);
        $manifest->events()->create(['actor_id' => $actor->id, 'hub_id' => $recovery->expected_hub_id, 'event_type' => 'resolve-discrepancy', 'barcode_scanned' => $parcel->barcode,
            'source_state' => $before, 'target_state' => $parcel->state(), 'reason' => 'Resolved by the actual complete recovery and original expected receipt.',
            'payload' => ['source_event_id' => $recovery->observation_event_id, 'return_recovery_id' => $recovery->id, 'receipt_reference' => $receipt->reference]]);
        app(BuyerReturnBagService::class)->recovered($recovery, $parcel, $actor, $receipt);
    }

    public function verifyChain(BuyerReturnRecovery $recovery): void
    {
        $intake = $recovery->events()->where('event_type', 'intake')->first();
        $this->requireState($recovery->authorized_by_id && $intake && BuyerReturnEvidence::where('purpose', 'recovery-intake')
            ->whereIn('buyer_return_event_id', BuyerReturnEvent::where('buyer_return_case_id', $recovery->buyer_return_case_id)->where('context->recovery_event_id', $intake->id)->select('id'))->exists(), 'Retain the actual authorized recovery intake and private proof.');
        for ($i = 0; $i < count($recovery->hub_ids) - 1; $i++) {
            $route = $recovery->routes()->where('leg_index', $i)->with('manifest')->first();
            $member = $route?->manifest->buyerReturnParcels()->where('buyer_return_parcel_id', $recovery->buyer_return_parcel_id)->where('included', true)->first();
            $scan = $route?->manifest->events()->where('event_type', 'buyer-return-received')->where('barcode_scanned', $recovery->parcel->barcode)->first();
            $this->requireState($route && $member?->received_at && $scan && $route->manifest->source_hub_id === $recovery->hub_ids[$i]
                && $route->manifest->destination_hub_id === $recovery->hub_ids[$i + 1] && $scan->actor_id === $member->received_by_id
                && $scan->hub_id === $recovery->hub_ids[$i + 1] && $scan->actor_role === 'logistics'
                && in_array($route->manifest->status, ['received', 'closed'], true)
                && ($scan->payload['return_member_id'] ?? null) === $member->id, 'Record every actual authorized recovery leg before resuming the normal return route.');
        }
    }

    public function supportsCompletion(LogisticsManifestEvent $scan): bool
    {
        $recovery = BuyerReturnRecovery::find($scan->payload['return_recovery_id'] ?? null);
        if (! $recovery || $recovery->state !== 'completed' || $recovery->expected_hub_id !== $scan->hub_id || $recovery->original_member_id !== ($scan->payload['return_member_id'] ?? null)
            || ! $recovery->events()->where('event_type', 'completed')->where('context->original_receipt_id', $scan->id)->exists()) {
            return false;
        }
        $this->verifyChain($recovery);

        return true;
    }

    public function lock(BuyerReturnParcel $snapshot, User $actor, array $hubIds): array
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $sourceCase = $snapshot->returnCase;
        Order::whereKey($sourceCase->order_id)->lockForUpdate()->firstOrFail();
        Delivery::whereKey($sourceCase->delivery_id)->lockForUpdate()->firstOrFail();
        $this->eligibility->lockNetwork($snapshot->logistics_company_id, [$actor->id], $hubIds);
        $case = BuyerReturnCase::whereKey($sourceCase->id)->lockForUpdate()->firstOrFail();
        $parcel = BuyerReturnParcel::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        app(ShipmentLossCustody::class)->assertMovable($parcel);
        app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);

        return [$case, $parcel];
    }

    private function event(BuyerReturnRecovery $recovery, User $actor, string $type, string $reason, array $context, ?string $token = null, ?string $hash = null): BuyerReturnRecoveryEvent
    {
        return BuyerReturnRecoveryEvent::create(['recovery_id' => $recovery->id, 'actor_id' => $actor->id, 'sequence' => $recovery->version,
            'event_type' => $type, 'reason' => $reason, 'context' => $context, 'request_token' => $token, 'fingerprint' => $hash, 'created_at' => now()]);
    }

    private function parentEvent(BuyerReturnCase $case, User $actor, BuyerReturnRecoveryEvent $event, string $token, string $hash): BuyerReturnEvent
    {
        $case->version++;
        $case->save();

        return app(BuyerReturnService::class)->append($case, $actor, 'recovery-'.$event->event_type, $case->status, $event->reason, hash('sha256', $token), $hash,
            ['return_recovery_id' => $event->recovery_id, 'recovery_event_id' => $event->id, ...$event->context]);
    }

    private function requireState(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new DomainException($message);
        }
    }
}
