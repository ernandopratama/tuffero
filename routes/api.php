<?php

use App\Http\Controllers\Api\V1\AccountAddressController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CancellationController;
use App\Http\Controllers\Api\V1\Admin\CommentController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProfileController;
use App\Http\Controllers\Api\V1\Admin\PromoContentController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StockController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VoucherController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\OrderController;
use Illuminate\Support\Facades\Route;

// ==========================================
// V1 API ROUTES
// ==========================================

Route::prefix('v1')->group(function () {

    Route::get('/health', [HealthController::class, 'check']);

    Route::get('/catalog', [CatalogController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/settings/public', [SettingController::class, 'publicSettings'])->middleware('throttle:120,1');
    Route::get('/reviews', [CatalogController::class, 'reviews'])->middleware('throttle:120,1');

    // ------------------------------------------
    // Toko: keranjang dan pesanan tamu (session)
    // ------------------------------------------
    Route::middleware(['web'])->group(function () {
        Route::middleware('throttle:120,1')->group(function () {
            Route::get('/cart', [CartController::class, 'show']);
            Route::post('/cart/items', [CartController::class, 'store']);
            Route::patch('/cart/items/{sku}', [CartController::class, 'update']);
            Route::delete('/cart/items/{sku}', [CartController::class, 'destroy']);
            Route::post('/shipping/rates', [CheckoutController::class, 'rates']);
            Route::get('/orders/{ref}', [OrderController::class, 'show'])->whereUuid('ref');
        });

        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
        Route::post('/orders/lookup', [OrderController::class, 'lookup'])->middleware('throttle:order-lookup');
        Route::post('/orders/{ref}/cancellation', [OrderController::class, 'requestCancellation'])->whereUuid('ref')->middleware('throttle:checkout');
    });

    // ------------------------------------------
    // Auth Routes (SPA / Sanctum)
    // ------------------------------------------
    Route::middleware(['web'])->prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('throttle:mfa')->prefix('mfa')->group(function () {
            Route::post('/setup', [MfaController::class, 'setup']);
            Route::post('/confirm', [MfaController::class, 'confirm']);
            Route::post('/verify', [MfaController::class, 'verify']);
        });

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
        });
    });

    // ------------------------------------------
    // Akun pelanggan (D-051)
    // ------------------------------------------
    Route::middleware(['web', 'auth:sanctum', 'active'])->prefix('account')->group(function () {
        Route::get('/profile', [AccountController::class, 'show']);
        Route::get('/orders', [AccountController::class, 'orders'])->middleware('throttle:120,1');

        Route::get('/addresses', [AccountAddressController::class, 'index'])->middleware('throttle:120,1');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/addresses', [AccountAddressController::class, 'store']);
            Route::patch('/addresses/{address}', [AccountAddressController::class, 'update'])->whereNumber('address');
            Route::delete('/addresses/{address}', [AccountAddressController::class, 'destroy'])->whereNumber('address');
            Route::post('/addresses/{address}/default', [AccountAddressController::class, 'makeDefault'])->whereNumber('address');
        });
        Route::patch('/profile', [AccountController::class, 'update'])->middleware('throttle:profile');
        Route::put('/profile/password', [AccountController::class, 'password'])->middleware('throttle:profile');
    });

    // ------------------------------------------
    // Admin Routes
    // ------------------------------------------
    Route::middleware(['web', 'auth:sanctum', 'active'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::put('/profile/password', [ProfileController::class, 'password'])->middleware(['throttle:profile', 'mfa.enrolled']);

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
        Route::post('/users', [UserController::class, 'store'])->middleware(['permission:users.manage', 'mfa.enrolled']);
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware(['permission:users.manage', 'mfa.enrolled']);
        Route::post('/users/{user}/reset-mfa', [UserController::class, 'resetMfa'])->middleware(['permission:users.manage_mfa', 'mfa.enrolled']);
        Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:roles.manage');

        Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.manage|settings.manage_contact');
        Route::patch('/settings/{setting:key}', [SettingController::class, 'update'])->middleware(['permission:settings.manage|settings.manage_contact', 'mfa.enrolled']);

        Route::middleware('permission:reviews.manage')->group(function () {
            Route::get('/reviews', [ReviewController::class, 'index']);
            Route::middleware('mfa.enrolled')->group(function () {
                Route::post('/reviews', [ReviewController::class, 'store']);
                Route::patch('/reviews/{review}', [ReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
            });
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        Route::middleware('permission:products.view')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show']);
        });

        Route::middleware(['permission:products.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
            Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
            Route::patch('/variants/{variant}', [ProductController::class, 'updateVariant']);
            Route::post('/products/{product}/images', [ProductController::class, 'storeImage']);
            Route::delete('/images/{image}', [ProductController::class, 'destroyImage']);
        });

        Route::middleware('permission:stock.view')->group(function () {
            Route::get('/stock', [StockController::class, 'index']);
            Route::get('/stock/movements', [StockController::class, 'movements']);
        });

        Route::post('/stock/adjust', [StockController::class, 'adjust'])->middleware(['permission:stock.adjust', 'mfa.enrolled']);

        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view');
        Route::get('/fulfillment', [AdminOrderController::class, 'fulfillment'])->middleware('permission:orders.view_fulfillment');
        Route::get('/shipments', [AdminOrderController::class, 'shipments'])->middleware('permission:shipments.view');
        Route::get('/payments', [AdminOrderController::class, 'payments'])->middleware('permission:payments.view');
        Route::post('/orders/{order}/process', [AdminOrderController::class, 'startProcessing'])->middleware(['permission:orders.update_status', 'mfa.enrolled']);
        Route::post('/orders/{order}/ship', [AdminOrderController::class, 'ship'])->middleware(['permission:shipments.manage', 'mfa.enrolled']);
        Route::post('/orders/{order}/complete', [AdminOrderController::class, 'complete'])->middleware(['permission:orders.update_status', 'mfa.enrolled']);

        Route::middleware('permission:orders.view|orders.view_fulfillment')->group(function () {
            Route::get('/orders/{order}/comments', [CommentController::class, 'index']);
            Route::post('/orders/{order}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });

        Route::get('/cancellations', [CancellationController::class, 'index'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
        Route::middleware('mfa.enrolled')->prefix('cancellations/{cancellation}')->group(function () {
            Route::post('/validate', [CancellationController::class, 'validateRequest'])->middleware('permission:orders.cancel_request');
            Route::post('/approve', [CancellationController::class, 'approveByStore'])->middleware('permission:orders.cancel');
            Route::post('/final-approve', [CancellationController::class, 'approveByOwner'])->middleware('permission:orders.cancel_final');
            Route::post('/refunded', [CancellationController::class, 'markRefunded'])->middleware('permission:orders.cancel');
            Route::post('/reject', [CancellationController::class, 'reject'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
        });

        Route::middleware('permission:vouchers.view')->group(function () {
            Route::get('/vouchers', [VoucherController::class, 'index']);
            Route::get('/vouchers/{voucher}/comments', [CommentController::class, 'index']);
            Route::post('/vouchers/{voucher}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });

        Route::middleware(['permission:vouchers.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/vouchers', [VoucherController::class, 'store']);
            Route::patch('/vouchers/{voucher}', [VoucherController::class, 'update']);
        });

        Route::middleware('permission:content.view')->group(function () {
            Route::get('/contents', [PromoContentController::class, 'index']);
            Route::get('/contents/{content}/comments', [CommentController::class, 'index']);
            Route::post('/contents/{content}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });

        Route::middleware(['permission:content.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/contents', [PromoContentController::class, 'store']);
            Route::patch('/contents/{content}', [PromoContentController::class, 'update']);
            Route::post('/contents/{content}/image', [PromoContentController::class, 'storeImage']);
        });
    });
});
