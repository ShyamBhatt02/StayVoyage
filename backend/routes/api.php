<?php

use App\Http\Controllers\Api\V1\Admin\AvailabilityController;
use App\Http\Controllers\Api\V1\Admin\BookingAdminController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\DestinationController as AdminDestinationController;
use App\Http\Controllers\Api\V1\Admin\HotelController as AdminHotelController;
use App\Http\Controllers\Api\V1\Admin\MarketingController;
use App\Http\Controllers\Api\V1\Admin\MembershipAdminController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\TourAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // ================================================================ public
    Route::get('meta/config', [MetaController::class, 'config']);
    Route::get('meta/home', [MetaController::class, 'home']);
    Route::get('faqs', [MetaController::class, 'faqs']);
    Route::get('offers', [MetaController::class, 'offers']);
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:contact');

    Route::get('destinations', [DestinationController::class, 'index']);
    Route::get('destinations/{slug}', [DestinationController::class, 'show']);

    Route::get('hotels', [HotelController::class, 'index'])->middleware('throttle:search');
    Route::get('hotels/{slug}', [HotelController::class, 'show']);
    Route::get('hotels/{slug}/reviews', [HotelController::class, 'reviews']);
    Route::post('hotels/quote', [HotelController::class, 'quote'])->middleware('throttle:search');

    Route::get('tours', [TourController::class, 'packages']);
    Route::get('tours/{slug}', [TourController::class, 'package']);
    Route::get('catalogue/{kind}', [TourController::class, 'catalogue'])->whereIn('kind', ['activities', 'guides', 'transportation', 'meals', 'hotels']);
    Route::get('trips/shared/{token}', [TripController::class, 'publicShow'])->where('token', '[a-z0-9]{32}');

    Route::get('membership/plans', [MembershipController::class, 'plans']);
    Route::get('payments/gateways', [PaymentController::class, 'gateways']);

    // Gateway webhooks (signature-verified, CSRF-exempt)
    Route::post('webhooks/{gateway}', [PaymentController::class, 'webhook'])->withoutMiddleware('throttle:api');

    // ================================================================ auth
    Route::prefix('auth')->group(function () {
        Route::get('session', [AuthController::class, 'session']);
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-sensitive');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-sensitive');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-sensitive');
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify')->middleware('throttle:auth-sensitive');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:auth-sensitive');
        });
    });

    // ================================================================ customer
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('dashboard', DashboardController::class);

        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('profile/avatar', [ProfileController::class, 'avatar']);
        Route::put('profile/password', [ProfileController::class, 'changePassword'])->middleware('throttle:auth-sensitive');
        Route::post('profile/two-factor', [ProfileController::class, 'twoFactorSetup']);
        Route::post('profile/two-factor/confirm', [ProfileController::class, 'twoFactorConfirm'])->middleware('throttle:two-factor');
        Route::delete('profile/two-factor', [ProfileController::class, 'twoFactorDisable'])->middleware('throttle:auth-sensitive');

        Route::get('saved-hotels', [HotelController::class, 'saved']);
        Route::post('hotels/{slug}/save', [HotelController::class, 'toggleSave']);

        // Memberships & wallet
        Route::post('membership/quote', [MembershipController::class, 'quote']);
        Route::post('membership/purchase', [MembershipController::class, 'purchase'])->middleware('throttle:payment');
        Route::get('membership/current', [MembershipController::class, 'current']);
        Route::get('membership/history', [MembershipController::class, 'history']);
        Route::get('membership/transactions', [MembershipController::class, 'transactions']);
        Route::put('membership/auto-renew', [MembershipController::class, 'autoRenew']);
        Route::get('membership/{number}/invoice', [PaymentController::class, 'membershipInvoice']);

        // Hotel bookings
        Route::get('bookings', [BookingController::class, 'index']);
        Route::post('bookings/hotel', [BookingController::class, 'storeHotel'])->middleware('throttle:booking');
        Route::get('bookings/{number}', [BookingController::class, 'show']);
        Route::get('bookings/{number}/cancellation-preview', [BookingController::class, 'cancellationPreview']);
        Route::post('bookings/{number}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:booking');
        Route::post('bookings/{number}/pay', [BookingController::class, 'pay'])->middleware('throttle:payment');
        Route::get('bookings/{number}/invoice', [BookingController::class, 'invoice']);
        Route::post('bookings/{number}/review', [ReviewController::class, 'store']);
        Route::get('my-reviews', [ReviewController::class, 'mine']);

        // Payments
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{reference}', [PaymentController::class, 'show']);
        Route::post('payments/{reference}/verify', [PaymentController::class, 'verify'])->middleware('throttle:payment');
        Route::post('payments/{reference}/sandbox-complete', [PaymentController::class, 'sandboxComplete'])->middleware('throttle:payment');

        // Tour builder
        Route::get('trips', [TripController::class, 'index']);
        Route::post('trips', [TripController::class, 'store']);
        Route::post('trips/from-package/{slug}', [TripController::class, 'fromPackage']);
        Route::post('trips/shared/{token}/copy', [TripController::class, 'copyShared'])->where('token', '[a-z0-9]{32}');
        Route::get('trips/{trip}', [TripController::class, 'show']);
        Route::patch('trips/{trip}', [TripController::class, 'update']);
        Route::delete('trips/{trip}', [TripController::class, 'destroy']);
        Route::post('trips/{trip}/duplicate', [TripController::class, 'duplicate']);
        Route::post('trips/{trip}/share', [TripController::class, 'share']);
        Route::post('trips/{trip}/days', [TripController::class, 'addDay']);
        Route::patch('trips/{trip}/days/{day}', [TripController::class, 'updateDay']);
        Route::delete('trips/{trip}/days/{day}', [TripController::class, 'deleteDay']);
        Route::put('trips/{trip}/days/order', [TripController::class, 'reorderDays']);
        Route::post('trips/{trip}/items', [TripController::class, 'addItem']);
        Route::patch('trips/{trip}/items/{item}', [TripController::class, 'updateItem']);
        Route::delete('trips/{trip}/items/{item}', [TripController::class, 'deleteItem']);
        Route::post('trips/{trip}/items/{item}/duplicate', [TripController::class, 'duplicateItem']);
        Route::put('trips/{trip}/items/order', [TripController::class, 'reorderItems']);
        Route::post('trips/{trip}/quote', [TripController::class, 'quote']);
        Route::post('trips/{trip}/book', [TripController::class, 'book'])->middleware('throttle:booking');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    });

    // ================================================================ admin (staff)
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:super-admin,admin', 'admin.2fa'])->group(function () {
        Route::get('dashboard', AdminDashboardController::class)->middleware('permission:dashboard.view');

        Route::middleware('permission:hotels.view,hotels.manage')->group(function () {
            Route::get('hotels', [AdminHotelController::class, 'index']);
            Route::get('hotels/{hotel}', [AdminHotelController::class, 'show']);
            Route::get('hotel-options', [BookingAdminController::class, 'hotelOptions']);
            Route::get('partner-candidates', [AdminHotelController::class, 'partnerCandidates']);
        });
        Route::middleware('permission:hotels.manage')->group(function () {
            Route::post('hotels', [AdminHotelController::class, 'store']);
            Route::put('hotels/{hotel}', [AdminHotelController::class, 'update']);
            Route::delete('hotels/{hotel}', [AdminHotelController::class, 'destroy']);
            Route::post('hotels/{hotel}/images', [AdminHotelController::class, 'images']);
            Route::delete('hotels/{hotel}/images/{image}', [AdminHotelController::class, 'deleteImage']);
            Route::post('hotels/{hotel}/images/{image}/cover', [AdminHotelController::class, 'setCover']);
            Route::get('hotels/{hotel}/rooms', [RoomController::class, 'index']);
            Route::post('hotels/{hotel}/rooms', [RoomController::class, 'store']);
            Route::put('hotels/{hotel}/rooms/{room}', [RoomController::class, 'update']);
            Route::delete('hotels/{hotel}/rooms/{room}', [RoomController::class, 'destroy']);
            Route::post('hotels/{hotel}/rooms/{room}/images', [RoomController::class, 'images']);
            Route::delete('hotels/{hotel}/rooms/{room}/images/{image}', [RoomController::class, 'deleteImage']);
            Route::get('amenities', [AdminDestinationController::class, 'amenities']);
            Route::post('amenities', [AdminDestinationController::class, 'storeAmenity']);
            Route::delete('amenities/{amenity}', [AdminDestinationController::class, 'destroyAmenity']);
        });
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::get('hotels/{hotel}/rooms/{room}/availability', [AvailabilityController::class, 'calendar']);
            Route::put('hotels/{hotel}/rooms/{room}/availability', [AvailabilityController::class, 'update']);
            Route::post('hotels/{hotel}/rooms/{room}/seasons', [RoomController::class, 'storeSeason']);
            Route::delete('hotels/{hotel}/rooms/{room}/seasons/{season}', [RoomController::class, 'deleteSeason']);
        });

        Route::middleware('permission:destinations.manage')->group(function () {
            Route::get('destinations', [AdminDestinationController::class, 'index']);
            Route::post('destinations', [AdminDestinationController::class, 'store']);
            Route::post('destinations/{destination}', [AdminDestinationController::class, 'update']); // POST for multipart uploads
            Route::delete('destinations/{destination}', [AdminDestinationController::class, 'destroy']);
        });

        Route::middleware('permission:tours.manage')->group(function () {
            Route::get('tour-packages', [TourAdminController::class, 'packages']);
            Route::get('tour-packages/{package}', [TourAdminController::class, 'package']);
            Route::post('tour-packages', [TourAdminController::class, 'storePackage']);
            Route::post('tour-packages/{package}', [TourAdminController::class, 'updatePackage']);
            Route::delete('tour-packages/{package}', [TourAdminController::class, 'destroyPackage']);
            Route::get('catalogue/{kind}', [TourAdminController::class, 'catalogue']);
            Route::post('catalogue/{kind}', [TourAdminController::class, 'storeCatalogue']);
            Route::post('catalogue/{kind}/{id}', [TourAdminController::class, 'updateCatalogue'])->whereNumber('id');
            Route::delete('catalogue/{kind}/{id}', [TourAdminController::class, 'destroyCatalogue'])->whereNumber('id');
        });

        Route::get('membership-plans', [MembershipAdminController::class, 'plans'])->middleware('permission:memberships.view,memberships.manage');
        Route::middleware('permission:memberships.manage')->group(function () {
            Route::post('membership-plans', [MembershipAdminController::class, 'storePlan']);
            Route::put('membership-plans/{plan}', [MembershipAdminController::class, 'updatePlan']);
            Route::post('membership-plans/{plan}/blackouts', [MembershipAdminController::class, 'addBlackout']);
            Route::delete('membership-plans/{plan}/blackouts/{blackout}', [MembershipAdminController::class, 'removeBlackout']);
        });
        Route::middleware('permission:memberships.view')->group(function () {
            Route::get('memberships', [MembershipAdminController::class, 'memberships']);
            Route::get('memberships/{membership}', [MembershipAdminController::class, 'membership']);
        });
        Route::post('memberships/{membership}/adjust', [MembershipAdminController::class, 'adjust'])->middleware('permission:memberships.adjust');

        Route::middleware('permission:bookings.view')->group(function () {
            Route::get('bookings', [BookingAdminController::class, 'index']);
            Route::get('bookings/{booking}', [BookingAdminController::class, 'show']);
        });
        Route::middleware('permission:bookings.manage')->group(function () {
            Route::get('bookings/{booking}/cancellation-preview', [BookingAdminController::class, 'cancellationPreview']);
            Route::post('bookings/{booking}/cancel', [BookingAdminController::class, 'cancel']);
        });
        Route::get('payments', [BookingAdminController::class, 'payments'])->middleware('permission:payments.view');
        Route::post('payments/{payment}/refund', [BookingAdminController::class, 'refund'])->middleware('permission:refunds.manage');

        Route::middleware('permission:users.view')->group(function () {
            Route::get('users', [UserAdminController::class, 'index']);
            Route::get('users/{user}', [UserAdminController::class, 'show']);
        });
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('users/{user}/suspend', [UserAdminController::class, 'suspend']);
            Route::post('users/{user}/activate', [UserAdminController::class, 'activate']);
        });
        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('roles', [UserAdminController::class, 'roles']);
            Route::put('roles/{slug}/permissions', [UserAdminController::class, 'updateRolePermissions']);
            Route::post('users', [UserAdminController::class, 'storeStaff']);
            Route::put('users/{user}/roles', [UserAdminController::class, 'updateRoles']);
        });

        Route::middleware('permission:coupons.manage')->group(function () {
            Route::get('coupons', [MarketingController::class, 'coupons']);
            Route::post('coupons', [MarketingController::class, 'storeCoupon']);
            Route::put('coupons/{coupon}', [MarketingController::class, 'updateCoupon']);
            Route::delete('coupons/{coupon}', [MarketingController::class, 'destroyCoupon']);
        });
        Route::middleware('permission:reviews.moderate')->group(function () {
            Route::get('reviews', [MarketingController::class, 'reviews']);
            Route::post('reviews/{review}/moderate', [MarketingController::class, 'moderateReview']);
            Route::delete('reviews/{review}', [MarketingController::class, 'destroyReview']);
        });
        Route::middleware('permission:content.manage')->group(function () {
            Route::get('faqs', [MarketingController::class, 'faqs']);
            Route::post('faqs', [MarketingController::class, 'saveFaq']);
            Route::put('faqs/{faq}', [MarketingController::class, 'saveFaq']);
            Route::delete('faqs/{faq}', [MarketingController::class, 'destroyFaq']);
            Route::get('messages', [MarketingController::class, 'messages']);
            Route::put('messages/{message}', [MarketingController::class, 'updateMessage']);
        });

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/{type}', [ReportController::class, 'show'])->whereIn('type', ['revenue', 'bookings', 'memberships', 'hotels', 'tours', 'customers', 'refunds']);
            Route::get('reports/{type}/export', [ReportController::class, 'export'])->whereIn('type', ['revenue', 'bookings', 'memberships', 'hotels', 'tours', 'customers', 'refunds']);
        });
        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings', [SettingsController::class, 'update']);
        });
        Route::get('audit-logs', [SettingsController::class, 'auditLogs'])->middleware('permission:audit.view');
    });

    // ================================================================ hotel partner portal
    // Same controllers as admin; HotelPolicy limits every action to the partner's own hotels.
    Route::prefix('partner')->middleware(['auth:sanctum', 'role:hotel-partner'])->group(function () {
        Route::get('hotels', [AdminHotelController::class, 'index']);
        Route::get('hotels/{hotel}', [AdminHotelController::class, 'show']);
        Route::put('hotels/{hotel}', [AdminHotelController::class, 'update']);
        Route::post('hotels/{hotel}/images', [AdminHotelController::class, 'images']);
        Route::delete('hotels/{hotel}/images/{image}', [AdminHotelController::class, 'deleteImage']);
        Route::post('hotels/{hotel}/images/{image}/cover', [AdminHotelController::class, 'setCover']);
        Route::get('hotels/{hotel}/rooms', [RoomController::class, 'index']);
        Route::post('hotels/{hotel}/rooms', [RoomController::class, 'store']);
        Route::put('hotels/{hotel}/rooms/{room}', [RoomController::class, 'update']);
        Route::post('hotels/{hotel}/rooms/{room}/images', [RoomController::class, 'images']);
        Route::delete('hotels/{hotel}/rooms/{room}/images/{image}', [RoomController::class, 'deleteImage']);
        Route::get('hotels/{hotel}/rooms/{room}/availability', [AvailabilityController::class, 'calendar']);
        Route::put('hotels/{hotel}/rooms/{room}/availability', [AvailabilityController::class, 'update']);
        Route::post('hotels/{hotel}/rooms/{room}/seasons', [RoomController::class, 'storeSeason']);
        Route::delete('hotels/{hotel}/rooms/{room}/seasons/{season}', [RoomController::class, 'deleteSeason']);
        Route::get('amenities', [AdminDestinationController::class, 'amenities']);
        Route::get('bookings', [BookingAdminController::class, 'index']);
        Route::get('bookings/{booking}', [BookingAdminController::class, 'show']);
        Route::get('my-hotels', [BookingAdminController::class, 'partnerHotels']);
    });
});
