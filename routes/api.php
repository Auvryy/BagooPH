<?php

use App\Http\Controllers\Api\RiderAuthController;
use App\Http\Controllers\Api\RiderMessagingController;
use App\Http\Controllers\Api\RiderNotificationController;
use App\Http\Controllers\Api\RiderOperationsController;
use App\Http\Controllers\Api\RiderParcelController;
use App\Http\Controllers\Api\RiderSettingsController;
use App\Http\Controllers\Api\RiderTripController;
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
    Route::prefix('rider')->group(function () {
        Route::middleware(EnsureRiderAccountToken::class.':operations:read')->group(function () {
            Route::get('home', [RiderOperationsController::class, 'home']);
            Route::get('pickup-jobs', [RiderOperationsController::class, 'available']);
            Route::get('tasks', [RiderOperationsController::class, 'index']);
            Route::get('tasks/{task}', [RiderOperationsController::class, 'show']);
            Route::get('commands/{key}', [RiderOperationsController::class, 'command']);
            Route::get('trips', [RiderTripController::class, 'index'])->name('rider.api.trips');
            Route::get('trips/{trip}', [RiderTripController::class, 'show']);
            Route::get('trips/{trip}/checkpoints/{checkpoint}/proof', [RiderTripController::class, 'checkpointProof']);
            Route::get('trips/{trip}/attempts/{attempt}/proof', [RiderTripController::class, 'attemptProof']);
            Route::get('conversations', [RiderMessagingController::class, 'index'])->name('rider.api.conversations');
            Route::get('conversations/{thread}/messages', [RiderMessagingController::class, 'show']);
            Route::get('notifications', [RiderNotificationController::class, 'index'])->name('rider.api.notifications');
        });
        Route::middleware([EnsureRiderAccountToken::class.':operations:messages', 'throttle:10,1'])->group(function () {
            Route::post('conversations/{thread}/messages', [RiderMessagingController::class, 'send']);
            Route::post('conversations/{thread}/read', [RiderMessagingController::class, 'read']);
        });
        Route::middleware([EnsureRiderAccountToken::class.':operations:notifications', 'throttle:10,1'])->group(function () {
            Route::post('notifications/read-through', [RiderNotificationController::class, 'readThrough']);
            Route::post('notifications/{notice}/read', [RiderNotificationController::class, 'read']);
        });
        Route::middleware([EnsureRiderAccountToken::class.':operations:work', 'throttle:10,1'])->group(function () {
            Route::patch('duty', [RiderOperationsController::class, 'duty']);
            Route::post('pickup-jobs/{job}/claim', [RiderOperationsController::class, 'claim']);
            Route::post('tasks/{task}/pickup', [RiderParcelController::class, 'pickup'])->name('rider.api.pickup');
            Route::post('tasks/{task}/depart', [RiderParcelController::class, 'depart'])->name('rider.api.depart');
            Route::post('tasks/{task}/deliver', [RiderParcelController::class, 'deliver'])->name('rider.api.deliver');
            Route::post('tasks/{task}/fail', [RiderParcelController::class, 'fail'])->name('rider.api.fail');
        });
    });
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
