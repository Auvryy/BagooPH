<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountRegistrationService;
use App\Services\ApplicationValidationService;
use App\Services\BirthDateEligibility;
use App\Services\OtpService;
use App\Services\RiderAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class RiderAuthController extends Controller
{
    private function denyManagedInputs(Request $request): void
    {
        $request->validate(array_fill_keys(['role', 'status', 'kyc_status', 'user_id', 'hub_id', 'assigned_hub_id', 'company_id', 'logistics_company_id', 'email_verified_at', 'is_available'], 'prohibited'));
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
            'vehicle_types' => ApplicationValidationService::VEHICLES, 'document_max_bytes' => 5 * 1024 * 1024,
            'document_types' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 'email_verification_required' => true]]);
    }

    public function sendCode(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:255']);
        if (User::whereRaw('LOWER(email) = LOWER(?)', [$data['email']])->exists()) {
            return response()->json(['message' => 'An account with this email already exists. Please sign in instead.'], 422);
        }
        $result = $otp->sendOtp($data['email'], 'registration');
        $status = $result['success'] ? 200 : ($result['status'] ?? 429);
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function verifyCode(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:255', 'code' => 'required|string|regex:/^[0-9]{6}$/']);
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
