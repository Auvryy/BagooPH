<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class OtpVerificationController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Send a 6-digit OTP code to the provided email.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'purpose' => ['nullable', 'string', 'in:registration,password_reset'],
        ]);

        $email = strtolower(trim($validated['email']));
        $purpose = $validated['purpose'] ?? 'registration';

        // Validate account existence based on purpose
        if ($purpose === 'registration') {
            if (User::where('email', $email)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'An account with this email already exists. Please sign in instead.',
                ], 422);
            }
        } elseif ($purpose === 'password_reset') {
            if (! User::where('email', $email)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No account found matching this email address.',
                ], 404);
            }
        }

        $result = $this->otpService->sendOtp($email, $purpose);

        if (! $result['success']) {
            return response()->json($result, 429);
        }

        return response()->json($result);
    }

    /**
     * Verify a submitted 6-digit OTP code.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'purpose' => ['nullable', 'string', 'in:registration,password_reset'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = trim($validated['code']);
        $purpose = $validated['purpose'] ?? 'registration';

        $result = $this->otpService->verifyOtp($email, $code, $purpose);

        if (! $result['success']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    /**
     * Resend an OTP code enforcing cooldown timer.
     */
    public function resend(Request $request): JsonResponse
    {
        return $this->send($request);
    }

    /**
     * Reset account password directly using the verified OTP code.
     */
    public function resetPasswordWithOtp(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $email = strtolower(trim($validated['email']));
        $token = $validated['token'] ?? null;
        $code = isset($validated['code']) ? trim($validated['code']) : null;

        if (! $token && ! $code) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either verification code or token is required.',
                ], 422);
            }

            return back()->withErrors(['code' => 'Verification code is required.']);
        }

        if ($token) {
            $isValid = $this->otpService->validateAndBurnToken($email, $token, 'password_reset');
            if (! $isValid) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or expired verification session. Please request a new code.',
                    ], 422);
                }

                return back()->withErrors(['code' => 'Invalid or expired verification session. Please request a new code.']);
            }
        } else {
            // Verify the OTP code for password reset
            $verifyResult = $this->otpService->verifyOtp($email, $code, 'password_reset');

            if (! $verifyResult['success']) {
                if ($request->wantsJson()) {
                    return response()->json($verifyResult, 422);
                }

                return back()->withErrors(['code' => $verifyResult['message']]);
            }

            if (isset($verifyResult['token'])) {
                $this->otpService->validateAndBurnToken($email, $verifyResult['token'], 'password_reset');
            }
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No account found for this email.',
                ], 404);
            }

            return back()->withErrors(['email' => 'No account found for this email.']);
        }

        // Update password and burn token
        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        if (isset($verifyResult['token'])) {
            $this->otpService->validateAndBurnToken($email, $verifyResult['token'], 'password_reset');
        }

        event(new PasswordReset($user));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. You may now sign in.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Password reset successfully. Please sign in with your new password.');
    }
}
