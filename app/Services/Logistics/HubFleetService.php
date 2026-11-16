<?php

namespace App\Services\Logistics;

use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HubFleetService
{
    public const DEFAULT_CAPACITIES = ['motorcycle' => 40, 'tricycle' => 100, 'l300_van' => 1200, 'wing_truck' => 8000];

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
        $data = Validator::make($input, [
            'plate_number' => ['required', 'string', 'max:50', 'regex:/\A[A-Z0-9]+(?:[ -][A-Z0-9]+)*\z/', Rule::unique('logistics_fleet', 'plate_number')],
            'vehicle_type' => ['required', Rule::in(array_keys(self::DEFAULT_CAPACITIES))],
            'model' => ['nullable', 'string', 'max:255', new ApplicationText('model', 1, 255)],
            'capacity_kg' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
        ])->validate();

        try {
            return LogisticsFleet::create([
                ...$data,
                'logistics_company_id' => $hub->logistics_company_id,
                'hub_id' => $hub->id,
                'assigned_driver_id' => null,
                'status' => 'active',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['plate_number' => 'This plate number is already registered.']);
        }
    }
}
