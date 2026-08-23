<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Buyer\BuyerHomeController;
use App\Http\Controllers\Buyer\BuyerProductController;
use App\Http\Controllers\Buyer\BuyerReviewController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\OrderHistoryController;
use App\Http\Controllers\Buyer\VoucherController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Courier\CourierDeliveryController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerVoucherController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public Landing Page (Completely Standalone)
|--------------------------------------------------------------------------
*/
Route::get('/', [MarketplaceController::class, 'index'])->name('marketplace');

/*
|--------------------------------------------------------------------------
| Buyer E-Commerce Ecosystem Routes (/buyer)
|--------------------------------------------------------------------------
*/
Route::prefix('buyer')->name('buyer.')->group(function () {
    Route::get('/', [BuyerHomeController::class, 'index'])->name('index');
    Route::get('/home', fn() => redirect()->route('buyer.index'));
    Route::get('/product/{slug}', [BuyerProductController::class, 'show'])->name('products.show');
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    
    Route::middleware('auth')->group(function () {
        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
        Route::get('/orders', [OrderHistoryController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderHistoryController::class, 'show'])->name('orders.show');
        Route::post('/reviews', [BuyerReviewController::class, 'store'])->name('reviews.store');
        Route::post('/vouchers/apply', [VoucherController::class, 'apply'])->name('vouchers.apply');
    });
});

// Backward-compatible Public Marketplace / Catalog routes
Route::get('/products', [MarketplaceController::class, 'catalog'])->name('products.index');
Route::get('/catalog', fn() => redirect()->route('buyer.index'));
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
        return redirect()->intended(match($user->role) {
            'admin' => route('admin.dashboard'),
            'seller' => route('seller.dashboard'),
            'courier' => route('courier.deliveries'),
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
    Route::get('/products', [AdminDashboardController::class, 'products'])->name('products');
    Route::patch('/products/{product}/toggle', [AdminDashboardController::class, 'toggleProductStatus'])->name('products.toggle');
});

require __DIR__.'/auth.php';
