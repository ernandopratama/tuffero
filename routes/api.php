<?php

use App\Http\Controllers\Api\V1\AccountAddressController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountVoucherController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CampaignController;
use App\Http\Controllers\Api\V1\Admin\CancellationController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\ChatFaqController;
use App\Http\Controllers\Api\V1\Admin\CommentController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerEmailController;
use App\Http\Controllers\Api\V1\Admin\CustomerHistoryController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DiscountController;
use App\Http\Controllers\Api\V1\Admin\FollowUpController;
use App\Http\Controllers\Api\V1\Admin\GalleryPhotoController;
use App\Http\Controllers\Api\V1\Admin\IntegrationController;
use App\Http\Controllers\Api\V1\Admin\MarketingDraftController;
use App\Http\Controllers\Api\V1\Admin\NewsItemController;
use App\Http\Controllers\Api\V1\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductReviewController;
use App\Http\Controllers\Api\V1\Admin\ProfileController;
use App\Http\Controllers\Api\V1\Admin\PromoContentController;
use App\Http\Controllers\Api\V1\Admin\QuickSearchController;
use App\Http\Controllers\Api\V1\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\V1\Admin\ReplyTemplateController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StockController;
use App\Http\Controllers\Api\V1\Admin\SystemHealthController;
use App\Http\Controllers\Api\V1\Admin\TermsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VoucherController;
use App\Http\Controllers\Api\V1\Admin\WarehouseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BiteshipWebhookController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HermesDraftController;
use App\Http\Controllers\Api\V1\HermesReminderController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\ChatController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\CheckoutVoucherController;
use App\Http\Controllers\Api\V1\Store\OrderController;
use App\Http\Controllers\Api\V1\Store\PhoneOtpController;
use App\Http\Controllers\Api\V1\Store\RefundController as StoreRefundController;
use App\Http\Controllers\Api\V1\Store\RegisterController;
use App\Http\Middleware\HermesToken;
use Illuminate\Support\Facades\Route;

// ==========================================
// V1 API ROUTES
// ==========================================

Route::prefix('v1')->group(function () {

    Route::get('/health', [HealthController::class, 'check']);

    // Notifikasi Midtrans: tanpa sesi dan CSRF, keaslian dari signature_key (BR-11).
    Route::post('/webhooks/midtrans', MidtransWebhookController::class)->middleware('throttle:120,1');
    Route::post('/webhooks/biteship', BiteshipWebhookController::class)->middleware('throttle:120,1');
    // Laporan riset dari Hermes Agent (D-079): token bersama, bukan sesi. Batas ketat karena hanya jadwal mingguan yang memakainya.
    Route::post('/integrations/hermes/drafts', HermesDraftController::class)->middleware(['throttle:hermes-ingest', HermesToken::class]);
    // Ringkasan pengingat untuk Hermes (hanya baca, tanpa data pelanggan): dasar pesan Telegram yang tahu isi dashboard.
    Route::get('/integrations/hermes/reminders', HermesReminderController::class)->middleware(['throttle:hermes-ingest', HermesToken::class]);

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
            Route::put('/cart/selection', [CartController::class, 'select']);
            Route::patch('/cart/items/{sku}', [CartController::class, 'update']);
            Route::delete('/cart/items/{sku}', [CartController::class, 'destroy']);
            Route::get('/orders/{ref}', [OrderController::class, 'show'])->whereUuid('ref');
            Route::get('/orders/{ref}/tracking', [OrderController::class, 'tracking'])->whereUuid('ref');
        });
        // Tiap panggilan bisa memotong saldo Biteship (D-075), jadi dibatasi lebih ketat daripada rute keranjang.
        Route::post('/shipping/rates', [CheckoutController::class, 'rates'])->middleware('throttle:shipping-rates');

        Route::get('/vouchers', [CheckoutVoucherController::class, 'index'])->middleware('throttle:120,1');
        Route::get('/vouchers/claimable', [CheckoutVoucherController::class, 'claimable'])->middleware('throttle:120,1');
        // Dibatasi ketat: mencegah menebak kode voucher.
        Route::post('/vouchers/check', [CheckoutVoucherController::class, 'check'])->middleware('throttle:20,1');
        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
        Route::post('/phone-otp/send', [PhoneOtpController::class, 'send'])->middleware('throttle:phone-otp');
        Route::post('/phone-otp/verify', [PhoneOtpController::class, 'verify'])->middleware('throttle:phone-otp');
        Route::post('/orders/lookup', [OrderController::class, 'lookup'])->middleware('throttle:order-lookup');
        Route::post('/refunds/lookup', [StoreRefundController::class, 'lookup'])->middleware('throttle:order-lookup');
        Route::post('/refunds', [StoreRefundController::class, 'store'])->middleware('throttle:checkout');
        Route::get('/chat/faqs', [ChatController::class, 'faqs'])->middleware('throttle:60,1');
        Route::post('/chat/ask', [ChatController::class, 'ask'])->middleware('throttle:chat-ai');
        Route::post('/chat/order-status', [ChatController::class, 'orderStatus'])->middleware('throttle:order-lookup');
        Route::post('/chat/shipping-rates', [ChatController::class, 'shippingRates'])->middleware('throttle:chat-rates');
        Route::post('/orders/{ref}/payment', [OrderController::class, 'payment'])->whereUuid('ref')->middleware('throttle:checkout');
        Route::post('/orders/{ref}/payment/sync', [OrderController::class, 'syncPayment'])->whereUuid('ref')->middleware('throttle:60,1');
        Route::post('/orders/{ref}/cancellation', [OrderController::class, 'requestCancellation'])->whereUuid('ref')->middleware('throttle:checkout');
        Route::post('/orders/{ref}/reviews', [OrderController::class, 'review'])->whereUuid('ref')->middleware('throttle:checkout');
    });

    // ------------------------------------------
    // Auth Routes (SPA / Sanctum)
    // ------------------------------------------
    Route::middleware(['web'])->prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        // Daftar pelanggan dengan kode email atau WhatsApp (D-095). Batas kasar per IP; batas kirim yang berbiaya ada di RegistrationService.
        Route::middleware('throttle:phone-otp')->prefix('register')->group(function () {
            Route::post('/send', [RegisterController::class, 'send']);
            Route::post('/resend', [RegisterController::class, 'resend']);
            Route::post('/verify', [RegisterController::class, 'verify']);
        });

        Route::middleware('throttle:mfa')->prefix('mfa')->group(function () {
            Route::post('/setup', [MfaController::class, 'setup']);
            Route::post('/confirm', [MfaController::class, 'confirm']);
            Route::post('/verify', [MfaController::class, 'verify']);
            Route::post('/email', [MfaController::class, 'sendEmailCode']);
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
        Route::get('/vouchers', [AccountVoucherController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/vouchers/{code}/claim', [AccountVoucherController::class, 'claim'])->middleware('throttle:30,1');

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
        Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports.view|reports.view_summary');
        Route::get('/system-health', [SystemHealthController::class, 'index'])->middleware(['permission:settings.manage', 'throttle:30,1']);
        Route::post('/system-health/log-ack', [SystemHealthController::class, 'acknowledgeLog'])->middleware(['permission:settings.manage', 'mfa.enrolled', 'throttle:6,1']);
        Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('/integrations/check', [IntegrationController::class, 'check'])->middleware(['permission:settings.manage', 'mfa.enrolled', 'throttle:6,1']);

        Route::get('/notifications', [AdminNotificationController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/notifications/read', [AdminNotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [AdminNotificationController::class, 'read']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::put('/profile/password', [ProfileController::class, 'password'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::post('/profile/username/code', [ProfileController::class, 'usernameCode'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::put('/profile/username', [ProfileController::class, 'username'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::post('/profile/mfa/start', [ProfileController::class, 'mfaStart'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::post('/profile/mfa/confirm', [ProfileController::class, 'mfaConfirm'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::get('/profile/activity', [ProfileController::class, 'activity']);
        Route::get('/profile/notifications', [ProfileController::class, 'notifications']);
        Route::put('/profile/notifications', [ProfileController::class, 'updateNotifications'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::get('/profile/sessions', [ProfileController::class, 'sessions']);
        Route::delete('/profile/sessions', [ProfileController::class, 'revokeOtherSessions'])->middleware(['throttle:profile', 'mfa.enrolled']);
        Route::delete('/profile/sessions/{token}', [ProfileController::class, 'revokeSession'])->where('token', '[a-f0-9]{32}')->middleware(['throttle:profile', 'mfa.enrolled']);

        Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::get('/customers/{user}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
        Route::patch('/customers/{user}', [CustomerController::class, 'update'])->middleware(['permission:customers.deactivate', 'mfa.enrolled']);
        Route::delete('/customers/{user}', [CustomerController::class, 'destroy'])->middleware(['permission:users.manage', 'mfa.enrolled']);

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
        Route::post('/users', [UserController::class, 'store'])->middleware(['permission:users.manage', 'mfa.enrolled']);
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware(['permission:users.manage', 'mfa.enrolled']);
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware(['permission:users.manage', 'mfa.enrolled']);
        Route::post('/users/{user}/reset-mfa', [UserController::class, 'resetMfa'])->middleware(['permission:users.manage_mfa', 'mfa.enrolled']);
        Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:roles.manage');

        Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.manage|settings.manage_contact');
        Route::patch('/settings/{setting:key}', [SettingController::class, 'update'])->middleware(['permission:settings.manage|settings.manage_contact', 'mfa.enrolled']);

        Route::get('/product-reviews', [ProductReviewController::class, 'index'])->middleware('permission:product_reviews.view');
        Route::middleware(['permission:product_reviews.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/product-reviews', [ProductReviewController::class, 'store']);
            Route::patch('/product-reviews/{productReview}', [ProductReviewController::class, 'update']);
            Route::delete('/product-reviews/{productReview}', [ProductReviewController::class, 'destroy']);
        });

        Route::middleware('permission:reviews.manage')->group(function () {
            Route::get('/reviews', [ReviewController::class, 'index']);
            Route::middleware('mfa.enrolled')->group(function () {
                Route::post('/reviews', [ReviewController::class, 'store']);
                Route::patch('/reviews/{review}', [ReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
            });
        });

        Route::get('/faqs', [ChatFaqController::class, 'index'])->middleware('permission:faqs.manage');
        Route::middleware(['permission:faqs.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/faqs', [ChatFaqController::class, 'store']);
            Route::patch('/faqs/{faq}', [ChatFaqController::class, 'update']);
        });

        // Template balasan CS: hanya CS dan Super Admin. Mengisi variabel butuh izin lihat datanya juga.
        Route::middleware('permission:templates.manage')->group(function () {
            Route::get('/reply-templates', [ReplyTemplateController::class, 'index']);
            Route::get('/orders/{order}/reply-templates', [ReplyTemplateController::class, 'forOrder'])->middleware('permission:orders.view');
            Route::get('/refunds/{refund}/reply-templates', [ReplyTemplateController::class, 'forRefund'])->middleware('permission:refunds.view');
            Route::get('/cancellations/{cancellation}/reply-templates', [ReplyTemplateController::class, 'forCancellation'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
            Route::middleware('mfa.enrolled')->group(function () {
                Route::post('/reply-templates', [ReplyTemplateController::class, 'store']);
                Route::patch('/reply-templates/{template}', [ReplyTemplateController::class, 'update']);
            });
        });

        // Alat CS (D-105): pencarian cepat dan penanda tindak lanjut. Izin lihat datanya dicek tambahan.
        Route::get('/quick-search', QuickSearchController::class)->middleware(['permission:search.quick', 'throttle:60,1']);
        Route::middleware('permission:followups.manage')->group(function () {
            Route::get('/orders/{order}/follow-ups', [FollowUpController::class, 'index'])->middleware('permission:orders.view');
            Route::get('/refunds/{refund}/follow-ups', [FollowUpController::class, 'index'])->middleware('permission:refunds.view');
            Route::get('/cancellations/{cancellation}/follow-ups', [FollowUpController::class, 'index'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
            Route::middleware(['mfa.enrolled', 'throttle:60,1'])->group(function () {
                Route::post('/orders/{order}/follow-ups', [FollowUpController::class, 'store'])->middleware('permission:orders.view');
                Route::post('/refunds/{refund}/follow-ups', [FollowUpController::class, 'store'])->middleware('permission:refunds.view');
                Route::post('/cancellations/{cancellation}/follow-ups', [FollowUpController::class, 'store'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
                Route::post('/follow-ups/{followUp}/done', [FollowUpController::class, 'complete']);
            });
        });

        // Riwayat pelanggan (D-107) dan email CS ke pelanggan (D-108). Izin lihat datanya dicek tambahan.
        Route::middleware('permission:search.quick')->group(function () {
            Route::get('/orders/{order}/customer-history', [CustomerHistoryController::class, 'forOrder'])->middleware('permission:orders.view');
            Route::get('/refunds/{refund}/customer-history', [CustomerHistoryController::class, 'forRefund'])->middleware('permission:refunds.view');
            Route::get('/cancellations/{cancellation}/customer-history', [CustomerHistoryController::class, 'forCancellation'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
        });
        Route::middleware('permission:emails.send')->group(function () {
            Route::get('/orders/{order}/customer-emails', [CustomerEmailController::class, 'index'])->middleware('permission:orders.view');
            Route::get('/refunds/{refund}/customer-emails', [CustomerEmailController::class, 'index'])->middleware('permission:refunds.view');
            Route::get('/cancellations/{cancellation}/customer-emails', [CustomerEmailController::class, 'index'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
            Route::middleware(['mfa.enrolled', 'throttle:10,1'])->group(function () {
                Route::post('/orders/{order}/customer-emails', [CustomerEmailController::class, 'store'])->middleware('permission:orders.view');
                Route::post('/refunds/{refund}/customer-emails', [CustomerEmailController::class, 'store'])->middleware('permission:refunds.view');
                Route::post('/cancellations/{cancellation}/customer-emails', [CustomerEmailController::class, 'store'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
            });
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        Route::middleware('permission:products.view')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show']);
            Route::get('/categories', [CategoryController::class, 'index']);
        });

        Route::middleware(['permission:categories.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::patch('/categories/{category}', [CategoryController::class, 'update']);
            Route::post('/categories/{category}/types', [CategoryController::class, 'storeType']);
            Route::patch('/category-types/{type}', [CategoryController::class, 'updateType']);
        });

        Route::middleware('permission:products.view')->group(function () {
            Route::get('/campaigns', [CampaignController::class, 'index']);
            Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
        });

        Route::middleware(['permission:campaigns.manage', 'mfa.enrolled'])->group(function () {
            Route::get('/campaign-variants', [CampaignController::class, 'variants']);
            Route::post('/campaigns', [CampaignController::class, 'store']);
            Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign');
            Route::post('/campaigns/{campaign}/image', [CampaignController::class, 'image'])->whereNumber('campaign');
            Route::post('/campaigns/{campaign}/items', [CampaignController::class, 'storeItem'])->whereNumber('campaign');
            Route::patch('/campaign-items/{discount}', [CampaignController::class, 'updateItem'])->whereNumber('discount');
        });

        Route::middleware(['permission:products.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
            Route::post('/products/{product}/translate', [ProductController::class, 'translate'])->middleware('throttle:20,1');
            Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
            Route::patch('/variants/{variant}', [ProductController::class, 'updateVariant']);
            Route::post('/variants/{variant}/discounts', [DiscountController::class, 'store']);
            Route::patch('/discounts/{discount}', [DiscountController::class, 'update']);
            Route::post('/products/{product}/images', [ProductController::class, 'storeImage']);
            Route::put('/products/{product}/images/order', [ProductController::class, 'reorderImages']);
            Route::delete('/images/{image}', [ProductController::class, 'destroyImage']);
        });

        Route::middleware('permission:stock.view')->group(function () {
            Route::get('/stock', [StockController::class, 'index']);
            Route::get('/stock/movements', [StockController::class, 'movements']);
            Route::get('/stock/history', [StockController::class, 'history']);
            Route::get('/stock/history/export', [StockController::class, 'exportHistory'])->middleware('throttle:10,1');
        });

        Route::post('/stock/adjust', [StockController::class, 'adjust'])->middleware(['permission:stock.adjust', 'mfa.enrolled']);

        Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view');
        Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware(['permission:warehouses.manage', 'mfa.enrolled']);

        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view');
        Route::get('/fulfillment', [AdminOrderController::class, 'fulfillment'])->middleware('permission:orders.view_fulfillment');
        Route::get('/fulfillment/pick-list', [AdminOrderController::class, 'pickList'])->middleware(['permission:orders.view_fulfillment', 'throttle:30,1']);
        Route::get('/shipments', [AdminOrderController::class, 'shipments'])->middleware('permission:shipments.view');
        Route::get('/payments', [AdminOrderController::class, 'payments'])->middleware('permission:payments.view');
        Route::post('/orders/{order}/process', [AdminOrderController::class, 'startProcessing'])->middleware(['permission:orders.update_status', 'mfa.enrolled']);
        Route::post('/orders/{order}/ship', [AdminOrderController::class, 'ship'])->middleware(['permission:shipments.manage', 'mfa.enrolled']);
        Route::post('/orders/{order}/arrange', [AdminOrderController::class, 'arrange'])->middleware(['permission:shipments.manage', 'mfa.enrolled']);
        Route::post('/orders/{order}/mark-shipped', [AdminOrderController::class, 'markShipped'])->middleware(['permission:shipments.manage', 'mfa.enrolled']);
        Route::get('/orders/{order}/label', [AdminOrderController::class, 'label'])->middleware('permission:shipments.manage');
        Route::post('/orders/{order}/late-payment-refunded', [AdminOrderController::class, 'markLateRefunded'])->middleware(['permission:orders.cancel', 'mfa.enrolled', 'throttle:30,1']);
        Route::post('/orders/{order}/complete', [AdminOrderController::class, 'complete'])->middleware(['permission:orders.update_status', 'mfa.enrolled']);

        Route::middleware('permission:orders.view|orders.view_fulfillment')->group(function () {
            Route::get('/orders/{order}/comments', [CommentController::class, 'index']);
            Route::post('/orders/{order}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });

        Route::get('/cancellations', [CancellationController::class, 'index'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
        Route::middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final')->group(function () {
            Route::get('/cancellations/{cancellation}/comments', [CommentController::class, 'index']);
            Route::post('/cancellations/{cancellation}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });
        Route::middleware('mfa.enrolled')->prefix('cancellations/{cancellation}')->group(function () {
            Route::post('/validate', [CancellationController::class, 'validateRequest'])->middleware('permission:orders.cancel_request');
            Route::post('/approve', [CancellationController::class, 'approveByStore'])->middleware('permission:orders.cancel');
            Route::post('/final-approve', [CancellationController::class, 'approveByOwner'])->middleware('permission:orders.cancel_final');
            Route::post('/refunded', [CancellationController::class, 'markRefunded'])->middleware('permission:orders.cancel');
            Route::post('/reject', [CancellationController::class, 'reject'])->middleware('permission:orders.cancel_request|orders.cancel|orders.cancel_final');
        });

        Route::middleware(['permission:terms.manage', 'throttle:60,1'])->prefix('terms')->group(function () {
            Route::get('/', [TermsController::class, 'index']);
            Route::middleware('mfa.enrolled')->group(function () {
                Route::post('/', [TermsController::class, 'store']);
                Route::put('/{section}', [TermsController::class, 'update']);
                Route::delete('/{section}', [TermsController::class, 'destroy']);
                Route::post('/{section}/move', [TermsController::class, 'move']);
            });
        });

        Route::middleware('permission:refunds.view')->group(function () {
            Route::get('/refunds', [AdminRefundController::class, 'index']);
            Route::get('/refunds/{refund}', [AdminRefundController::class, 'show']);
            Route::get('/refunds/{refund}/comments', [CommentController::class, 'index']);
            Route::post('/refunds/{refund}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });
        Route::middleware('mfa.enrolled')->prefix('refunds/{refund}')->group(function () {
            Route::post('/contacted', [AdminRefundController::class, 'contacted'])->middleware('permission:refunds.process');
            Route::post('/evidence', [AdminRefundController::class, 'evidence'])->middleware('permission:refunds.process');
            Route::post('/choice', [AdminRefundController::class, 'choice'])->middleware('permission:refunds.process');
            Route::post('/refunded', [AdminRefundController::class, 'refunded'])->middleware('permission:refunds.process');
            Route::post('/cancel', [AdminRefundController::class, 'cancel'])->middleware('permission:refunds.process');
            Route::post('/approve', [AdminRefundController::class, 'approve'])->middleware('permission:refunds.decide');
            Route::post('/reject', [AdminRefundController::class, 'reject'])->middleware('permission:refunds.decide');
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
            Route::get('/gallery-photos', [GalleryPhotoController::class, 'index']);
            Route::get('/contents/{content}/comments', [CommentController::class, 'index']);
            Route::post('/contents/{content}/comments', [CommentController::class, 'store'])->middleware(['permission:comments.create', 'mfa.enrolled']);
        });

        // Berita popup beranda (D-085): semua role admin melihat, Marketing dan Super Admin mengelola.
        Route::get('/news', [NewsItemController::class, 'index'])->middleware('permission:news.view');
        Route::middleware(['permission:news.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/news', [NewsItemController::class, 'store']);
            Route::patch('/news/{item}', [NewsItemController::class, 'update'])->whereNumber('item');
            Route::post('/news/{item}/image', [NewsItemController::class, 'storeImage'])->whereNumber('item')->middleware('throttle:30,1');
            Route::delete('/news/{item}/image', [NewsItemController::class, 'destroyImage'])->whereNumber('item');
            Route::delete('/news/{item}', [NewsItemController::class, 'destroy'])->whereNumber('item');
        });

        // Draf konten AI Marketing (D-062, D-079): semua role admin melihat, Marketing dan Super Admin menyusun, Pemilik dan Super Admin memutuskan.
        Route::get('/marketing-drafts', [MarketingDraftController::class, 'index'])->middleware('permission:marketing_drafts.view');
        Route::middleware(['permission:marketing_drafts.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/marketing-drafts', [MarketingDraftController::class, 'store']);
            Route::post('/marketing-drafts/generate', [MarketingDraftController::class, 'generate'])->middleware('throttle:marketing-ai');
            Route::patch('/marketing-drafts/{draft}', [MarketingDraftController::class, 'update'])->whereNumber('draft');
            Route::post('/marketing-drafts/{draft}/move', [MarketingDraftController::class, 'move'])->whereNumber('draft');
            Route::delete('/marketing-drafts/{draft}', [MarketingDraftController::class, 'destroy'])->whereNumber('draft');
        });
        Route::post('/marketing-drafts/{draft}/decide', [MarketingDraftController::class, 'decide'])->whereNumber('draft')->middleware(['permission:marketing_drafts.approve', 'mfa.enrolled']);

        Route::middleware(['permission:content.manage', 'mfa.enrolled'])->group(function () {
            Route::post('/contents', [PromoContentController::class, 'store']);
            Route::patch('/contents/{content}', [PromoContentController::class, 'update']);
            Route::post('/contents/{content}/image', [PromoContentController::class, 'storeImage']);
            Route::post('/contents/{content}/thumbnail', [PromoContentController::class, 'refreshThumbnail'])->middleware('throttle:10,1');
            Route::delete('/contents/{content}', [PromoContentController::class, 'destroy']);
            Route::post('/gallery-photos', [GalleryPhotoController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/gallery-photos/{photo}', [GalleryPhotoController::class, 'update']);
            Route::post('/gallery-photos/{photo}/image', [GalleryPhotoController::class, 'storeImage'])->middleware('throttle:30,1');
            Route::delete('/gallery-photos/{photo}', [GalleryPhotoController::class, 'destroy']);
        });
    });
});
