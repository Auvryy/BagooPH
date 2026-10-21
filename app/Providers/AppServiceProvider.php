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
use App\Services\AccountSessions\AccountSessionService;
use App\Services\BuyerAccessService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Notifications\GovernanceNoticeService;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(HandoverHoldService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, fn (Login $event) => app(AccountSessionService::class)->pendingLogin($event));
        foreach ([Logout::class, CurrentDeviceLogout::class] as $event) {
            Event::listen($event, function ($event) {
                if ($event->guard === 'web' && $event->user instanceof User) {
                    app(AccountSessionService::class)->logoutWeb(request(), $event->user);
                }
            });
        }
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => app(AccountSessionService::class)->passwordChanged($event->user));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => route('password.reset', [
            'token' => $token, 'email' => $user->email, 'role' => $user->role,
        ]));
        Password::defaults(fn () => Password::min(8)->max(128)->mixedCase()->numbers()->symbols()->rules(['regex:/[\p{S}\p{P}]/u', 'not_regex:/\p{C}/u']));

        RateLimiter::for('web-otp-send', fn (Request $request) => Limit::perMinute(10)->by(hash('sha256', 'otp-send|'.$request->ip())));
        RateLimiter::for('web-otp-verify', fn (Request $request) => Limit::perMinute(30)->by(hash('sha256', 'otp-verify|'.$request->ip())));

        foreach ([KycDecision::class, ShopReviewDecision::class, RestrictionDecision::class,
            IdentityCorrectionDecision::class, ProductModerationDecision::class,
            LogisticsPlacementRecord::class, ExceptionDecision::class,
            CustodyRecoveryGrant::class, CustodyRecoveryReceipt::class] as $model) {
            $model::created(fn ($event) => app(GovernanceNoticeService::class)->record($event));
        }

        RateLimiter::for('rider-api', fn (Request $request) => Limit::perMinute(30)->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('rider-login', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (is_string($request->input('email')) ? strtolower($request->input('email')) : '').'|'.$request->ip())));
        Gate::define('buyer.existing-orders', fn (User $user) => app(BuyerAccessService::class)->hasExistingOrderEligibility($user));
        Gate::define('buyer.order-history', fn (User $user) => app(BuyerAccessService::class)->canViewHolding($user));

        RateLimiter::for('public-tracking', fn (Request $request) => Limit::perMinute(30)
            ->by(hash('sha256', (string) $request->ip())));

        RateLimiter::for('advertisement-events', fn (Request $request) => Limit::perMinute(60)
            ->by(hash('sha256', 'advertisement-events|'.$request->ip())));

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
