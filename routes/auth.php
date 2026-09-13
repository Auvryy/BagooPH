<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OtpVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::get('seller/register', [RegisteredUserController::class, 'createSeller'])
        ->name('seller.register');

    Route::get('courier/register', [RegisteredUserController::class, 'createCourier'])
        ->name('courier.register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::get('seller/login', [AuthenticatedSessionController::class, 'createSeller'])
        ->name('seller.login');

    Route::get('courier/login', [AuthenticatedSessionController::class, 'createCourier'])
        ->name('courier.login');

    Route::get('admin/login', [AuthenticatedSessionController::class, 'createAdmin'])
        ->name('admin.login');

    Route::get('hub/login', [AuthenticatedSessionController::class, 'createHub'])
        ->name('hub.login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    // Email OTP Verification Endpoints
    Route::post('otp/send', [OtpVerificationController::class, 'send'])->name('otp.send');
    Route::post('otp/verify', [OtpVerificationController::class, 'verify'])->name('otp.verify');
    Route::post('otp/resend', [OtpVerificationController::class, 'resend'])->name('otp.resend');
    Route::post('password/reset-otp', [OtpVerificationController::class, 'resetPasswordWithOtp'])->name('password.reset.otp');

    Route::post('api/otp/send', [OtpVerificationController::class, 'send'])->name('api.otp.send');
    Route::post('api/otp/verify', [OtpVerificationController::class, 'verify'])->name('api.otp.verify');
    Route::post('api/otp/resend', [OtpVerificationController::class, 'resend'])->name('api.otp.resend');
    Route::post('api/password/reset-otp', [OtpVerificationController::class, 'resetPasswordWithOtp'])->name('api.password.reset.otp');
});

Route::middleware('auth')->group(function () {
    Route::get('pending-approval', [RegisteredUserController::class, 'pendingApproval'])
        ->name('kyc.pending');

    Route::post('kyc/resubmit', [RegisteredUserController::class, 'resubmitKyc'])
        ->name('kyc.resubmit');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
