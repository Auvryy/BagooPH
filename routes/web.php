<?php

use App\Http\Controllers\AccountEmailController;

use App\Http\Controllers\Admin\AccountClosureController;
use App\Http\Controllers\Admin\AccountRestrictionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminShopReviewController;
use App\Http\Controllers\Admin\LogisticsHubController;
use App\Http\Controllers\Admin\ProductModerationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Buyer\BuyerDisputeController;
use App\Http\Controllers\Buyer\BuyerHomeController;
use App\Http\Controllers\Buyer\BuyerProductController;
use App\Http\Controllers\Buyer\BuyerProfileController;
use App\Http\Controllers\Buyer\BuyerReviewController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\CustomerServiceAssistantController;
use App\Http\Controllers\Buyer\OrderHistoryController;
use App\Http\Controllers\Buyer\PickupClaimController;
use App\Http\Controllers\Buyer\VoucherController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Courier\CourierDeliveryController;
use App\Http\Controllers\ExceptionOversightController;
use App\Http\Controllers\Governance\ResourceRestrictionController;
use App\Http\Controllers\GovernanceHistoryController;
use App\Http\Controllers\IdentityCorrectionController;
use App\Http\Controllers\Logistics\DeliveryRecoveryController;
use App\Http\Controllers\Logistics\LogisticsHubWorkstationController;
use App\Http\Controllers\Logistics\LogisticsManifestController;
use App\Http\Controllers\Logistics\RestrictedCustodyRecoveryController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicTrackingController;
use App\Http\Controllers\Seller\SellerAiAssistantController;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerDisputeController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerReviewController;
/*
|--------------------------------------------------------------------------
| Subdomain Routing (bagooph.shop, seller.*, courier.*, hub.*, admin.*)
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Seller\SellerShopController;
use App\Http\Controllers\Seller\SellerVoucherController;
use App\Http\Controllers\ShopVerificationDocumentController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\VerificationDocumentController;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$baseDomains = array_unique(array_filter([
    env('APP_DOMAIN'),
    'bagooph.shop',
    'localhost',
]));

$registerResourceRestrictionRoutes = function () {
    Route::get('/resources', [ResourceRestrictionController::class, 'index'])->name('resources.index');
    Route::get('/resources/{type}/{resource}', [ResourceRestrictionController::class, 'show'])
        ->where('type', 'shop|company|hub|handler|fleet')->whereNumber('resource')->name('resources.show');
    Route::post('/resources/{type}/{resource}', [ResourceRestrictionController::class, 'store'])
        ->where('type', 'shop|company|hub|handler|fleet')->whereNumber('resource')->name('resources.store');
};

Route::middleware('auth')->get('/verification-documents/{user}/{document}', [VerificationDocumentController::class, 'show'])
    ->name('verification-documents.show');

Route::middleware('auth')->group(function () {
    Route::get('/exceptions', [ExceptionOversightController::class, 'index'])->name('exceptions.index');
    Route::get('/exceptions/attempt/{id}/proof', [ExceptionOversightController::class, 'proof'])->whereNumber('id')->name('exceptions.proof');
    Route::get('/exceptions/{kind}/{id}', [ExceptionOversightController::class, 'show'])->whereIn('kind', ['restriction', 'attempt', 'manifest', 'pickup'])->whereNumber('id')->name('exceptions.show');
    Route::post('/exceptions/{kind}/{id}', [ExceptionOversightController::class, 'decide'])->whereIn('kind', ['restriction', 'attempt', 'manifest', 'pickup'])->whereNumber('id')->name('exceptions.decide');
    Route::get('/account/identity-corrections', [IdentityCorrectionController::class, 'show'])->name('identity-corrections.own');
    Route::post('/account/emails/send', [AccountEmailController::class, 'send'])->middleware('throttle:10,1')->name('account.emails.send');
    Route::post('/account/emails/confirm', [AccountEmailController::class, 'confirm'])->middleware('throttle:20,1')->name('account.emails.confirm');
    Route::patch('/account/emails/{accountEmail}/preferred', [AccountEmailController::class, 'prefer'])->middleware('throttle:10,1')->name('account.emails.prefer');
    Route::delete('/account/emails/{accountEmail}', [AccountEmailController::class, 'destroy'])->middleware('throttle:10,1')->name('account.emails.destroy');
    Route::post('/account/identity-corrections', [IdentityCorrectionController::class, 'store'])->name('identity-corrections.store');
    Route::get('/identity-correction-documents/{correction}/{document}', [IdentityCorrectionController::class, 'document'])->name('identity-correction-documents.show');
});

Route::get('/custody-recovery/sign-in', [RestrictedCustodyRecoveryController::class, 'login'])->name('custody-recovery.login');
Route::post('/custody-recovery/sign-in', [RestrictedCustodyRecoveryController::class, 'signIn'])->middleware('throttle:10,1')->name('custody-recovery.sign-in');
Route::middleware(['auth', 'verified'])->prefix('custody-recovery')->name('custody-recovery.')->group(function () {
    Route::get('/', [RestrictedCustodyRecoveryController::class, 'own'])->name('own');
    Route::get('/receiving', [RestrictedCustodyRecoveryController::class, 'receiving'])->name('receiving');
    Route::get('/work/{work}', [RestrictedCustodyRecoveryController::class, 'review'])->name('review');
    Route::post('/work/{work}', [RestrictedCustodyRecoveryController::class, 'grant'])->name('grant');
    Route::post('/{grant}/handover', [RestrictedCustodyRecoveryController::class, 'acknowledge'])->name('acknowledge');
    Route::post('/{grant}/receipt', [RestrictedCustodyRecoveryController::class, 'receive'])->name('receive');
});

Route::get('/storage/kyc_documents/{path?}', fn () => abort(404))->where('path', '.*');
Route::middleware('auth')->get('/shop-verification-documents/{shop}/{document}', [ShopVerificationDocumentController::class, 'show'])->name('shop-verification-documents.show');

$registerSellerRoutes = function () {
    Route::get('/', function () {
        if (auth()->check() && auth()->user()->isSeller()) {
            return redirect('/dashboard');
        }

        return Inertia::render('Seller/Landing');
    });
    Route::get('/login', [AuthenticatedSessionController::class, 'createSeller']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'createSeller']);
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/pending-approval', [RegisteredUserController::class, 'pendingApproval']);
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::get('/seller/login', fn () => redirect('/login'));
    Route::get('/seller/register', fn () => redirect('/register'));

    Route::middleware(['auth', 'subdomain.role:seller'])->group(function () {
        Route::get('/dashboard', [SellerDashboardController::class, 'index']);
        Route::get('/products', [SellerProductController::class, 'index']);
        Route::post('/products', [SellerProductController::class, 'store']);
        Route::patch('/products/{product}/stock', [SellerProductController::class, 'updateStock']);
        Route::match(['put', 'post'], '/products/{product}', [SellerProductController::class, 'update']);
        Route::delete('/products/{product}', [SellerProductController::class, 'destroy']);
        Route::get('/orders', [SellerOrderController::class, 'index']);
        Route::post('/orders/{order}/accept', [SellerOrderController::class, 'accept']);
        Route::post('/orders/{order}/accept-and-pack', [SellerOrderController::class, 'acceptAndPack']);
        Route::post('/orders/{order}/pack', [SellerOrderController::class, 'pack']);
        Route::post('/orders/{order}/ready', [SellerOrderController::class, 'readyForPickup']);
        Route::post('/orders/{order}/handover', [SellerOrderController::class, 'handover']);
        Route::post('/orders/{order}/cancel', [SellerOrderController::class, 'cancel']);
        Route::post('/orders/{order}/return-receipt', [SellerOrderController::class, 'receiveReturn']);
        Route::post('/orders/batch-ready', [SellerOrderController::class, 'batchReady']);
        Route::get('/vouchers', [SellerVoucherController::class, 'index']);
        Route::post('/vouchers', [SellerVoucherController::class, 'store']);
        Route::patch('/vouchers/{voucher}/toggle', [SellerVoucherController::class, 'toggle']);
        Route::get('/messages', [ChatController::class, 'sellerInbox']);
        Route::post('/messages', [ChatController::class, 'sendMessage']);
        Route::post('/chat/send', [ChatController::class, 'sendMessage']);
        Route::get('/reviews', [SellerReviewController::class, 'index']);
        Route::post('/reviews/{review}/reply', [SellerReviewController::class, 'reply']);
        Route::get('/disputes', [SellerDisputeController::class, 'index']);
        Route::get('/reports', [SellerDashboardController::class, 'reports']);
        Route::get('/settings', [SellerDashboardController::class, 'settings']);
        Route::post('/settings', [SellerDashboardController::class, 'updateSettings']);
        Route::get('/profile', [SellerDashboardController::class, 'profile']);
        Route::post('/profile', [SellerDashboardController::class, 'updateProfile']);
        Route::get('/shops', [SellerShopController::class, 'index']);
        Route::post('/shops/{shop}/resubmit', [SellerShopController::class, 'resubmit']);
        Route::get('/preview', [SellerDashboardController::class, 'previewStorefront'])->name('preview');

        Route::get('/seller/dashboard', function (Request $request) {
            $qs = $request->getQueryString();

            return redirect('/dashboard'.($qs ? '?'.$qs : ''));
        });
        Route::get('/seller/orders', [SellerOrderController::class, 'index']);
    });
};

$registerCourierRoutes = function () {
    Route::get('/', function () {
        if (auth()->check() && auth()->user()->isCourier()) {
            return redirect('/deliveries');
        }

        return app(AuthenticatedSessionController::class)->createCourier();
    });
    Route::get('/login', [AuthenticatedSessionController::class, 'createCourier']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'createCourier']);
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/pending-approval', [RegisteredUserController::class, 'pendingApproval']);
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::get('/courier/login', fn () => redirect('/login'));
    Route::get('/courier/register', fn () => redirect('/register'));

    Route::middleware(['auth', 'subdomain.role:courier', 'courier.approved'])->group(function () {
        Route::get('/deliveries', [CourierDeliveryController::class, 'index']);
        Route::post('/deliveries/{delivery}/claim', [CourierDeliveryController::class, 'claim']);
        Route::patch('/deliveries/{delivery}/status', [CourierDeliveryController::class, 'updateStatus']);
        Route::get('/earnings', [CourierDeliveryController::class, 'earnings']);
        Route::get('/messages', [CourierDeliveryController::class, 'messages']);
        Route::post('/messages/send', [CourierDeliveryController::class, 'sendMessage']);
        Route::post('/messages/read', [CourierDeliveryController::class, 'acknowledgeMessages']);
        Route::get('/profile', [CourierDeliveryController::class, 'profile']);
        Route::patch('/profile/account', [CourierDeliveryController::class, 'updateProfile']);
        Route::put('/profile/password', [PasswordController::class, 'update']);
        Route::post('/profile/toggle-duty', [CourierDeliveryController::class, 'toggleDuty']);
        Route::get('/dashboard', fn () => redirect('/deliveries'));
        Route::get('/courier/deliveries', fn () => redirect('/deliveries'));
    });
};

$registerHubRoutes = function () use ($registerResourceRestrictionRoutes) {
    Route::get('/', function () {
        if (auth()->check() && (auth()->user()->isLogistics() || auth()->user()->isAdmin())) {
            return redirect('/dashboard');
        }

        return app(AuthenticatedSessionController::class)->createHub();
    });
    Route::get('/login', [AuthenticatedSessionController::class, 'createHub']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'createLogistics']);
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/pending-approval', [RegisteredUserController::class, 'pendingApproval']);
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::get('/hub/login', fn () => redirect('/login'));
    Route::get('/hub/register', fn () => redirect('/register'));

    Route::middleware(['auth', 'subdomain.role:logistics'])->group(function () use ($registerResourceRestrictionRoutes) {
        $registerResourceRestrictionRoutes();
        Route::get('/dashboard', [LogisticsHubWorkstationController::class, 'dashboard']);
        Route::get('/network', [LogisticsHubWorkstationController::class, 'network']);
        Route::get('/fleet', [LogisticsHubWorkstationController::class, 'fleet']);
        Route::get('/manifests', [LogisticsManifestController::class, 'index']);
        Route::get('/manifests/{manifest}', [LogisticsManifestController::class, 'show'])->whereNumber('manifest');
        Route::post('/manifests', [LogisticsManifestController::class, 'store']);
        Route::post('/manifests/{manifest}/{action}', [LogisticsManifestController::class, 'command'])
            ->whereNumber('manifest')->where('action', 'load|remove|seal|reopen|dispatch|receive|close|report-discrepancy|correct-discrepancy|resolve-discrepancy');
        Route::get('/deliveries', [LogisticsHubWorkstationController::class, 'deliveries']);
        Route::get('/delivery-recovery', [DeliveryRecoveryController::class, 'index']);
        Route::post('/delivery-recovery/{delivery}/retry', [DeliveryRecoveryController::class, 'approve']);
        Route::get('/delivery-attempts/{attempt}/proof', [DeliveryRecoveryController::class, 'proof']);
        Route::get('/counter', [LogisticsHubWorkstationController::class, 'counter']);
        Route::post('/counter/hours', [LogisticsHubWorkstationController::class, 'pickupHours']);
        Route::post('/switch-hub', [LogisticsHubWorkstationController::class, 'switchHub']);
        Route::post('/placements', [LogisticsHubWorkstationController::class, 'placeResource']);
        Route::get('/scan', [LogisticsHubWorkstationController::class, 'scanStation'])->name('logistics.scan.station');
        Route::post('/scan', [LogisticsHubWorkstationController::class, 'scanIntake']);
        Route::post('/sort', [LogisticsHubWorkstationController::class, 'sortBarangay']);
        Route::post('/deliveries/{delivery}/assign-rider', [LogisticsHubWorkstationController::class, 'assignRider']);
        Route::post('/release', [LogisticsHubWorkstationController::class, 'releasePickup']);
        Route::get('/roadmap', [LogisticsHubWorkstationController::class, 'roadmap']);
        Route::get('/hub', fn () => redirect('/dashboard'));
    });
};

$registerAdminRoutes = function () use ($registerResourceRestrictionRoutes) {
    Route::get('/', function () {
        if (auth()->check() && auth()->user()->isAdmin()) {
            return redirect('/dashboard');
        }

        return app(AuthenticatedSessionController::class)->createAdmin();
    });
    Route::get('/login', [AuthenticatedSessionController::class, 'createAdmin']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/admin/login', fn () => redirect('/login'));

    Route::middleware(['auth', 'subdomain.role:admin'])->group(function () use ($registerResourceRestrictionRoutes) {
        $registerResourceRestrictionRoutes();
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/users', [AdminDashboardController::class, 'users']);
        Route::get('/users/{userId}/context', [GovernanceHistoryController::class, 'account'])->whereNumber('userId');
        Route::get('/users/{user}/closure', [AccountClosureController::class, 'show'])->whereNumber('user');
        Route::post('/users/{user}/closure', [AccountClosureController::class, 'store'])->whereNumber('user');
        Route::get('/identity-corrections', [IdentityCorrectionController::class, 'index']);
        Route::get('/users/{user}/identity-corrections', [IdentityCorrectionController::class, 'show']);
        Route::post('/users/{user}/identity-corrections', [IdentityCorrectionController::class, 'store']);
        Route::post('/identity-corrections/{correction}/decision', [IdentityCorrectionController::class, 'decide']);
        Route::get('/users/{user}/activity', [AccountRestrictionController::class, 'show']);
        Route::post('/users/{user}/activity', [AccountRestrictionController::class, 'store']);
        Route::get('/shops', [AdminShopReviewController::class, 'index']);
        Route::post('/shops/{shop}/approve', [AdminShopReviewController::class, 'approve']);
        Route::post('/shops/{shop}/reject', [AdminShopReviewController::class, 'reject']);
        Route::get('/kyc', [AdminKycController::class, 'index']);
        Route::post('/kyc/{user}/approve', [AdminKycController::class, 'approve']);
        Route::post('/kyc/{user}/reject', [AdminKycController::class, 'reject']);
        Route::get('/products', [AdminDashboardController::class, 'products']);
        Route::get('/products/{product}/moderation', [ProductModerationController::class, 'show']);
        Route::post('/products/{product}/moderation', [ProductModerationController::class, 'store']);
        Route::get('/logistics', [LogisticsHubController::class, 'index']);
        Route::get('/admin/dashboard', fn () => redirect('/dashboard'));
    });
};

$registerBuyerDomainRoutes = function () {
    Route::get('/sitemap.xml', [SitemapController::class, 'index']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create']);
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create']);
    Route::post('/register', [RegisteredUserController::class, 'store']);
};

foreach ($baseDomains as $domain) {
    Route::domain("seller.{$domain}")->group($registerSellerRoutes);
    Route::domain("courier.{$domain}")->group($registerCourierRoutes);
    Route::domain("hub.{$domain}")->group($registerHubRoutes);
    Route::domain("admin.{$domain}")->group($registerAdminRoutes);
    Route::domain($domain)->group($registerBuyerDomainRoutes);
}

Route::get('/seller', function () {
    if (auth()->check() && auth()->user()->isSeller()) {
        return redirect()->route('seller.dashboard');
    }

    return Inertia::render('Seller/Landing');
})->name('seller.landing');

Route::get('/courier', function () {
    if (auth()->check() && auth()->user()->isCourier()) {
        return redirect()->route('courier.deliveries');
    }

    return app(AuthenticatedSessionController::class)->createCourier();
})->name('courier.landing');

Route::get('/logistics', function () {
    if (auth()->check() && (auth()->user()->isLogistics() || auth()->user()->isAdmin())) {
        return redirect()->route('hub.index');
    }

    return redirect()->route('logistics.register');
})->name('logistics.landing');

Route::get('/admin', function () {
    if (auth()->check() && auth()->user()->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    return app(AuthenticatedSessionController::class)->createAdmin();
})->name('admin.landing');

/*
|--------------------------------------------------------------------------
| Live Public Buyer Marketplace (Root /) & SEO Discovery
|--------------------------------------------------------------------------
*/
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/', [BuyerHomeController::class, 'index'])->name('marketplace');
Route::get('/overview', [MarketplaceController::class, 'index'])->name('overview');
Route::get('/about', [MarketplaceController::class, 'index'])->name('about');

/*
|--------------------------------------------------------------------------
| Universal Parcel Tracking Routes (Public & Role-Aware Operations)
|--------------------------------------------------------------------------
*/
Route::get('/track/{tracking_number?}', [PublicTrackingController::class, 'show'])->middleware('throttle:public-tracking')->name('track.show');

/*
|--------------------------------------------------------------------------
| Buyer E-Commerce Ecosystem Routes (/buyer)
|--------------------------------------------------------------------------
*/
Route::prefix('buyer')->name('buyer.')->group(function () {
    Route::get('/', [BuyerHomeController::class, 'index'])->name('index');
    Route::get('/home', fn () => redirect()->route('marketplace'));
    Route::get('/search', [BuyerProductController::class, 'search'])->name('search');
    Route::get('/catalog', [BuyerProductController::class, 'search'])->name('catalog');
    Route::get('/product/{slug}', [BuyerProductController::class, 'show'])->name('products.show');
    Route::get('/cart', [CartController::class, 'index'])->middleware('buyer.approved:optional')->name('cart');

    Route::middleware('auth')->group(function () {
        Route::middleware('buyer.approved')->group(function () {
            Route::get('/profile', [BuyerProfileController::class, 'index'])->name('profile');
            Route::post('/profile', [BuyerProfileController::class, 'update'])->name('profile.update');
            Route::post('/addresses', [BuyerProfileController::class, 'storeAddress'])->name('addresses.store');
            Route::post('/addresses/{address}/default', [BuyerProfileController::class, 'setDefaultAddress'])->name('addresses.default');
            Route::delete('/addresses/{address}', [BuyerProfileController::class, 'destroyAddress'])->name('addresses.destroy');
            Route::get('/messages', [ChatController::class, 'buyerInbox'])->name('messages');
            Route::get('/disputes', [BuyerDisputeController::class, 'index'])->name('disputes.index');
            Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
            Route::post('/reviews', [BuyerReviewController::class, 'store'])->name('reviews.store');
            Route::post('/vouchers/apply', [VoucherController::class, 'apply'])->name('vouchers.apply');
            Route::post('/support/assistant', [CustomerServiceAssistantController::class, 'respond'])
                ->middleware('throttle:20,1')
                ->name('support.assistant');
        });
        Route::post('/kyc/upload', [CheckoutController::class, 'uploadKycDocument'])->name('kyc.upload');
        Route::get('/orders', [OrderHistoryController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/confirm', [OrderHistoryController::class, 'confirmReceived'])->name('orders.confirm');
        Route::post('/orders/{order}/pickup-code', [PickupClaimController::class, 'issue'])->middleware('throttle:10,1')->name('orders.pickup-code');
        Route::patch('/order-notices/{notification}/read', [OrderHistoryController::class, 'readNotice'])->name('orders.notice-read');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
    Route::get('/governance-history', [GovernanceHistoryController::class, 'index'])->name('governance-history.index');
    Route::get('/governance-history/{source}/{decisionId}', [GovernanceHistoryController::class, 'show'])->whereNumber('decisionId')->name('governance-history.show');
});

// Backward-compatible Public Marketplace / Catalog routes
Route::get('/products', [BuyerProductController::class, 'search'])->name('products.index');
Route::get('/catalog', [BuyerProductController::class, 'search'])->name('catalog.index');
Route::get('/product/{slug}', [BuyerProductController::class, 'show'])->name('products.show');
Route::get('/shop/{slug}', [MarketplaceController::class, 'shop'])->name('shop.show');
Route::post('/shop/{slug}/update-branding', [MarketplaceController::class, 'updateBranding'])->middleware(['auth', 'role:seller'])->name('shop.updateBranding');

// Cart (Accessible to guests and logged in users)
Route::middleware('buyer.approved:optional')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('/cart/{cartItem}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');
});

/*
|--------------------------------------------------------------------------
| Authenticated Shared & Live Chat Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    // Universal Dashboard Redirector
    Route::get('/dashboard', function () {
        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login');
        }

        return redirect()->intended(match ($user->role) {
            'admin' => route('admin.dashboard'),
            'seller' => route('seller.dashboard'),
            'courier' => route('courier.deliveries'),
            'logistics' => route('hub.index'),
            default => route('buyer.index'),
        });
    })->middleware('account.approved')->name('dashboard');

    // Profile Settings
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Buyer Checkout & Orders
    Route::get('/checkout', [CheckoutController::class, 'index'])->middleware('buyer.approved')->name('checkout.index');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('buyer.approved')->name('checkout.store');
    Route::post('/checkout/kyc/upload', [CheckoutController::class, 'uploadKycDocument'])->name('checkout.kyc.upload');
    Route::get('/my-orders', [OrderHistoryController::class, 'index'])->name('orders.index');
    Route::get('/my-orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');

    // Live Chat / Messaging Endpoints
    Route::get('/messages', [ChatController::class, 'buyerInbox'])->middleware('buyer.approved')->name('messages');
    Route::get('/chat/messages/{receiverId}', [ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
});

/*
|--------------------------------------------------------------------------
| Seller Portal Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:seller'])->prefix('seller')->name('seller.')->group(function () {
    Route::get('/dashboard', [SellerDashboardController::class, 'index'])->name('dashboard');
    Route::get('/products', [SellerProductController::class, 'index'])->name('products.index');
    Route::post('/products', [SellerProductController::class, 'store'])->name('products.store');
    Route::post('/products/assist-description', [SellerAiAssistantController::class, 'generateDescription'])
        ->middleware('throttle:20,1')
        ->name('products.assist-description');
    Route::patch('/products/{product}/stock', [SellerProductController::class, 'updateStock'])->name('products.stock.update');
    Route::match(['put', 'post'], '/products/{product}', [SellerProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [SellerProductController::class, 'destroy'])->name('products.destroy');
    Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/{order}/accept', [SellerOrderController::class, 'accept'])->name('orders.accept');
    Route::post('/orders/{order}/accept-and-pack', [SellerOrderController::class, 'acceptAndPack'])->name('orders.acceptAndPack');
    Route::post('/orders/{order}/pack', [SellerOrderController::class, 'pack'])->name('orders.pack');
    Route::post('/orders/{order}/ready', [SellerOrderController::class, 'readyForPickup'])->name('orders.ready');
    Route::post('/orders/{order}/handover', [SellerOrderController::class, 'handover'])->name('orders.handover');
    Route::post('/orders/{order}/cancel', [SellerOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/return-receipt', [SellerOrderController::class, 'receiveReturn'])->name('orders.return-receipt');
    Route::post('/orders/batch-ready', [SellerOrderController::class, 'batchReady'])->name('orders.batchReady');
    Route::get('/vouchers', [SellerVoucherController::class, 'index'])->name('vouchers.index');
    Route::post('/vouchers', [SellerVoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}/toggle', [SellerVoucherController::class, 'toggle'])->name('vouchers.toggle');
    Route::delete('/vouchers/{voucher}', [SellerVoucherController::class, 'destroy'])->name('vouchers.destroy');
    Route::get('/messages', [ChatController::class, 'sellerInbox'])->name('messages.index');
    Route::get('/reviews', [SellerReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews/{review}/reply', [SellerReviewController::class, 'reply'])->name('reviews.reply');
    Route::get('/disputes', [SellerDisputeController::class, 'index'])->name('disputes.index');
    Route::get('/reports', [SellerDashboardController::class, 'reports'])->name('reports');
    Route::get('/settings', [SellerDashboardController::class, 'settings'])->name('settings');
    Route::post('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.update');
    Route::get('/profile', [SellerDashboardController::class, 'profile'])->name('profile');
    Route::post('/profile', [SellerDashboardController::class, 'updateProfile'])->name('profile.update');
    Route::get('/shops', [SellerShopController::class, 'index'])->name('shops.index');
    Route::post('/shops/{shop}/resubmit', [SellerShopController::class, 'resubmit'])->name('shops.resubmit');
    Route::get('/preview', [SellerDashboardController::class, 'previewStorefront'])->name('preview');
});

/*
|--------------------------------------------------------------------------
| Courier Portal Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'courier.approved'])->prefix('courier')->name('courier.')->group(function () {
    Route::get('/deliveries', [CourierDeliveryController::class, 'index'])->name('deliveries');
    Route::post('/deliveries/{delivery}/claim', [CourierDeliveryController::class, 'claim'])->name('claim');
    Route::patch('/deliveries/{delivery}/status', [CourierDeliveryController::class, 'updateStatus'])->name('updateStatus');
    Route::get('/earnings', [CourierDeliveryController::class, 'earnings'])->name('earnings');
    Route::get('/messages', [CourierDeliveryController::class, 'messages'])->name('messages');
    Route::post('/messages/send', [CourierDeliveryController::class, 'sendMessage'])->name('messages.send');
    Route::post('/messages/read', [CourierDeliveryController::class, 'acknowledgeMessages'])->name('messages.read');
    Route::get('/profile', [CourierDeliveryController::class, 'profile'])->name('profile');
    Route::patch('/profile/account', [CourierDeliveryController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [PasswordController::class, 'update'])->name('profile.password.update');
    Route::post('/profile/toggle-duty', [CourierDeliveryController::class, 'toggleDuty'])->name('toggleDuty');
});

/*
|--------------------------------------------------------------------------
| Admin Control Center Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () use ($registerResourceRestrictionRoutes) {
    $registerResourceRestrictionRoutes();
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [AdminDashboardController::class, 'users'])->name('users');
    Route::get('/users/{userId}/context', [GovernanceHistoryController::class, 'account'])->whereNumber('userId')->name('users.context');
    Route::get('/users/{user}/closure', [AccountClosureController::class, 'show'])->whereNumber('user')->name('users.closure');
    Route::post('/users/{user}/closure', [AccountClosureController::class, 'store'])->whereNumber('user')->name('users.closure.store');
    Route::get('/users/{user}/activity', [AccountRestrictionController::class, 'show'])->name('users.activity');
    Route::get('/identity-corrections', [IdentityCorrectionController::class, 'index'])->name('identity-corrections.index');
    Route::get('/users/{user}/identity-corrections', [IdentityCorrectionController::class, 'show'])->name('identity-corrections.show');
    Route::post('/users/{user}/identity-corrections', [IdentityCorrectionController::class, 'store'])->name('identity-corrections.submit');
    Route::post('/identity-corrections/{correction}/decision', [IdentityCorrectionController::class, 'decide'])->name('identity-corrections.decide');
    Route::post('/users/{user}/activity', [AccountRestrictionController::class, 'store'])->name('users.activity.store');
    Route::get('/shops', [AdminShopReviewController::class, 'index'])->name('shops.index');
    Route::post('/shops/{shop}/approve', [AdminShopReviewController::class, 'approve'])->name('shops.approve');
    Route::post('/shops/{shop}/reject', [AdminShopReviewController::class, 'reject'])->name('shops.reject');
    Route::get('/kyc', [AdminKycController::class, 'index'])->name('kyc.index');
    Route::post('/kyc/{user}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
    Route::post('/kyc/{user}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');
    Route::get('/products', [AdminDashboardController::class, 'products'])->name('products');
    Route::get('/products/{product}/moderation', [ProductModerationController::class, 'show'])->name('products.moderation');
    Route::post('/products/{product}/moderation', [ProductModerationController::class, 'store'])->name('products.moderation.store');
    Route::get('/logistics', [LogisticsHubController::class, 'index'])->name('logistics');
});

/*
|--------------------------------------------------------------------------
| Logistics Hub Workstation Routes
|--------------------------------------------------------------------------
*/
Route::prefix('hub')->name('hub.')->group(function () use ($registerResourceRestrictionRoutes) {
    Route::get('/', function (Request $request) {
        $user = auth()->user();
        if ($user) {
            return app(RoleMiddleware::class)->handle(
                $request,
                fn (Request $request) => app(LogisticsHubWorkstationController::class)->index($request)->toResponse($request),
                'logistics', 'admin',
            );
        }

        return app(AuthenticatedSessionController::class)->createHub();
    })->name('index');

    Route::middleware(['auth', 'role:logistics,admin'])->group(function () use ($registerResourceRestrictionRoutes) {
        $registerResourceRestrictionRoutes();
        Route::get('/dashboard', [LogisticsHubWorkstationController::class, 'dashboard'])->name('dashboard');
        Route::get('/network', [LogisticsHubWorkstationController::class, 'network'])->name('network');
        Route::get('/fleet', [LogisticsHubWorkstationController::class, 'fleet'])->name('fleet');
        Route::get('/manifests', [LogisticsManifestController::class, 'index'])->name('manifests.index');
        Route::get('/manifests/{manifest}', [LogisticsManifestController::class, 'show'])->whereNumber('manifest')->name('manifests.show');
        Route::post('/manifests', [LogisticsManifestController::class, 'store'])->name('manifests.store');
        Route::post('/manifests/{manifest}/{action}', [LogisticsManifestController::class, 'command'])
            ->whereNumber('manifest')->where('action', 'load|remove|seal|reopen|dispatch|receive|close|report-discrepancy|correct-discrepancy|resolve-discrepancy')->name('manifests.command');
        Route::get('/deliveries', [LogisticsHubWorkstationController::class, 'deliveries'])->name('deliveries');
        Route::get('/delivery-recovery', [DeliveryRecoveryController::class, 'index'])->name('recovery.index');
        Route::post('/delivery-recovery/{delivery}/retry', [DeliveryRecoveryController::class, 'approve'])->name('recovery.retry');
        Route::get('/delivery-attempts/{attempt}/proof', [DeliveryRecoveryController::class, 'proof'])->name('recovery.proof');
        Route::get('/counter', [LogisticsHubWorkstationController::class, 'counter'])->name('counter');
        Route::post('/counter/hours', [LogisticsHubWorkstationController::class, 'pickupHours'])->name('counter.hours');
        Route::post('/switch-hub', [LogisticsHubWorkstationController::class, 'switchHub'])->name('switchHub');
        Route::post('/placements', [LogisticsHubWorkstationController::class, 'placeResource'])->name('placements');
        Route::get('/scan', [LogisticsHubWorkstationController::class, 'scanStation'])->name('scan.station');
        Route::post('/scan', [LogisticsHubWorkstationController::class, 'scanIntake'])->name('scan');
        Route::post('/sort', [LogisticsHubWorkstationController::class, 'sortBarangay'])->name('sort');
        Route::post('/deliveries/{delivery}/assign-rider', [LogisticsHubWorkstationController::class, 'assignRider'])->name('assignRider');
        Route::post('/release', [LogisticsHubWorkstationController::class, 'releasePickup'])->name('release');
        Route::get('/roadmap', [LogisticsHubWorkstationController::class, 'roadmap'])->name('roadmap');
    });
});

require __DIR__.'/auth.php';
