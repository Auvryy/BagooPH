<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\LogisticsHubController;
use App\Http\Controllers\Buyer\BuyerDisputeController;
use App\Http\Controllers\Buyer\BuyerHomeController;
use App\Http\Controllers\Buyer\BuyerProductController;
use App\Http\Controllers\Buyer\BuyerProfileController;
use App\Http\Controllers\Buyer\BuyerReviewController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\OrderHistoryController;
use App\Http\Controllers\Buyer\VoucherController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Courier\CourierDeliveryController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Logistics\LogisticsHubWorkstationController;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerDisputeController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerReviewController;
use App\Http\Controllers\Seller\SellerVoucherController;
use App\Http\Controllers\Simulation\OrderSimulationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Subdomain Routing (seller.bagooph.shop, courier.bagooph.shop, admin.bagooph.shop)
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Auth\AuthenticatedSessionController;

$appHost = parse_url(config('app.url', 'https://bagooph.shop'), PHP_URL_HOST) ?? 'bagooph.shop';

if ($appHost !== 'localhost' && !str_contains($appHost, '127.0.0.1')) {
    Route::domain("seller.{$appHost}")->group(function () {
        Route::get('/', function () {
            if (auth()->check() && auth()->user()->isSeller()) {
                return redirect()->route('seller.dashboard');
            }
            return app(AuthenticatedSessionController::class)->createSeller();
        });
        Route::get('/login', fn() => redirect('/'));
    });

    Route::domain("courier.{$appHost}")->group(function () {
        Route::get('/', function () {
            if (auth()->check() && auth()->user()->isCourier()) {
                return redirect()->route('courier.deliveries');
            }
            return app(AuthenticatedSessionController::class)->createCourier();
        });
        Route::get('/login', fn() => redirect('/'));
    });

    Route::domain("admin.{$appHost}")->group(function () {
        Route::get('/', function () {
            if (auth()->check() && auth()->user()->isAdmin()) {
                return redirect()->route('admin.dashboard');
            }
            return app(AuthenticatedSessionController::class)->createAdmin();
        });
        Route::get('/login', fn() => redirect('/'));
    });
}

/*
|--------------------------------------------------------------------------
| Live Public Buyer Marketplace (Root /)
|--------------------------------------------------------------------------
*/
Route::get('/', [BuyerHomeController::class, 'index'])->name('marketplace');
Route::get('/overview', [MarketplaceController::class, 'index'])->name('overview');
Route::get('/about', [MarketplaceController::class, 'index'])->name('about');

/*
|--------------------------------------------------------------------------
| Buyer E-Commerce Ecosystem Routes (/buyer)
|--------------------------------------------------------------------------
*/
Route::prefix('buyer')->name('buyer.')->group(function () {
    Route::get('/', [BuyerHomeController::class, 'index'])->name('index');
    Route::get('/home', fn() => redirect()->route('marketplace'));
    Route::get('/search', [BuyerProductController::class, 'search'])->name('search');
    Route::get('/catalog', [BuyerProductController::class, 'search'])->name('catalog');
    Route::get('/product/{slug}', [BuyerProductController::class, 'show'])->name('products.show');
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    
    Route::middleware('auth')->group(function () {
        Route::get('/profile', [BuyerProfileController::class, 'index'])->name('profile');
        Route::post('/profile', [BuyerProfileController::class, 'update'])->name('profile.update');
        Route::get('/messages', [ChatController::class, 'buyerInbox'])->name('messages');
        Route::get('/disputes', [BuyerDisputeController::class, 'index'])->name('disputes.index');
        Route::post('/disputes', [BuyerDisputeController::class, 'store'])->name('disputes.store');
        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
        Route::get('/orders', [OrderHistoryController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');
        Route::post('/reviews', [BuyerReviewController::class, 'store'])->name('reviews.store');
        Route::post('/vouchers/apply', [VoucherController::class, 'apply'])->name('vouchers.apply');
    });
});

// Backward-compatible Public Marketplace / Catalog routes
Route::get('/products', [BuyerProductController::class, 'search'])->name('products.index');
Route::get('/catalog', [BuyerProductController::class, 'search'])->name('catalog.index');
Route::get('/product/{slug}', [BuyerProductController::class, 'show'])->name('products.show');
Route::get('/shop/{slug}', [MarketplaceController::class, 'shop'])->name('shop.show');

// Cart (Accessible to guests and logged in users)
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::patch('/cart/{cartItem}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');

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
        if (! $user->isAdmin() && ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected')) {
            return redirect()->route('kyc.pending');
        }
        return redirect()->intended(match($user->role) {
            'admin' => route('admin.dashboard'),
            'seller' => route('seller.dashboard'),
            'courier' => route('courier.deliveries'),
            'logistics' => route('hub.index'),
            default => route('buyer.index'),
        });
    })->name('dashboard');

    // Profile Settings
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Buyer Checkout & Orders
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/my-orders', [OrderHistoryController::class, 'index'])->name('orders.index');
    Route::get('/my-orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');

    // Live Chat / Messaging Endpoints
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
    Route::put('/products/{product}', [SellerProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [SellerProductController::class, 'destroy'])->name('products.destroy');
    Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/{order}/pack', [SellerOrderController::class, 'pack'])->name('orders.pack');
    Route::post('/orders/{order}/ready', [SellerOrderController::class, 'readyForPickup'])->name('orders.ready');
    Route::get('/vouchers', [SellerVoucherController::class, 'index'])->name('vouchers.index');
    Route::post('/vouchers', [SellerVoucherController::class, 'store'])->name('vouchers.store');
    Route::patch('/vouchers/{voucher}/toggle', [SellerVoucherController::class, 'toggle'])->name('vouchers.toggle');
    Route::delete('/vouchers/{voucher}', [SellerVoucherController::class, 'destroy'])->name('vouchers.destroy');
    Route::get('/messages', [ChatController::class, 'sellerInbox'])->name('messages.index');
    Route::get('/reviews', [SellerReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews/{review}/reply', [SellerReviewController::class, 'reply'])->name('reviews.reply');
    Route::get('/disputes', [SellerDisputeController::class, 'index'])->name('disputes.index');
    Route::patch('/disputes/{dispute}/respond', [SellerDisputeController::class, 'respond'])->name('disputes.respond');
    Route::get('/reports', [SellerDashboardController::class, 'reports'])->name('reports');
    Route::get('/settings', [SellerDashboardController::class, 'settings'])->name('settings');
    Route::post('/settings', [SellerDashboardController::class, 'updateSettings'])->name('settings.update');
});

/*
|--------------------------------------------------------------------------
| Courier & Logistics Portal Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:courier,logistics'])->prefix('courier')->name('courier.')->group(function () {
    Route::get('/deliveries', [CourierDeliveryController::class, 'index'])->name('deliveries');
    Route::post('/deliveries/{delivery}/claim', [CourierDeliveryController::class, 'claim'])->name('claim');
    Route::patch('/deliveries/{delivery}/status', [CourierDeliveryController::class, 'updateStatus'])->name('updateStatus');
    Route::get('/earnings', [CourierDeliveryController::class, 'earnings'])->name('earnings');
    Route::get('/messages', [CourierDeliveryController::class, 'messages'])->name('messages');
    Route::get('/profile', [CourierDeliveryController::class, 'profile'])->name('profile');
    Route::post('/profile/toggle-duty', [CourierDeliveryController::class, 'toggleDuty'])->name('toggleDuty');
});

/*
|--------------------------------------------------------------------------
| Admin Control Center Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [AdminDashboardController::class, 'users'])->name('users');
    Route::patch('/users/{user}/role', [AdminDashboardController::class, 'updateUserRole'])->name('users.updateRole');
    Route::get('/kyc', [AdminKycController::class, 'index'])->name('kyc.index');
    Route::post('/kyc/{user}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
    Route::post('/kyc/{user}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');
    Route::get('/products', [AdminDashboardController::class, 'products'])->name('products');
    Route::patch('/products/{product}/toggle', [AdminDashboardController::class, 'toggleProductStatus'])->name('products.toggle');
    Route::get('/logistics', [LogisticsHubController::class, 'index'])->name('logistics');
    Route::post('/logistics/override', [LogisticsHubController::class, 'override'])->name('logistics.override');
});

/*
|--------------------------------------------------------------------------
| Logistics Hub Workstation Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:logistics,admin'])->prefix('hub')->name('hub.')->group(function () {
    Route::get('/', [LogisticsHubWorkstationController::class, 'index'])->name('index');
    Route::post('/scan', [LogisticsHubWorkstationController::class, 'scanIntake'])->name('scan');
    Route::post('/sort', [LogisticsHubWorkstationController::class, 'sortBarangay'])->name('sort');
});

/*
|--------------------------------------------------------------------------
| Order Progression Simulator Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('simulator')->name('simulator.')->group(function () {
    Route::post('/orders/{order}/advance', [OrderSimulationController::class, 'advance'])->name('orders.advance');
    Route::post('/orders/{order}/reset', [OrderSimulationController::class, 'reset'])->name('orders.reset');
});

require __DIR__.'/auth.php';
