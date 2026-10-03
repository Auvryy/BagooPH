<?php

use App\Http\Controllers\PublicTrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for Mobile & External Client Applications
|--------------------------------------------------------------------------
*/

Route::get('/track/{tracking_number}', [PublicTrackingController::class, 'apiTrack'])->middleware('throttle:public-tracking')->name('api.track.show');
