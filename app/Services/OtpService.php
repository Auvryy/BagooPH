<?php

namespace App\Services;

use App\Mail\OtpVerificationMail;
use App\Models\EmailOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OtpService
{
    /**
     * Generate and dispatch a 6-digit OTP code to the given email.
     */
    public function sendOtp(string $email, string $purpose = 'registration'): array
    {
        $normalizedEmail = strtolower(trim($email));

        // 1. Rate Limit Cooldown: check if a code was created within 60 seconds
        $recentOtp = EmailOtp::forEmail($normalizedEmail, $purpose)
            ->latest('created_at')
            ->first();

        if ($recentOtp && $recentOtp->created_at->diffInSeconds(now()) < 60) {
            $remaining = 60 - $recentOtp->created_at->diffInSeconds(now());
            return [
                'success' => false,
                'message' => "Please wait {$remaining} seconds before requesting a new code.",
                'cooldown' => $remaining,
            ];
        }

        // 2. Throttle Limit: max 5 requests per 15 minutes
        $fifteenMinutesAgo = now()->subMinutes(15);
        $requestCount = EmailOtp::forEmail($normalizedEmail, $purpose)
            ->where('created_at', '>=', $fifteenMinutesAgo)
            ->count();

        if ($requestCount >= 5) {
            return [
                'success' => false,
                'message' => 'Too many verification requests. Please try again in 15 minutes.',
                'cooldown' => 900,
            ];
        }

        // 3. Invalidate previous active unverified OTPs for this email and purpose
        EmailOtp::forEmail($normalizedEmail, $purpose)
            ->whereNull('verified_at')
            ->delete();

        // 4. Generate 6-digit numeric code
        $code = sprintf('%06d', random_int(100000, 999999));

        // 5. Store OTP record with 10-minute expiry
        EmailOtp::create([
            'email' => $normalizedEmail,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        // 6. Send branded HTML email via configured mailer (Resend SMTP)
        Mail::to($normalizedEmail)->send(new OtpVerificationMail($code, $purpose));

        return [
            'success' => true,
            'message' => 'Verification code sent to your email address.',
            'expires_in' => 600,
            'cooldown' => 60,
        ];
    }

    /**
     * Verify a submitted 6-digit OTP code.
     */
    public function verifyOtp(string $email, string $code, string $purpose = 'registration'): array
    {
        $normalizedEmail = strtolower(trim($email));
        $cleanCode = trim($code);

        // Find active unverified OTP
        $otp = EmailOtp::forEmail($normalizedEmail, $purpose)
            ->whereNull('verified_at')
            ->latest('created_at')
            ->first();

        if (! $otp || ! $otp->expires_at->isFuture()) {
            return [
                'success' => false,
                'message' => 'The verification code has expired or does not exist. Please request a new code.',
            ];
        }

        if ($otp->attempts >= 5) {
            return [
                'success' => false,
                'message' => 'Maximum verification attempts exceeded. Please request a new code.',
            ];
        }

        // Increment attempt counter
        $otp->increment('attempts');

        // Compare constant-time hash
        if (! Hash::check($cleanCode, $otp->code_hash)) {
            $remaining = max(0, 5 - $otp->attempts);
            return [
                'success' => false,
                'message' => $remaining > 0 
                    ? "Invalid verification code. {$remaining} attempt(s) remaining." 
                    : 'Maximum verification attempts reached. Please request a new code.',
            ];
        }

        // Code matches! Issue verification token
        $verificationToken = Str::random(40);
        $otp->update([
            'verified_at' => now(),
            'token' => $verificationToken,
        ]);

        return [
            'success' => true,
            'message' => 'Email verified successfully.',
            'token' => $verificationToken,
        ];
    }

    /**
     * Validate and burn a verification token to finalize registration or action.
     */
    public function validateAndBurnToken(string $email, string $token, string $purpose = 'registration'): bool
    {
        $normalizedEmail = strtolower(trim($email));

        $otp = EmailOtp::where('email', $normalizedEmail)
            ->where('token', $token)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subHours(2))
            ->first();

        if (! $otp) {
            return false;
        }

        // Single-use token: burn upon consumption
        $otp->update(['token' => null]);

        return true;
    }

    /**
     * Check if an email has an active valid verification token without burning it yet.
     */
    public function hasValidToken(string $email, string $token, string $purpose = 'registration'): bool
    {
        $normalizedEmail = strtolower(trim($email));

        return EmailOtp::where('email', $normalizedEmail)
            ->where('token', $token)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subHours(2))
            ->exists();
    }
}
