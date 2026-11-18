<?php

use App\Http\Controllers\Api\V1\AccountSettingsController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\PaymentSettingsController;
use App\Http\Controllers\Api\V1\AdminAuthController;
use App\Http\Controllers\Api\V1\AdminConsoleController;
use App\Http\Controllers\Api\V1\AdminMarketingController;
use App\Http\Controllers\Api\V1\MarketingAuthController;
use App\Http\Controllers\Api\V1\MarketingWorkspaceController;
use App\Http\Controllers\Api\V1\AdminSubscriptionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingPaymentController;
use App\Http\Controllers\Api\V1\BookingPortalController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PortalMessageController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GettingStartedController;
use App\Http\Controllers\Api\V1\PublicDirectPortalBookingController;
use App\Http\Controllers\Api\V1\PublicDirectPortalCalendarController;
use App\Http\Controllers\Api\V1\PublicDirectPortalController;
use App\Http\Controllers\Api\V1\PublicDirectPortalQuoteController;
use App\Http\Controllers\Api\V1\PromoCodeController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\UnitDateBlockController;
use App\Http\Controllers\Api\V1\UnitDiscountController;
use App\Http\Controllers\Api\V1\Configuration\NotificationSettingsController;
use App\Http\Controllers\Api\V1\Configuration\PropertyController;
use App\Http\Controllers\Api\V1\Configuration\TeamMemberController;
use App\Http\Controllers\Api\V1\Configuration\UnitController;
use App\Http\Controllers\Api\V1\Configuration\UnitRateIntervalController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MerchantSubscriptionController;
use App\Http\Controllers\Api\V1\PublicPricingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): \Illuminate\Http\JsonResponse {
    return response()->json([
        'ok' => true,
        'status' => 'ok',
        'service' => 'hofros-api',
        'name' => 'Hofros',
        'message' => 'hofros API is running',
        'version' => '1.0.0',
        'health' => url('/api/health'),
    ]);
});

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::get('/public/pricing', PublicPricingController::class);
    Route::get('/public/direct-portals/{slug}', [PublicDirectPortalController::class, 'show'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/public/direct-portals/{slug}/calendar', [PublicDirectPortalCalendarController::class, 'show'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/public/direct-portals/{slug}/quote', [PublicDirectPortalQuoteController::class, 'show'])
        ->where('slug', '[a-z0-9-]+');
    Route::post('/public/direct-portals/{slug}/bookings', [PublicDirectPortalBookingController::class, 'store'])
        ->where('slug', '[a-z0-9-]+');
    Route::post('/customer/register', [CustomerAuthController::class, 'register']);
    Route::post('/customer/login', [CustomerAuthController::class, 'login']);
    Route::middleware(['auth:sanctum', 'customer'])->group(function (): void {
        Route::get('/customer/me', [CustomerAuthController::class, 'me']);
        Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
        Route::get('/public/direct-portals/{slug}/messages', [PortalMessageController::class, 'customerIndex'])
            ->where('slug', '[a-z0-9-]+');
        Route::post('/public/direct-portals/{slug}/messages', [PortalMessageController::class, 'customerStore'])
            ->where('slug', '[a-z0-9-]+');
    });
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AdminAuthController::class, 'login']);
    Route::post('/marketing/login', [MarketingAuthController::class, 'login']);
    Route::middleware(['auth:sanctum', 'marketing'])->prefix('marketing')->group(function (): void {
        Route::get('/home', [MarketingWorkspaceController::class, 'home']);
        Route::post('/prospects', [MarketingWorkspaceController::class, 'store']);
        Route::put('/prospects/{prospect}/status', [MarketingWorkspaceController::class, 'updateStatus']);
        Route::post('/prospects/{prospect}/notes', [MarketingWorkspaceController::class, 'storeNote']);
    });

    Route::middleware(['auth:sanctum', 'platform-admin'])->prefix('admin')->group(function (): void {
        Route::get('/me', [AdminConsoleController::class, 'me']);
        Route::get('/dashboard', [AdminConsoleController::class, 'dashboard']);
        Route::get('/merchants', [AdminConsoleController::class, 'merchants']);
        Route::get('/merchants/{merchant}', [AdminConsoleController::class, 'merchant']);
        Route::get('/bookings', [AdminConsoleController::class, 'bookings']);
        Route::get('/calendars', [AdminConsoleController::class, 'calendars']);
        Route::get('/users', [AdminConsoleController::class, 'users']);
        Route::get('/marketing', [AdminMarketingController::class, 'index']);
        Route::post('/marketing', [AdminMarketingController::class, 'store']);
        Route::post('/marketing/accounts', [AdminMarketingController::class, 'storeAccount']);
        Route::put('/marketing/{prospect}/status', [AdminMarketingController::class, 'updateStatus']);
        Route::post('/marketing/{prospect}/notes', [AdminMarketingController::class, 'storeNote']);
        Route::put('/marketing/{prospect}', [AdminMarketingController::class, 'update']);
        Route::delete('/marketing/{prospect}', [AdminMarketingController::class, 'destroy']);
        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::post('/subscriptions/plans', [AdminSubscriptionController::class, 'storePlan']);
        Route::put('/subscriptions/plans/{plan}', [AdminSubscriptionController::class, 'updatePlan']);
        Route::put('/subscriptions/features', [AdminSubscriptionController::class, 'updateFeatures']);
        Route::put('/subscriptions/billing-discounts', [AdminSubscriptionController::class, 'updateBillingDiscounts']);
        Route::put('/subscriptions/free-trial', [AdminSubscriptionController::class, 'updateFreeTrial']);
        Route::post('/subscriptions/assign', [AdminSubscriptionController::class, 'assign']);
    });

    Route::middleware(['auth:sanctum', 'merchant'])->group(function (): void {
        Route::get('/configuration/properties', [PropertyController::class, 'index']);
        Route::post('/configuration/properties', [PropertyController::class, 'store']);
        Route::put('/configuration/properties/{id}', [PropertyController::class, 'update']);
        Route::delete('/configuration/properties/{id}', [PropertyController::class, 'destroy']);

        Route::get('/configuration/units', [UnitController::class, 'index']);
        Route::post('/configuration/units', [UnitController::class, 'store']);
        Route::post('/configuration/units/{id}/images', [UnitController::class, 'uploadImages']);
        Route::put('/configuration/units/{id}', [UnitController::class, 'update']);
        Route::patch('/configuration/units/{id}/week-schedule', [UnitController::class, 'updateWeekSchedule']);
        Route::delete('/configuration/units/{id}', [UnitController::class, 'destroy']);

        Route::get('/configuration/units/{unitId}/rate-intervals', [UnitRateIntervalController::class, 'index']);
        Route::post('/configuration/units/{unitId}/rate-intervals', [UnitRateIntervalController::class, 'store']);
        Route::put('/configuration/units/{unitId}/rate-intervals/{intervalId}', [UnitRateIntervalController::class, 'update']);
        Route::delete('/configuration/units/{unitId}/rate-intervals/{intervalId}', [UnitRateIntervalController::class, 'destroy']);

        Route::get('/configuration/notifications', [NotificationSettingsController::class, 'show']);
        Route::put('/configuration/notifications', [NotificationSettingsController::class, 'update']);

        Route::get('/account', [AccountSettingsController::class, 'show']);
        Route::put('/account', [AccountSettingsController::class, 'update']);
        Route::put('/account/password', [AccountSettingsController::class, 'updatePassword']);
        Route::get('/account/payment', [PaymentSettingsController::class, 'show']);
        Route::put('/account/payment', [PaymentSettingsController::class, 'update']);
        Route::get('/getting-started', [GettingStartedController::class, 'show']);
        Route::post('/getting-started/dismiss', [GettingStartedController::class, 'dismiss']);

        Route::get('/configuration/team', [TeamMemberController::class, 'index']);
        Route::patch('/configuration/team/{id}', [TeamMemberController::class, 'update']);
        Route::delete('/configuration/team/{id}', [TeamMemberController::class, 'destroy']);
        Route::post('/configuration/team/invite', [TeamMemberController::class, 'invite']);

        Route::get('/booking-portals', [BookingPortalController::class, 'index']);
        Route::get('/booking-portals/direct-website/settings', [BookingPortalController::class, 'directWebsiteSettings']);
        Route::post('/booking-portals/direct-website/hero-image', [BookingPortalController::class, 'uploadDirectWebsiteHero']);
        Route::patch('/booking-portals/direct-website/content', [BookingPortalController::class, 'saveDirectWebsiteContent']);
        Route::post('/booking-portals/direct-website/design', [BookingPortalController::class, 'saveDirectWebsiteDesign']);
        Route::patch('/booking-portals/direct-website/live', [BookingPortalController::class, 'setDirectWebsiteLive']);
        Route::get('/booking-portals/available', [BookingPortalController::class, 'available']);
        Route::patch('/booking-portals/{portalKey}/active', [BookingPortalController::class, 'updateActive'])
            ->where('portalKey', '[a-z_]+');
        Route::post('/booking-portals/{portalKey}/sync', [BookingPortalController::class, 'sync'])
            ->where('portalKey', '[a-z_]+');
        Route::post('/booking-portals/{portalKey}/connect', [BookingPortalController::class, 'connect'])
            ->where('portalKey', '[a-z_]+');

        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/analytics', [AnalyticsController::class, 'summary']);
        Route::get('/analytics/export', [AnalyticsController::class, 'export']);

        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{id}/available-units', [BookingController::class, 'availableUnits']);
        Route::get('/bookings/{id}/payments', [BookingPaymentController::class, 'index']);
        Route::post('/bookings/{id}/payments', [BookingPaymentController::class, 'store']);
        Route::get('/bookings/{id}', [BookingController::class, 'show']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::patch('/bookings/{id}', [BookingController::class, 'update']);
        Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);

        Route::get('/calendar', [CalendarController::class, 'index']);
        Route::get('/unit-date-blocks', [UnitDateBlockController::class, 'index']);
        Route::post('/unit-date-blocks', [UnitDateBlockController::class, 'store']);
        Route::patch('/unit-date-blocks/{id}', [UnitDateBlockController::class, 'update']);
        Route::delete('/unit-date-blocks/{id}', [UnitDateBlockController::class, 'destroy']);

        Route::get('/discounts/promo-codes', [PromoCodeController::class, 'index']);
        Route::post('/discounts/promo-codes', [PromoCodeController::class, 'store']);
        Route::put('/discounts/promo-codes/{id}', [PromoCodeController::class, 'update']);
        Route::delete('/discounts/promo-codes/{id}', [PromoCodeController::class, 'destroy']);

        Route::get('/discounts/unit-discounts', [UnitDiscountController::class, 'index']);
        Route::post('/discounts/unit-discounts', [UnitDiscountController::class, 'store']);
        Route::put('/discounts/unit-discounts/{id}', [UnitDiscountController::class, 'update']);
        Route::delete('/discounts/unit-discounts/{id}', [UnitDiscountController::class, 'destroy']);

        Route::get('/discounts/promotions', [PromotionController::class, 'index']);
        Route::post('/discounts/promotions', [PromotionController::class, 'store']);
        Route::put('/discounts/promotions/{id}', [PromotionController::class, 'update']);
        Route::delete('/discounts/promotions/{id}', [PromotionController::class, 'destroy']);
        Route::post('/discounts/promotions/{id}/toggle', [PromotionController::class, 'toggle']);

        Route::get('/subscription', [MerchantSubscriptionController::class, 'show']);
        Route::get('/subscription/upcoming-invoice', [MerchantSubscriptionController::class, 'upcomingInvoice']);
        Route::get('/subscription/payments', [MerchantSubscriptionController::class, 'payments']);
        Route::post('/subscription/pay', [MerchantSubscriptionController::class, 'pay']);
        Route::post('/subscription/checkout', [MerchantSubscriptionController::class, 'checkout']);
        Route::post('/subscription/checkout/confirm', [MerchantSubscriptionController::class, 'confirmCheckout']);
        Route::post('/subscription/features/toggle', [MerchantSubscriptionController::class, 'toggle']);

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/messages', [PortalMessageController::class, 'merchantIndex']);
        Route::post('/messages/{customerUuid}/read', [PortalMessageController::class, 'merchantRead']);
        Route::post('/messages/{customerUuid}', [PortalMessageController::class, 'merchantReply']);
    });
});
