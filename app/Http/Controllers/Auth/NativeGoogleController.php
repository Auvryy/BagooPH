<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\BirthDateEligibility;
use App\Services\BuyerMobile\GoogleIdTokenVerifier;
use App\Services\BuyerMobile\NativeGoogleConfiguration;
use App\Services\BuyerMobile\NativeGoogleSignInService;
use App\Services\GoogleRegistrationService;
use App\Services\RegistrationPolicyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeGoogleController extends Controller
{
    public function exchange(Request $request, NativeGoogleConfiguration $configuration, GoogleIdTokenVerifier $verifier, NativeGoogleSignInService $signIn): JsonResponse
    {
        $this->available($request, $configuration);
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:16384'], 'remember' => ['sometimes', 'boolean'],
            'account_password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json($signIn->exchange($request, $verifier->verify($data['id_token']), (bool) ($data['remember'] ?? false)));
    }

    public function registration(Request $request, NativeGoogleConfiguration $configuration, GoogleRegistrationService $registration): JsonResponse
    {
        $this->available($request, $configuration, guest: true);
        $pending = $registration->pending($request, GoogleRegistrationService::NATIVE_PENDING);

        return response()->json([
            'identity' => array_intersect_key($pending['identity'], array_flip(['name', 'email'])),
            'registrationNonce' => $pending['nonce'], 'expiresAt' => $pending['expires_at'],
            'birthDateLimits' => app(BirthDateEligibility::class)->limits(), 'policies' => app(RegistrationPolicyService::class)->contract(),
        ]);
    }

    public function register(Request $request, NativeGoogleConfiguration $configuration, GoogleRegistrationService $registration, NativeGoogleSignInService $signIn): JsonResponse
    {
        $this->available($request, $configuration, guest: true);
        $pending = $registration->pending($request, GoogleRegistrationService::NATIVE_PENDING);
        $user = $registration->register($request, GoogleRegistrationService::NATIVE_PENDING);
        $signIn->authenticate($request, $user, ($pending['remember'] ?? false) === true);

        return response()->json(['authenticated' => true]);
    }

    private function available(Request $request, NativeGoogleConfiguration $configuration, bool $guest = false): void
    {
        abort_unless($configuration->ready(), 503, 'Google sign-in is unavailable. Use email sign-in.');
        abort_if($request->user() && ! $request->user()->isBuyer(), 403, 'Use buyer Google sign-in from a buyer session.');
        if ($guest && $request->user()) {
            throw new HttpResponseException(response()->json([
                'code' => 'ALREADY_AUTHENTICATED', 'message' => 'You are already signed in. Check your current session before continuing.',
            ], 409));
        }
    }
}
