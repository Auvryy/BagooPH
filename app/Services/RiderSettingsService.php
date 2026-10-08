<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiderSettingsService
{
    public function __construct(private AccountSettingsService $settings, private AccountEmailService $emails) {}

    public function snapshot(Request $request): array
    {
        return DB::transaction(fn () => $this->presentation($this->settings->actor($request, lock: true)), 3);
    }

    private function revision(User $user): string
    {
        return hash_hmac('sha256', json_encode([$user->id, $user->contact_settings_version, $user->identity_version,
            $user->name, $user->email, $user->phone], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function presentation(User $user): array
    {
        $user->load('courierProfile.company', 'courierProfile.hub', 'courierProfile.vehicle');
        $profile = $user->courierProfile;
        $addresses = $this->emails->presentation($user)['addresses'];

        return ['account_id' => (string) $user->id, 'revision' => $this->revision($user),
            'profile' => ['name' => $user->name, 'email' => $user->email, 'email_verified' => $user->hasVerifiedEmail(), 'phone' => $user->phone],
            'capabilities' => ['update_contact' => $user->currentAccessToken()->can('rider:settings:profile'),
                'change_password' => $user->hasVerifiedEmail() && $user->currentAccessToken()->can('rider:settings:password'),
                'manage_emails' => $user->currentAccessToken()->can('rider:settings:emails')],
            'emails' => array_map(fn ($address) => [...$address, 'id' => (string) $address['id']], $addresses),
            'managed_details' => ['available' => $profile !== null, 'company' => $profile?->company?->name,
                'hub' => $profile?->hub?->name, 'hub_code' => $profile?->hub?->code, 'barangay' => $profile?->assigned_barangay,
                'vehicle_type' => $profile?->vehicle?->vehicle_type ?? $profile?->vehicle_type, 'vehicle_model' => $profile?->vehicle?->model,
                'plate_number' => $profile?->vehicle?->plate_number ?? $profile?->plate_number,
                'fleet_status' => $profile?->vehicle?->status, 'license_number' => $profile?->license_number,
                'registration_status' => $profile?->or_cr_status]];
    }

    public function updateContact(Request $request): array
    {
        $request->validate(['phone' => 'present|nullable|string', 'revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);

        return DB::transaction(function () use ($request) {
            $user = $this->settings->actor($request, lock: true);
            if (! hash_equals($this->revision($user), $request->input('revision'))) {
                throw new HttpResponseException(response()->json(['message' => 'Your contact details changed. Reload settings before saving.',
                    'data' => $this->presentation($user)], 409));
            }
            $values = app(ProfileInputService::class)->validate($request, ['phone']);
            app(IdentityCorrectionService::class)->protectReviewedIdentity($user, $values);
            $user->update(['phone' => $values['phone'] ?? null]);
            $user->refresh();

            return $this->presentation($user);
        }, 3);
    }
}
