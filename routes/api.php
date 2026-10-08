<?php

use App\Http\Controllers\Api\RiderAuthController;
use App\Http\Controllers\Api\RiderSettingsController;
use App\Http\Controllers\PublicTrackingController;
use App\Http\Middleware\EnsureRiderAccountToken;
use App\Http\Middleware\PrivateRiderResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for Mobile & External Client Applications
|--------------------------------------------------------------------------
*/

Route::get('/track/{tracking_number}', [PublicTrackingController::class, 'apiTrack'])->middleware('throttle:public-tracking')->name('api.track.show');

Route::prefix('v1')->middleware([PrivateRiderResponse::class, 'throttle:rider-api'])->group(function () {
    Route::post('auth/tokens', [RiderAuthController::class, 'login'])->middleware('throttle:rider-login');
    Route::get('rider/registration/options', [RiderAuthController::class, 'options']);
    Route::post('rider/registration/email/send', [RiderAuthController::class, 'sendCode']);
    Route::post('rider/registration/email/verify', [RiderAuthController::class, 'verifyCode']);
    Route::post('rider/applications', [RiderAuthController::class, 'register']);
    Route::delete('auth/tokens/current', [RiderAuthController::class, 'logout'])->middleware(EnsureRiderAccountToken::class.':logout');
    Route::get('rider/me', [RiderAuthController::class, 'me'])->middleware(EnsureRiderAccountToken::class);
    Route::prefix('rider/settings')->group(function () {
        Route::get('/', [RiderSettingsController::class, 'show'])->middleware(EnsureRiderAccountToken::class.':settings:read');
        Route::patch('profile', [RiderSettingsController::class, 'profile'])->middleware([EnsureRiderAccountToken::class.':settings:profile', 'throttle:10,1']);
        Route::put('password', [RiderSettingsController::class, 'password'])->middleware([EnsureRiderAccountToken::class.':settings:password', 'throttle:10,1']);
        Route::post('emails/send', [RiderSettingsController::class, 'send'])->middleware([EnsureRiderAccountToken::class.':settings:emails', 'throttle:10,1']);
        Route::post('emails/confirm', [RiderSettingsController::class, 'confirm'])->middleware([EnsureRiderAccountToken::class.':settings:emails', 'throttle:20,1']);
        Route::patch('emails/{id}/preferred', [RiderSettingsController::class, 'prefer'])->where('id', '[1-9][0-9]*')->middleware([EnsureRiderAccountToken::class.':settings:emails', 'throttle:10,1']);
        Route::delete('emails/{id}', [RiderSettingsController::class, 'destroy'])->where('id', '[1-9][0-9]*')->middleware([EnsureRiderAccountToken::class.':settings:emails', 'throttle:10,1']);
    });
});
