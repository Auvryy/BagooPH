<?php

use App\Http\Controllers\Api\RiderAuthController;
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
});
