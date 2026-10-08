<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountEmail;
use App\Services\AccountEmailService;
use App\Services\OtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

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
            if (AccountEmail::where('email', $email)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'An account with this email already exists. Please sign in instead.',
                ], 422);
            }
        } elseif ($purpose === 'password_reset') {
            if (! app(AccountEmailService::class)->recoveryOwner($email)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No account found matching this email address.',
                ], 404);
            }
        }

        $result = $this->otpService->sendOtp($email, $purpose, $purpose === 'password_reset' ? app(AccountEmailService::class)->recoveryOwner($email)?->id : null);

        if (! $result['success']) {
            $status = $result['status'] ?? 429;
            unset($result['status']);

            return response()->json($result, $status);
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
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'purpose' => ['nullable', 'string', 'in:registration,password_reset'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = trim($validated['code']);
        $purpose = $validated['purpose'] ?? 'registration';

        $result = $this->otpService->verifyOtp($email, $code, $purpose, $purpose === 'password_reset' ? app(AccountEmailService::class)->recoveryOwner($email)?->id : null);

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
            'code' => ['nullable', 'string', 'regex:/\A[0-9]{6}\z/'],
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

        $owner = app(AccountEmailService::class)->recoveryOwner($email);
        if (! $owner) {
            throw ValidationException::withMessages(['email' => 'This address cannot recover an account.']);
        }
        if (! $token) {
            $result = $this->otpService->verifyOtp($email, $code, 'password_reset', $owner->id);
            if (! $result['success']) {
                throw ValidationException::withMessages(['code' => $result['message']]);
            }
            $token = $result['token'];
        }
        DB::transaction(function () use ($email, $token, $owner, $validated) {
            $user = app(AccountEmailService::class)->recoveryOwner($email, lock: true);
            if (! $user || $user->id !== $owner->id || ! $this->otpService->validateAndBurnToken($email, $token, 'password_reset', $user->id)) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired verification session. Request a new code.']);
            }
            $user->forceFill(['password' => $validated['password'], 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        }, 3);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. You may now sign in.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Password reset successfully. Please sign in with your new password.');
    }
}
