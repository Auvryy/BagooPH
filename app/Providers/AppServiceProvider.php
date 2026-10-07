<?php

namespace App\Providers;

use App\Models\CustodyRecoveryGrant;
use App\Models\CustodyRecoveryReceipt;
use App\Models\ExceptionDecision;
use App\Models\IdentityCorrectionDecision;
use App\Models\KycDecision;
use App\Models\LogisticsPlacementRecord;
use App\Models\ProductModerationDecision;
use App\Models\RestrictionDecision;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\Notifications\GovernanceNoticeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([KycDecision::class, ShopReviewDecision::class, RestrictionDecision::class,
            IdentityCorrectionDecision::class, ProductModerationDecision::class,
            LogisticsPlacementRecord::class, ExceptionDecision::class,
            CustodyRecoveryGrant::class, CustodyRecoveryReceipt::class] as $model) {
            $model::created(fn ($event) => app(GovernanceNoticeService::class)->record($event));
        }

        RateLimiter::for('rider-api', fn (Request $request) => Limit::perMinute(30)->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('rider-login', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (is_string($request->input('email')) ? strtolower($request->input('email')) : '').'|'.$request->ip())));
        Gate::define('buyer.existing-orders', fn (User $user) => app(BuyerAccessService::class)->hasExistingOrderEligibility($user));

        RateLimiter::for('public-tracking', fn (Request $request) => Limit::perMinute(30)
            ->by(hash('sha256', (string) $request->ip())));

        Vite::prefetch(concurrency: 3);

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
