<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsPlacementRecord;
use App\Models\LogisticsTransportTrip;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Courier\CourierOperationsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HubFleetService
{
    public const DEFAULT_CAPACITIES = ['motorcycle' => 40, 'tricycle' => 100, 'l300_van' => 1200, 'wing_truck' => 8000];

    public const DEFAULT_MODELS = ['motorcycle' => 'Delivery motorcycle', 'tricycle' => 'Delivery tricycle', 'l300_van' => 'Closed transfer van', 'wing_truck' => 'Wing truck'];

    public function canRegister(User $actor, LogisticsHub $hub): bool
    {
        $eligibility = app(LogisticsEligibilityService::class);

        return $eligibility->accessibleHubs($actor)->whereKey($hub->id)->exists()
            && ($actor->isAdmin() || $eligibility->isCompanyManager($actor, $hub->logistics_company_id)
                || $eligibility->canManageHubTeam($actor, $hub));
    }

    public function register(User $actor, LogisticsHub $hub, array $input): LogisticsFleet
    {
        abort_unless($this->canRegister($actor, $hub), 403);
        if (is_string($input['plate_number'] ?? null)) {
            $input['plate_number'] = strtoupper(trim($input['plate_number']));
        }
        $data = $this->values($input);
        $target = $this->target($actor, $hub, $input);

        try {
            return LogisticsFleet::create([
                ...$data,
                'model' => $data['model'] ?? self::DEFAULT_MODELS[$data['vehicle_type']],
                'logistics_company_id' => $hub->logistics_company_id,
                'hub_id' => $target->id,
                'assigned_driver_id' => null,
                'status' => 'active',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['plate_number' => 'This plate number is already registered.']);
        }
    }

    public function destinations(User $actor, LogisticsHub $source): Collection
    {
        if (! $this->canRegister($actor, $source)) {
            return collect();
        }
        $query = LogisticsHub::eligible()->where('logistics_company_id', $source->logistics_company_id);
        $owner = $actor->isAdmin() || app(LogisticsEligibilityService::class)->isCompanyManager($actor, $source->logistics_company_id);
        if (! $owner) {
            $query->where(fn ($q) => $q->whereKey($source->id)
                ->when($source->isMotherHub(), fn ($children) => $children->orWhere('parent_hub_id', $source->id)));
        }

        return $query->orderBy('name')->get();
    }

    private function target(User $actor, LogisticsHub $source, array $input): LogisticsHub
    {
        Validator::make($input, ['target_hub_id' => ['nullable', 'integer', 'min:1']])->validate();
        $hub = $this->destinations($actor, $source)->firstWhere('id', $input['target_hub_id'] ?? $source->id);
        abort_unless($hub, 403, 'Choose a hub in your managed network.');

        return $hub;
    }

    public static function stateToken(LogisticsFleet $vehicle): string
    {
        $state = $vehicle->only(['id', 'logistics_company_id', 'hub_id', 'assigned_driver_id', 'plate_number', 'vehicle_type', 'model', 'capacity_kg', 'status', 'restriction_version']);
        $state['restriction_version'] = (int) ($state['restriction_version'] ?? 0);

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function values(array $input, ?LogisticsFleet $vehicle = null): array
    {
        if (is_string($input['plate_number'] ?? null)) {
            $input['plate_number'] = strtoupper(trim($input['plate_number']));
        }

        return Validator::make($input, [
            'plate_number' => ['required', 'string', 'max:50', 'regex:/\A[A-Z0-9]+(?:[ -][A-Z0-9]+)*\z/', Rule::unique('logistics_fleet', 'plate_number')->ignore($vehicle?->id)],
            'vehicle_type' => ['required', Rule::in(array_keys(self::DEFAULT_CAPACITIES))],
            'model' => ['nullable', 'string', 'max:255', new ApplicationText('model', 1, 255)],
            'capacity_kg' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
        ])->validate();

    }

    public function update(User $actor, LogisticsHub $source, LogisticsFleet $vehicle, array $input): void
    {
        abort_unless($this->canRegister($actor, $source), 403);
        $target = $this->target($actor, $source, $input);
        $allowed = $this->destinations($actor, $source)->pluck('id');
        abort_unless($vehicle->logistics_company_id === $source->logistics_company_id && $allowed->contains($vehicle->hub_id), 403);
        Validator::make($input, ['expected_state' => ['required', 'string', 'size:64']])->validate();
        try {
            DB::transaction(function () use ($actor, $source, $target, $vehicle, $input, $allowed) {
                app(LogisticsEligibilityService::class)->lockNetwork($source->logistics_company_id, [$actor->id, $vehicle->assigned_driver_id], $allowed->all());
                $profile = $vehicle->assigned_driver_id ? CourierProfile::where('user_id', $vehicle->assigned_driver_id)->lockForUpdate()->first() : null;
                $current = LogisticsFleet::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals(self::stateToken($current), $input['expected_state'])) {
                    throw ValidationException::withMessages(['expected_state' => 'This vehicle changed. Refresh the fleet before editing it.']);
                }
                abort_unless($this->canRegister($actor, $source) && $this->destinations($actor, $source)->contains('id', $target->id), 403);
                if (! in_array($current->status, ['active', 'idle', 'maintenance'], true)) {
                    throw ValidationException::withMessages(['vehicle' => 'This vehicle needs a restriction review before editing.']);
                }
                if (LogisticsManifest::where('vehicle_id', $current->id)->where(fn ($q) => $q->whereIn('status', ['draft', 'sealed', 'dispatched'])
                    ->orWhereIn('id', LogisticsManifest::awaitingPhysicalReceipt()->select('logistics_manifests.id')))->exists()
                    || LogisticsTransportTrip::where('vehicle_id', $current->id)->whereIn('status', ['ready', 'dispatched'])->exists()
                    || ($current->assigned_driver_id && Delivery::riderHasActiveWork($current->assigned_driver_id))) {
                    throw ValidationException::withMessages(['vehicle' => 'Finish or recover active deliveries and transfers before editing this vehicle.']);
                }
                $data = $this->values($input, $current);
                $moving = $target->id !== $current->hub_id;
                if ($current->assigned_driver_id && (! $profile || $profile->vehicle_id !== $current->id || $profile->assigned_hub_id !== $current->hub_id
                    || $profile->logistics_company_id !== $current->logistics_company_id)) {
                    throw ValidationException::withMessages(['vehicle' => 'The driver assignment needs review before editing.']);
                }
                if ($moving && $profile) {
                    try {
                        app(CourierOperationsService::class)->setAvailability(User::findOrFail($profile->user_id), false);
                    } catch (\DomainException $error) {
                        throw ValidationException::withMessages(['vehicle' => $error->getMessage()]);
                    }
                    $before = $profile->only(['logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'assigned_barangay']);
                    $profile->fill(['assigned_hub_id' => $target->id, 'assigned_barangay' => null, 'is_available' => false])->save();
                    LogisticsPlacementRecord::create(['logistics_company_id' => $current->logistics_company_id, 'actor_id' => $actor->id,
                        'user_id' => $profile->user_id, 'kind' => 'courier', 'before_state' => $before,
                        'after_state' => $profile->only(['logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'assigned_barangay']), 'recorded_at' => now()]);
                }
                $current->fill([...$data, 'model' => $data['model'] ?? self::DEFAULT_MODELS[$data['vehicle_type']], 'hub_id' => $target->id])->save();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['plate_number' => 'This plate number is already registered.']);
        }
    }
}
