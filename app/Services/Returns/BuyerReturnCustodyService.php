<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnEvidence;
use App\Models\BuyerReturnManifestRoute;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\WaybillScanInputService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuyerReturnCustodyService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function prepare(BuyerReturnCase $case, BuyerReturnEvent $approval): BuyerReturnParcel
    {
        abort_unless(DB::transactionLevel() > 0 && $case->status === 'approved' && $approval->buyer_return_case_id === $case->id
            && $approval->event_type === 'approve' && $approval->target_status === 'approved' && in_array($approval->actor_role, ['seller', 'admin'], true), 409);

        return BuyerReturnParcel::firstOrCreate(['buyer_return_case_id' => $case->id], [
            'approval_event_id' => $approval->id, 'logistics_company_id' => $case->route_snapshot['forward_route']['logistics_company_id'],
            'barcode' => 'BRT-'.strtoupper(Str::random(20)), 'route_snapshot' => $case->route_snapshot['forward_route'],
            'hub_ids' => $case->route_snapshot['hub_ids'], 'route_position' => 0, 'status' => 'awaiting_dropoff', 'version' => 1,
            'custody' => ['kind' => 'buyer', 'user_id' => $case->buyer_id],
        ]);
    }

    public function scoped(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isLogistics() && $actor->canAccessPortal(), 403);
        $company = LogisticsCompany::eligible()->where('user_id', $actor->id)->first();
        $hubs = $this->eligibility->accessibleHubs($actor)->pluck('id');

        return BuyerReturnCase::whereHas('parcel', function ($query) use ($company, $hubs) {
            if ($company) {
                $query->where('logistics_company_id', $company->id);
            } else {
                $query->where(function ($scope) use ($hubs) {
                    $scope->whereRaw('1 = 0');
                    foreach ($hubs as $id) {
                        $scope->orWhereJsonContains('hub_ids', $id);
                    }
                });
            }
        });
    }

    public function command(User $actor, BuyerReturnCase $case, string $action, array $input, mixed $photo = null): BuyerReturnEvent
    {
        $inputs = app(WaybillScanInputService::class);
        unset($input['action']);
        $input = $inputs->normalize($input);
        $data = Validator::make(array_replace($input, ['action' => $action, 'photo' => $photo]), [
            'action' => ['required', 'string', Rule::in(['prepare-label', 'intake', 'stage', 'seller-receive'])],
            'request_token' => ['required', 'uuid'], 'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'barcode' => $inputs->barcodeRules($action !== 'prepare-label'),
            'hub_id' => [$action === 'intake' || $action === 'stage' ? 'required' : 'nullable', new AsciiPositiveInteger, 'integer'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'weight_kg' => [$action === 'intake' ? 'required' : 'prohibited', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,3})?\z/'],
            'condition' => [$action === 'intake' || $action === 'seller-receive' ? 'required' : 'nullable', Rule::in(['accepted', 'disputed', 'intact', 'damaged'])],
            'photo' => [$action === 'intake' || $action === 'seller-receive' ? 'required' : 'prohibited', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['prohibited'], 'amount' => ['prohibited'], 'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'],
        ])->validate();
        unset($data['photo']);
        $data['expected_version'] = (int) $data['expected_version'];
        if (isset($data['hub_id'])) {
            $data['hub_id'] = (int) $data['hub_id'];
        }
        $info = $photo instanceof UploadedFile ? app(BuyerReturnFiles::class)->inspect($photo, 'photo') : null;
        $hash = hash('sha256', json_encode([$case->id, $actor->id, $action, $data, $info['content_hash'] ?? null], JSON_THROW_ON_ERROR));
        $token = hash('sha256', $data['request_token']);
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $case, $action, $data, $photo, $info, $hash, $token, &$paths) {
                Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
                $actor = app(BuyerReturnService::class)->current($actor);
                $network = in_array($action, ['intake', 'stage'], true);
                if ($network) {
                    $this->eligibility->lockNetwork($case->route_snapshot['forward_route']['logistics_company_id'], [$actor->id], [$data['hub_id']]);
                    $actor = User::findOrFail($actor->id);
                    abort_unless($actor->isLogistics() && $actor->canAccessPortal()
                        && $this->eligibility->canScan($actor, LogisticsHub::findOrFail($data['hub_id'])), 403);
                } else {
                    User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                }
                $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                app(BuyerReturnService::class)->owned($actor, $case);
                $prior = BuyerReturnEvent::where('request_token_hash', $token)->first();
                if ($prior) {
                    abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $hash), 409,
                        'This scan request already saved different evidence. Reload the return before continuing.');

                    return $prior;
                }
                abort_unless($case->version === $data['expected_version'], 409, 'This return changed. Reload its actual custody before scanning again.');
                $parcel = BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->first();
                if ($action === 'prepare-label') {
                    abort_unless(($actor->isBuyer() && $actor->id === $case->buyer_id) || ($actor->isAdmin() && $actor->canAccessPortal()), 403);
                    abort_unless($case->status === 'approved' && $case->dropoff_until && now()->lessThanOrEqualTo($case->dropoff_until), 409,
                        'A current approved return is required before preparing its label.');
                    $approval = $case->events()->where('event_type', 'approve')->latest('id')->firstOrFail();
                    $parcel ??= $this->prepare($case, $approval);
                } else {
                    abort_unless($parcel && ($data['barcode'] ?? null) === $parcel->barcode, 422, 'Scan the separate return barcode shown on this case. The original delivery waybill cannot record a buyer return.');
                    $this->assertSource($case, $parcel);
                }
                $before = $parcel->state();
                $source = $case->status;
                if ($action === 'intake') {
                    abort_unless($case->status === 'approved' && $parcel->status === 'awaiting_dropoff' && $case->dropoff_until
                        && now()->lessThanOrEqualTo($case->dropoff_until), 409, 'This return is not awaiting a timely first drop-off. Check its saved intake or review history.');
                    abort_unless($data['hub_id'] === $parcel->hub_ids[0] && in_array($data['condition'], ['intact', 'damaged'], true), 422,
                        'The original destination Bayan Hub must record the actual return intake and condition.');
                    $grams = $this->grams($data['weight_kg']);
                    if ($grams < 1 || $grams > 50000000) {
                        throw ValidationException::withMessages(['weight_kg' => 'Record an actual weight from 0.001 to 50,000 kg.']);
                    }
                    $parcel->fill(['status' => 'at_hub', 'current_hub_id' => $data['hub_id'], 'weight_grams' => $grams,
                        'custody' => ['kind' => 'hub', 'hub_id' => $data['hub_id'], 'received_by_id' => $actor->id]]);
                    $case->status = 'at_destination_hub';
                } elseif ($action === 'stage') {
                    $this->assertFinishedRoute($case, $parcel);
                    abort_unless($parcel->status === 'at_hub' && $data['hub_id'] === $parcel->current_hub_id, 409, 'Only the actual origin-hub holder may stage this fully received return.');
                    $parcel->status = 'ready_for_seller';
                    $case->status = 'ready_for_seller';
                } elseif ($action === 'seller-receive') {
                    abort_unless($actor->isSeller() && $actor->isKycApproved() && $actor->id === $case->seller_id && $actor->email_verified_at, 403);
                    $this->assertFinishedRoute($case, $parcel);
                    abort_unless($parcel->status === 'ready_for_seller' && $case->status === 'ready_for_seller'
                        && in_array($data['condition'], ['accepted', 'disputed'], true), 409,
                        'The origin handler must stage the actual parcel before its original seller confirms receipt and condition.');
                    $parcel->fill(['status' => 'seller_received', 'current_hub_id' => null, 'custody' => ['kind' => 'seller', 'user_id' => $actor->id]]);
                    $case->status = $data['condition'] === 'accepted' ? 'seller_received' : 'condition_review';
                }
                if ($action !== 'prepare-label') {
                    $parcel->version++;
                    $parcel->save();
                }
                $case->version++;
                $case->save();
                $event = app(BuyerReturnService::class)->append($case, $actor, 'return-'.$action, $source, $data['reason'], $token, $hash,
                    ['barcode' => $parcel->barcode, 'source_state' => $before, 'target_state' => $parcel->state(), 'condition' => $data['condition'] ?? null]);
                if ($photo && $info) {
                    $paths[] = $path = app(BuyerReturnFiles::class)->store($photo, $info);
                    BuyerReturnEvidence::create(['buyer_return_case_id' => $case->id, 'buyer_return_event_id' => $event->id, 'purpose' => $action,
                        'path' => $path, 'content_hash' => $info['content_hash'], 'mime' => $info['mime'], 'bytes' => $info['bytes'], 'created_at' => now()]);
                }

                return $event;
            }, 3);
            app(BuyerReturnFiles::class)->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            app(BuyerReturnFiles::class)->discardUnused($paths);
            throw $error;
        }
    }

    public function grams(string $kg): int
    {
        [$whole, $fraction] = array_pad(explode('.', $kg, 2), 2, '0');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public function assertSource(BuyerReturnCase $case, BuyerReturnParcel $parcel): void
    {
        $approval = BuyerReturnEvent::findOrFail($parcel->approval_event_id);
        abort_unless($parcel->buyer_return_case_id === $case->id && $approval->buyer_return_case_id === $case->id
            && $approval->event_type === 'approve' && $approval->target_status === 'approved'
            && $parcel->hub_ids === $case->route_snapshot['hub_ids'] && $parcel->route_snapshot === $case->route_snapshot['forward_route']
            && $parcel->logistics_company_id === $parcel->route_snapshot['logistics_company_id'], 409, 'The original return authority or route needs review.');
    }

    public function assertFinishedRoute(BuyerReturnCase $case, BuyerReturnParcel $parcel, bool $afterSellerReceipt = false): void
    {
        $this->assertSource($case, $parcel);
        $held = $afterSellerReceipt ? ($parcel->status === 'seller_received' && $parcel->custody === ['kind' => 'seller', 'user_id' => $case->seller_id])
            : ($parcel->current_hub_id === $parcel->hub_ids[count($parcel->hub_ids) - 1]
                && ($parcel->custody['kind'] ?? null) === 'hub' && ($parcel->custody['hub_id'] ?? null) === $parcel->current_hub_id);
        abort_unless($parcel->route_position === count($parcel->hub_ids) - 1 && $held, 409,
            'Record every original reverse hub receipt before staging or seller collection.');
        $intake = $case->events()->where('event_type', 'return-intake')->where('actor_role', 'logistics')->first();
        abort_unless($intake && ($intake->context['target_state']['current_hub_id'] ?? null) === $parcel->hub_ids[0]
            && $case->evidence()->where('buyer_return_event_id', $intake->id)->where('purpose', 'intake')->exists(), 409,
            'The original return needs its actual hub intake and photo evidence.');
        for ($index = 0; $index < count($parcel->hub_ids) - 1; $index++) {
            $member = $parcel->members()->with('manifest')->where('leg_index', $index)->where('included', true)->whereNotNull('received_at')->first();
            $scan = $member ? LogisticsManifestEvent::where('manifest_id', $member->manifest_id)->where('event_type', 'buyer-return-received')
                ->where('barcode_scanned', $parcel->barcode)->where('hub_id', $parcel->hub_ids[$index + 1])->first() : null;
            abort_unless($member && $scan && $member->manifest->direction === 'buyer_return' && in_array($member->manifest->status, ['received', 'closed'], true)
                && $member->manifest->source_hub_id === $parcel->hub_ids[$index] && $member->manifest->destination_hub_id === $parcel->hub_ids[$index + 1]
                && ($scan->payload['return_member_id'] ?? null) === $member->id && $scan->actor_id === $member->received_by_id, 409,
                'This return is missing an actual retained manifest receipt. Its route cannot be skipped.');
        }
    }

    public function presentation(User $actor, BuyerReturnCase $case): ?array
    {
        $parcel = $case->parcel()->first();
        $actor = User::findOrFail($actor->id);
        if (! $parcel) {
            if ($case->status !== 'approved') {
                return null;
            }
            $canPrepare = (($actor->isBuyer() && $actor->id === $case->buyer_id) || ($actor->isAdmin() && $actor->canAccessPortal()))
                && $case->dropoff_until && now()->lessThanOrEqualTo($case->dropoff_until);

            return ['barcode' => null, 'status' => 'label_needed', 'position' => 0, 'weight_grams' => null, 'custody' => ['kind' => 'buyer'],
                'hubs' => [], 'actions' => $canPrepare ? [['action' => 'prepare-label', 'hub_id' => null, 'label' => 'Prepare return label']] : [],
                'current_hub_name' => null, 'manifest_url' => null, 'manifest_reference' => null];
        }
        $hubs = LogisticsHub::whereIn('id', $parcel->hub_ids)->get()->keyBy('id');
        $options = [];
        if ($actor->isLogistics() && $actor->canAccessPortal()) {
            foreach ($this->eligibility->accessibleHubs($actor)->get() as $hub) {
                if (! $this->eligibility->canScan($actor, $hub)) {
                    continue;
                }
                if ($case->status === 'approved' && $parcel->status === 'awaiting_dropoff' && $hub->id === $parcel->hub_ids[0]
                    && $case->dropoff_until && now()->lessThanOrEqualTo($case->dropoff_until)) {
                    $options[] = ['action' => 'intake', 'hub_id' => $hub->id, 'label' => 'Accept physical return'];
                }
                if ($parcel->status === 'at_hub' && $parcel->route_position === count($parcel->hub_ids) - 1 && $hub->id === $parcel->current_hub_id) {
                    $options[] = ['action' => 'stage', 'hub_id' => $hub->id, 'label' => 'Stage for seller collection'];
                }
            }
        }
        if ($actor->isSeller() && $actor->isKycApproved() && $actor->id === $case->seller_id && $actor->email_verified_at
            && $case->status === 'ready_for_seller' && $parcel->status === 'ready_for_seller') {
            $options[] = ['action' => 'seller-receive', 'hub_id' => null, 'label' => 'Confirm actual return receipt'];
        }
        $manifestRoute = BuyerReturnManifestRoute::where('buyer_return_case_id', $case->id)->orderByDesc('leg_index')->first();
        $manifest = $manifestRoute ? LogisticsManifest::find($manifestRoute->manifest_id) : null;
        $canReadManifest = $manifest && $actor->isLogistics()
            && app(LogisticsManifestService::class)->readable($actor)->whereKey($manifest->id)->exists();

        return ['barcode' => $parcel->barcode, 'status' => $parcel->status, 'position' => $parcel->route_position,
            'weight_grams' => $parcel->weight_grams, 'custody' => $parcel->custody, 'actions' => $options,
            'hubs' => array_map(fn ($id) => $hubs->get($id)?->only(['id', 'name', 'code', 'address', 'operating_hours']), $parcel->hub_ids),
            'can_dropoff' => $case->status === 'approved' && $case->dropoff_until && now()->lessThanOrEqualTo($case->dropoff_until),
            'current_hub_name' => $hubs->get($parcel->current_hub_id)?->name, 'manifest_url' => $canReadManifest
                ? (str_starts_with(request()->getHost(), 'hub.') ? '/manifests/' : '/hub/manifests/').$manifest->id : null,
            'manifest_pending' => $canReadManifest && $manifestRoute->leg_index === $parcel->route_position && in_array($manifest->status, ['draft', 'sealed'], true),
            'manifest_reference' => $manifest?->reference];
    }
}
