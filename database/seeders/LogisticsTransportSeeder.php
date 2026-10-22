<?php

namespace Database\Seeders;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LogisticsTransportSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $company = LogisticsCompany::eligible()->where('code', 'BGX')
                ->where('name', 'Bagoo Express Dispatch Fleet')->lockForUpdate()->first();
            if (! $company) {
                throw new RuntimeException('The existing active BGX demo company is required. No transport records were changed.');
            }
            foreach ([
                ['BH-LBN-01', 'local_bayan_hub', 'origin.transport@bagoo.test', 'Los Banos Transport Driver', 'BG-LBN-201'],
                ['MH-LAG-01', 'regional_mother_hub', 'mother.transport@bagoo.test', 'Mother Hub Transport Driver', 'BG-LAG-201'],
            ] as [$code, $tier, $email, $name, $plate]) {
                $hub = LogisticsHub::eligible()->where('logistics_company_id', $company->id)
                    ->where('code', $code)->where('tier', $tier)->lockForUpdate()->first();
                if (! $hub) {
                    throw new RuntimeException('The expected active BGX demo hub '.$code.' is required. No transport records were changed.');
                }
                $this->ensureTransport($company, $hub, $email, $name, $plate);
            }
        });
    }

    private function ensureTransport(LogisticsCompany $company, LogisticsHub $hub, string $email, string $name, string $plate): void
    {
        $driver = User::where('email', $email)->where('role', 'courier')->lockForUpdate()->first();
        if ($driver && (! $driver->canAccessPortal() || ! $driver->isEligibleCourier())) {
            throw new RuntimeException('The reserved transport courier '.$email.' is restricted. Review it manually; no approval or password was changed.');
        }
        if (! $driver) {
            $driver = User::create(['email' => $email, 'role' => 'courier',
                'name' => $name, 'email_verified_at' => now(), 'password' => 'Password1234',
                'status' => 'active', 'kyc_status' => 'approved', 'kyc_reviewed_at' => now(),
                'address' => $hub->address, 'city' => $hub->city_municipality]);
        }
        if (LogisticsFleet::where('assigned_driver_id', $driver->id)->where('plate_number', '!=', $plate)->exists()) {
            throw new RuntimeException('The reserved transport courier already has another vehicle. Review it manually; nothing was reassigned.');
        }
        $vehicle = LogisticsFleet::where('plate_number', $plate)->lockForUpdate()->first();
        if ($vehicle && $vehicle->assigned_driver_id === null
            && $vehicle->logistics_company_id === $company->id && $vehicle->hub_id === $hub->id
            && $vehicle->vehicle_type === 'l300_van' && $vehicle->status === 'active') {
            if (LogisticsManifest::where('vehicle_id', $vehicle->id)->exists()
                || CourierProfile::where('vehicle_id', $vehicle->id)->exists()
                || Delivery::riderHasActiveWork($driver->id)
                || LogisticsManifest::where('driver_id', $driver->id)->where('status', '!=', 'closed')->exists()) {
                throw new RuntimeException('The unassigned reserved demo van has retained transport or assignment evidence. Review it manually; nothing was reassigned.');
            }
            $vehicle->update(['assigned_driver_id' => $driver->id]);
        }
        if ($vehicle && ($vehicle->logistics_company_id !== $company->id || $vehicle->hub_id !== $hub->id
            || $vehicle->vehicle_type !== 'l300_van' || $vehicle->assigned_driver_id !== $driver->id || $vehicle->status !== 'active')) {
            throw new RuntimeException('The reserved demo vehicle '.$plate.' has conflicting ownership, assignment or status. Review it manually; nothing was reassigned.');
        }
        if (! $vehicle) {
            $vehicle = LogisticsFleet::create(['plate_number' => $plate, 'logistics_company_id' => $company->id,
                'hub_id' => $hub->id, 'vehicle_type' => 'l300_van', 'model' => 'Hub transfer closed van',
                'capacity_kg' => 1200, 'assigned_driver_id' => $driver->id, 'status' => 'active']);
        }
        $profile = CourierProfile::where('user_id', $driver->id)->lockForUpdate()->first();
        if ($profile && ($profile->logistics_company_id !== $company->id || $profile->assigned_hub_id !== $hub->id
            || $profile->vehicle_id !== $vehicle->id || $profile->plate_number !== $plate || $profile->or_cr_status !== 'Verified')) {
            throw new RuntimeException('The reserved transport courier '.$email.' has conflicting company, hub or vehicle details. Review it manually; nothing was reassigned.');
        }
        if (! $profile) {
            if (Delivery::riderHasActiveWork($driver->id) || LogisticsManifest::where('driver_id', $driver->id)->where('status', '!=', 'closed')->exists()) {
                throw new RuntimeException('The reserved transport courier has active work. No assignment was changed.');
            }
            CourierProfile::create(['user_id' => $driver->id, 'logistics_company_id' => $company->id,
                'assigned_hub_id' => $hub->id, 'vehicle_id' => $vehicle->id, 'vehicle_type' => 'Closed van',
                'plate_number' => $plate, 'license_number' => 'DEMO-TRANSPORT-'.$hub->id,
                'or_cr_status' => 'Verified', 'is_available' => true]);
        }
    }
}
