<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\RegistrationDocument;
use App\Rules\UniqueAccountEmail;
use App\Services\AccountRegistrationService;
use App\Services\ApplicationValidationService;
use App\Services\BirthDateEligibility;
use App\Services\OtpService;
use App\Services\RegistrationPolicyService;
use App\Services\RiderAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class RiderAuthController extends Controller
{
    private function denyManagedInputs(Request $request): void
    {
        $request->validate(array_fill_keys(['role', 'status', 'kyc_status', 'user_id', 'shop_id', 'vehicle_id', 'hub_id', 'assigned_hub_id', 'company_id', 'logistics_company_id', 'email_verified_at', 'is_available'], 'prohibited'));
    }

    public function login(Request $request, RiderAccountService $accounts): JsonResponse
    {
        $this->denyManagedInputs($request);
        $data = $request->validate(['email' => 'required|string|email|max:255', 'password' => 'required|string|max:4096', 'device_name' => 'required|string|max:80']);
        $user = User::whereRaw('LOWER(email) = LOWER(?)', [trim($data['email'])])->first();
        if (! $user || ! Hash::check($data['password'], $user->getAuthPassword())) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        return response()->json(['data' => $accounts->session($user, $data['device_name'])]);
    }

    public function me(Request $request, RiderAccountService $accounts): JsonResponse
    {
        return response()->json(['data' => $accounts->resource($request->user()->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $request->bearerToken() || ! $token instanceof PersonalAccessToken || ! $token->can('rider:logout')) {
            return response()->json(['message' => 'A rider session is required.'], 401);
        }
        $token->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['birth_date_limits' => app(BirthDateEligibility::class)->limits(),
            'vehicle_types' => ApplicationValidationService::VEHICLES, 'document_max_bytes' => RegistrationDocument::MAX_BYTES,
            'document_types' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 'email_verification_required' => true,
            'password_policy' => ['min_length' => 8, 'max_length' => 128, 'uppercase' => true, 'lowercase' => true, 'number' => true, 'symbol' => true],
            'mobile_number_format' => '+639XXXXXXXXX', 'mobile_number_unique' => true,
            'registration_policies' => app(RegistrationPolicyService::class)->contract(),
            'plate_number_max_length' => 8, 'plate_number_max_symbols' => 7,
            'license_number_format' => 'N01-12-345678', 'license_number_max_length' => 13, 'license_number_compact_length' => 11,
            'sex_options' => ['Female', 'Male', 'Other'], 'sex_required' => true,
            'postal_code_length' => 4, 'postal_code_required' => false]]);
    }

    public function sendCode(Request $request, OtpService $otp): JsonResponse
    {
        $request->merge(app(ApplicationValidationService::class)->normalize($request->only('email'), 'buyer'));
        $data = $request->validate(['email' => ApplicationValidationService::emailRules()]);
        if (UniqueAccountEmail::taken($data['email'])) {
            return response()->json(['message' => 'An account with this email already exists. Please sign in instead.'], 422);
        }
        $result = $otp->sendOtp($data['email'], 'registration');
        $status = $result['success'] ? 200 : ($result['status'] ?? 429);
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function verifyCode(Request $request, OtpService $otp): JsonResponse
    {
        $request->merge(app(ApplicationValidationService::class)->normalize($request->only('email'), 'buyer'));
        $data = $request->validate(['email' => ApplicationValidationService::emailRules(), 'code' => 'required|string|regex:/\A[0-9]{6}\z/']);
        $result = $otp->verifyOtp($data['email'], $data['code'], 'registration');

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function register(Request $request, AccountRegistrationService $registration, RiderAccountService $accounts): JsonResponse
    {
        $this->denyManagedInputs($request);
        $request->validate(['otp_token' => 'required|string|max:128', 'device_name' => 'required|string|max:80']);
        $result = $registration->submit($request, 'courier', true);

        return response()->json(['data' => $accounts->session($result['user'], $request->input('device_name')),
            'verification_email_sent' => $result['verification_email_sent']], 201);
    }
}
