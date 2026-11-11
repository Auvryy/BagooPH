<?php

namespace App\Services\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\OtpService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffInvitationService
{
    public const PURPOSE = 'company_staff_activation';

    public function pending(string $email, string $kind): ?User
    {
        $user = User::where('role', $kind === 'rider' ? 'courier' : 'logistics')
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->first();
        if (! $user || ! $user->must_change_password || ! $user->provisioned_company_id
            || $user->closed_at || $user->status !== 'active' || ! in_array($user->kyc_status, ['approved', 'verified'], true)
            || ! $user->temporary_password_expires_at?->isFuture()
            || ! LogisticsCompany::eligible()->whereKey($user->provisioned_company_id)->exists()) {
            return null;
        }
        $provision = DB::table('company_staff_provisions')->where('user_id', $user->id)
            ->where('logistics_company_id', $user->provisioned_company_id)->where('kind', $kind)->whereNull('accepted_at')->first();
        $hub = $provision ? LogisticsHub::eligible()->where('logistics_company_id', $user->provisioned_company_id)->find($provision->hub_id) : null;
        if (! $hub) {
            return null;
        }
        $assigned = $kind === 'operator'
            ? HubHandler::where('user_id', $user->id)->where('logistics_company_id', $hub->logistics_company_id)->where('hub_id', $hub->id)->where('is_active', true)->exists()
            : $user->courierProfile?->logistics_company_id === $hub->logistics_company_id && $user->courierProfile?->assigned_hub_id === $hub->id;

        return $assigned ? $user : null;
    }

    public function assignmentToken(User $user): string
    {
        $provision = DB::table('company_staff_provisions')->where('user_id', $user->id)->first();

        return hash_hmac('sha256', json_encode([$provision?->logistics_company_id, $provision?->hub_id,
            $provision?->kind, $user->getAuthPassword()], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function send(User $user): array
    {
        $kind = $user->isCourier() ? 'rider' : 'operator';
        if (! $this->pending($user->email, $kind)) {
            return ['success' => false, 'message' => 'This invitation is unavailable. Ask the company owner to review the assignment.', 'status' => 422];
        }

        return app(OtpService::class)->sendOtp($user->email, self::PURPOSE, $user->id, $user->role);
    }

    public function verify(string $email, string $kind, string $code): array
    {
        return DB::transaction(function () use ($email, $kind, $code) {
            $user = $this->lockPending($email, $kind);
            if (! $user) {
                return ['success' => false, 'message' => 'The invitation or code is unavailable. Request a new code or contact your company owner.'];
            }
            $result = app(OtpService::class)->verifyOtp($user->email, $code, self::PURPOSE, $user->id, $user->role);
            if ($result['success']) {
                $assignment = DB::table('company_staff_provisions')->join('logistics_hubs', 'logistics_hubs.id', '=', 'company_staff_provisions.hub_id')
                    ->join('logistics_companies', 'logistics_companies.id', '=', 'company_staff_provisions.logistics_company_id')
                    ->where('company_staff_provisions.user_id', $user->id)->first(['logistics_companies.name as company', 'logistics_hubs.name as hub']);
                $result['assignment'] = $assignment;
                $result['assignment_token'] = $this->assignmentToken($user);
            }

            return $result;
        });
    }

    public function activate(string $email, string $kind, string $token, string $password, string $assignmentToken): User
    {
        return DB::transaction(function () use ($email, $kind, $token, $password, $assignmentToken) {
            $user = $this->lockPending($email, $kind);
            if (! $user) {
                throw ValidationException::withMessages(['token' => 'This invitation is no longer active. Ask the company owner to review it.']);
            }
            if (! hash_equals($this->assignmentToken($user), $assignmentToken)) {
                throw ValidationException::withMessages(['token' => 'Your assignment changed. Verify a new code and review the current assignment.']);
            }
            if (! $this->pending($email, $kind)
                || ! app(OtpService::class)->validateAndBurnToken($user->email, $token, self::PURPOSE, $user->id, $user->role)) {
                throw ValidationException::withMessages(['token' => 'The setup code expired or was already used. Request another code.']);
            }
            $user->forceFill(['password' => $password, 'must_change_password' => false,
                'temporary_password_expires_at' => null, 'email_verified_at' => now()])->save();
            DB::table('company_staff_provisions')->where('user_id', $user->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);
            app(AccountSessionService::class)->passwordChanged($user);

            return $user;
        });
    }

    /** Called inside the OTP transaction; use the same account/company/hub lock order as provisioning. */
    private function lockPending(string $email, string $kind): ?User
    {
        $candidate = $this->pending($email, $kind);
        if (! $candidate) {
            return null;
        }
        $snapshot = DB::table('company_staff_provisions')->where('user_id', $candidate->id)->first();
        try {
            app(LogisticsEligibilityService::class)->lockNetwork($candidate->provisioned_company_id, [$candidate->id], [$snapshot?->hub_id]);
        } catch (DomainException) {
            return null;
        }
        DB::table('company_staff_provisions')->where('user_id', $candidate->id)->lockForUpdate()->get();
        HubHandler::where('user_id', $candidate->id)->orderBy('id')->lockForUpdate()->get();
        $candidate->courierProfile()->lockForUpdate()->get();

        return $this->pending($email, $kind);
    }
}
