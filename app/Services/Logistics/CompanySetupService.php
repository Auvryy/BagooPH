<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\PhilippineContact;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\BirthDateEligibility;
use App\Services\Finance\CodMoney;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CompanySetupService
{
    public function owner(User $actor, int $companyId, bool $allowPlatform = false): LogisticsCompany
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal(), 403);
        $company = LogisticsCompany::eligible()->findOrFail($companyId);
        abort_unless(($actor->isLogistics() && $company->user_id === $actor->id) || ($allowPlatform && $actor->isAdmin()), 403, 'Only this approved company owner can create its facilities and personnel.');

        return $company;
    }

    public function hub(User $actor, int $companyId, array $input): LogisticsHub
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', new ApplicationText('name', 2, 120)],
            'code' => ['required', 'string', 'max:40', 'regex:/\A[A-Z0-9][A-Z0-9-]+\z/', Rule::unique('logistics_hubs', 'code')],
            'tier' => 'required|in:regional_mother_hub,local_bayan_hub', 'parent_hub_id' => 'nullable|required_if:tier,local_bayan_hub|integer|min:1',
            'province' => ['required', 'string', new ApplicationText('location', 2, 100)],
            'city_municipality' => ['required', 'string', new ApplicationText('location', 2, 100)],
            'barangay' => ['nullable', 'string', new ApplicationText('location', 2, 100)],
            'address' => ['required', 'string', new ApplicationText('address', 5, 500)],
            'postal_codes' => 'required_if:tier,local_bayan_hub|array|max:100', 'postal_codes.*' => 'required|string|regex:/\A[0-9]{4}\z/|distinct',
            'coverage_barangays' => 'nullable|array|max:300', 'coverage_barangays.*' => ['required', 'string', new ApplicationText('location', 2, 100)],
            'capacity' => 'required|integer|between:1,1000000', 'allows_self_pickup' => 'required|boolean',
            'operating_hours' => ['nullable', 'string', new ApplicationText('notes', 3, 250)],
        ])->validate();

        return DB::transaction(function () use ($actor, $companyId, $data) {
            $company = $this->owner($actor, $companyId);
            app(LogisticsEligibilityService::class)->lockNetwork($company->id, [$actor->id], [$data['parent_hub_id'] ?? null]);
            if ($data['tier'] === 'local_bayan_hub') {
                if (! LogisticsHub::eligible()->where('logistics_company_id', $company->id)->where('tier', 'regional_mother_hub')->whereKey($data['parent_hub_id'])->exists()) {
                    throw new DomainException('Choose an active Mother Hub from this company.');
                }
                foreach (LogisticsHub::where('logistics_company_id', $company->id)->where('tier', 'local_bayan_hub')->get() as $other) {
                    if (array_intersect($other->postal_codes ?? [], $data['postal_codes'])) {
                        throw new DomainException('A child hub in this company already covers one of these postal codes. Use distinct postal coverage.');
                    }
                }
                if (count($data['postal_codes']) < 1) {
                    throw new DomainException('Add at least one served postal code for this child hub.');
                }
            } else {
                $data['parent_hub_id'] = null;
            }

            return LogisticsHub::create($data + ['logistics_company_id' => $company->id, 'is_active' => true]);
        });
    }

    public function staff(User $actor, int $companyId, array $input): User
    {
        $data = Validator::make($input, [
            'kind' => 'required|in:operator,rider', 'hub_id' => 'required|integer|min:1',
            'first_name' => ['required', 'string', new ApplicationText('name', 2, 100)],
            'last_name' => ['required', 'string', new ApplicationText('name', 2, 100)],
            'birthday' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.app(BirthDateEligibility::class)->limits()['adult_maximum']],
            'email' => 'required|string|email:rfc|max:255', 'phone' => ['required', 'string', new PhilippineContact],
            'password' => ['required', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();
        $role = $data['kind'] === 'rider' ? 'courier' : 'logistics';
        $email = strtolower(trim($data['email']));
        Validator::make(['email' => $email], ['email' => [Rule::unique('users', 'email')->where('role', $role)]])->validate();

        return DB::transaction(function () use ($actor, $companyId, $data, $role, $email) {
            $company = $this->owner($actor, $companyId);
            app(LogisticsEligibilityService::class)->lockNetwork($company->id, [$actor->id], [$data['hub_id']]);
            $hub = LogisticsHub::eligible()->where('logistics_company_id', $company->id)->findOrFail($data['hub_id']);
            if ($role === 'courier' && ! $hub->isBayanHub()) {
                throw new DomainException('Assign a pickup or delivery rider to an active child hub.');
            }
            $user = new User(['first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'name' => $data['first_name'].' '.$data['last_name'], 'birthday' => $data['birthday'], 'email' => $email,
                'phone' => $data['phone'], 'password' => $data['password'], 'role' => $role,
                'status' => 'active', 'kyc_status' => 'approved', 'kyc_reviewed_at' => now(),
                'city' => $hub->city_municipality, 'province' => $hub->province]);
            $user->forceFill(['must_change_password' => true, 'provisioned_by_id' => $actor->id,
                'provisioned_company_id' => $company->id, 'temporary_password_expires_at' => now()->addDays(7)]);
            $user->save();
            if ($role === 'logistics') {
                HubHandler::create(['user_id' => $user->id, 'logistics_company_id' => $company->id, 'hub_id' => $hub->id, 'is_active' => true]);
            } else {
                CourierProfile::create(['user_id' => $user->id, 'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'is_available' => false]);
            }
            DB::table('company_staff_provisions')->insert(['logistics_company_id' => $company->id, 'user_id' => $user->id,
                'actor_id' => $actor->id, 'hub_id' => $hub->id, 'kind' => $data['kind']]);

            return $user;
        });
    }

    public function tariff(User $actor, int $companyId, array $input): LogisticsCompany
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:1',
            'base_fee' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/'],
            'additional_kg_fee' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/'],
            'included_weight_grams' => 'required|integer|between:1,1000000', 'shipping_earnings_model' => 'required|in:standard,dedicated_fleet',
        ])->validate();

        return DB::transaction(function () use ($actor, $companyId, $data) {
            $company = $this->owner($actor, $companyId, allowPlatform: true);
            $company = LogisticsCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($company->tariff_version !== (int) $data['version']) {
                throw new DomainException('These rates changed. Review the current tariff before saving.');
            }
            $before = $company->only(['base_shipping_cents', 'included_weight_grams', 'additional_kg_cents', 'shipping_earnings_model']);
            $company->fill(['base_shipping_cents' => CodMoney::cents($data['base_fee']), 'additional_kg_cents' => CodMoney::cents($data['additional_kg_fee']),
                'included_weight_grams' => $data['included_weight_grams'], 'shipping_earnings_model' => $data['shipping_earnings_model'], 'tariff_version' => $company->tariff_version + 1])->save();
            DB::table('logistics_tariff_events')->insert(['logistics_company_id' => $company->id, 'actor_id' => $actor->id, 'version' => $company->tariff_version,
                'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($company->only(array_keys($before)), JSON_THROW_ON_ERROR)]);

            return $company;
        });
    }

    public function resetTemporaryPassword(User $actor, int $companyId, User $staff, array $input): void
    {
        $data = Validator::make($input, ['current_password' => 'required|string|max:128',
            'password' => ['required', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()]])->validate();
        DB::transaction(function () use ($actor, $companyId, $staff, $data) {
            $company = $this->owner($actor, $companyId);
            User::whereIn('id', [$actor->id, $staff->id])->orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            $staff = User::findOrFail($staff->id);
            if (! Hash::check($data['current_password'], $actor->password)) {
                throw ValidationException::withMessages(['current_password' => 'Enter your current company-owner password.']);
            }
            abort_unless($staff->provisioned_company_id === $company->id && $staff->must_change_password && $staff->closed_at === null, 403,
                'Only an unactivated company-created account can receive another temporary password.');
            $expires = now()->addDays(7);
            $staff->forceFill(['password' => $data['password'], 'temporary_password_expires_at' => $expires])->save();
            app(AccountSessionService::class)->passwordChanged($staff);
            DB::table('company_staff_login_resets')->insert(['logistics_company_id' => $company->id, 'user_id' => $staff->id,
                'actor_id' => $actor->id, 'expires_at' => $expires]);
        });
    }

    public function coverageToken(LogisticsHub $hub): string
    {
        return hash('sha256', json_encode($hub->only(['id', 'logistics_company_id', 'parent_hub_id', 'postal_codes', 'coverage_barangays']), JSON_THROW_ON_ERROR));
    }

    public function coverage(User $actor, int $companyId, LogisticsHub $snapshot, array $input): void
    {
        $data = Validator::make($input, ['parent_hub_id' => 'required|integer|min:1', 'postal_codes' => 'required|array|min:1|max:100',
            'postal_codes.*' => 'required|string|regex:/\A[0-9]{4}\z/|distinct', 'coverage_token' => 'required|string|size:64',
            'coverage_barangays' => 'sometimes|array|max:300', 'coverage_barangays.*' => ['required', 'string', new ApplicationText('location', 2, 100)]])->validate();
        DB::transaction(function () use ($actor, $companyId, $snapshot, $data) {
            $company = $this->owner($actor, $companyId);
            app(LogisticsEligibilityService::class)->lockNetwork($company->id, [$actor->id], [$snapshot->id, $data['parent_hub_id']]);
            $hub = LogisticsHub::whereKey($snapshot->id)->where('logistics_company_id', $company->id)->where('tier', 'local_bayan_hub')->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->coverageToken($hub), $data['coverage_token'])) {
                throw new DomainException('This hub coverage changed. Reload before saving your changes.');
            }
            if (! LogisticsHub::eligible()->where('logistics_company_id', $company->id)->where('tier', 'regional_mother_hub')->whereKey($data['parent_hub_id'])->exists()) {
                throw new DomainException('Choose an active Mother Hub from this company.');
            }
            foreach (LogisticsHub::where('logistics_company_id', $company->id)->where('tier', 'local_bayan_hub')->whereKeyNot($hub->id)->get() as $other) {
                if (array_intersect($other->postal_codes ?? [], $data['postal_codes'])) {
                    throw new DomainException('Another child hub already serves one of these postal codes.');
                }
            }
            $hub->update(['parent_hub_id' => $data['parent_hub_id'], 'postal_codes' => $data['postal_codes'],
                ...array_intersect_key($data, ['coverage_barangays' => true])]);
        });
    }
}
