<?php

use App\Http\Controllers\Api\V1\AdvertisementController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatteryController;
use App\Http\Controllers\Api\V1\ChargerLocationController;
use App\Http\Controllers\Api\V1\ChargingSessionController;
use App\Http\Controllers\Api\V1\ContributorController;
use App\Http\Controllers\Api\V1\DualSourceLocationController;
use App\Http\Controllers\Api\V1\FuelPriceController;
use App\Http\Controllers\Api\V1\HomeChargingDiscountController;
use App\Http\Controllers\Api\V1\LocationCategoryController;
use App\Http\Controllers\Api\V1\LocationReportController;
use App\Http\Controllers\Api\V1\MonetizationController;
use App\Http\Controllers\Api\V1\PlnChargerLocationController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\ScrapeIngestController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\SpkluLocationController;
use App\Http\Controllers\Api\V1\SpkluSourceComparisonController;
use App\Http\Controllers\Api\V1\StateOfHealthController;
use App\Http\Controllers\Api\V1\StationPhotoController;
use App\Http\Controllers\Api\V1\StationReviewController;
use App\Http\Controllers\Api\V1\TesterController;
use App\Http\Controllers\Api\V1\SavedStationController;
use App\Http\Controllers\Api\V1\ServiceLogController;
use App\Http\Controllers\Api\V1\ServiceLogPhotoController;
use App\Http\Controllers\Api\V1\OcmNearbyController;
use App\Http\Controllers\Api\V1\UserChargerLocationController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleMarketController;
use App\Http\Controllers\Api\V1\ObdAccessController;
use App\Http\Controllers\Api\V1\ObdProfileController;
use App\Http\Controllers\Api\V1\ObdCompatibilityReportController;
use App\Http\Controllers\Api\V1\ObdAdapterProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/verify-otp', [AuthController::class, 'confirmVerification']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // Public EV data routes
    Route::get('/charging-locations/nearby', [ChargerLocationController::class, 'nearby']);
    Route::get('/charging-locations', [ChargerLocationController::class, 'index']);
    Route::get('/charging-locations/{chargerLocation}', [ChargerLocationController::class, 'show']);
    Route::get('/pln-charging-locations', [PlnChargerLocationController::class, 'index']);
    Route::get('/location-categories', [LocationCategoryController::class, 'index']);

    // SPKLU data routes (from data_spklu.json)
    Route::get('/spklu', [SpkluLocationController::class, 'index']);
    Route::get('/spklu/{id}', [SpkluLocationController::class, 'show']);
    Route::get('/meta/filters', [SpkluLocationController::class, 'metaFilters']);
    Route::get('/meta/spklu-sync', [SpkluLocationController::class, 'syncManifest']);

    // OpenChargeMap nearby — serve lokal + auto-harvest titik baru (lazy-sync)
    Route::get('/ocm/nearby', [OcmNearbyController::class, 'nearby']);

    // Data pasar kendaraan (sumber: import GAIKINDO) — dipindah ke auth:sanctum (Revisi 2, poin 4)

    // Station reviews (Fase 1) — list & summary publik; eligibility/store/delete auth
    Route::get('/stations/{station}/reviews', [StationReviewController::class, 'index']);
    Route::get('/stations/{station}/reviews/summary', [StationReviewController::class, 'summary']);

    // Station photos (Fase 2) — list publik; upload/delete auth
    Route::get('/stations/{station}/photos', [StationPhotoController::class, 'index']);

    // Providers
    // Social auth (no middleware — public endpoints)
    Route::post('/auth/google', [SocialAuthController::class, 'googleLogin']);
    Route::post('/auth/apple', [SocialAuthController::class, 'appleLogin']);

    Route::get('/providers', [ProviderController::class, 'index']);
    Route::get('/providers/all', [ProviderController::class, 'all']);

    // Public advertisement routes (for displaying ads to users and tracking metrics)
    Route::get('/ads/mobile', [AdvertisementController::class, 'mobile']);
    Route::get('/ads/web', [AdvertisementController::class, 'web']);
    Route::post('/advertisements/{advertisement}/impression', [AdvertisementController::class, 'recordImpression']);
    Route::post('/advertisements/{advertisement}/click', [AdvertisementController::class, 'recordClick']);

    // Testing funnel — app config & ping build PUBLIK (install fresh belum login)
    Route::get('/app/config', [TesterController::class, 'appConfig'])->middleware('throttle:30,1');
    Route::post('/testers/ping', [TesterController::class, 'ping'])->middleware('throttle:30,1');

    // Email gate tester (app Islam) — PUBLIK tanpa login, idempotent per device.
    Route::post('/testers/register-email', [TesterController::class, 'registerEmail'])->middleware('throttle:10,1');

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Authentication
        Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Social auth status & logout
        Route::get('/auth/status', [SocialAuthController::class, 'status']);
        Route::post('/auth/social-logout', [SocialAuthController::class, 'logout']);

        // Akun (soft-delete + anonimisasi PII, lihat AccountController::destroy)
        Route::delete('/account', [AccountController::class, 'destroy']);

        // Monetization / entitlement server-side (per akun app)
        Route::get('/monetization/config', [MonetizationController::class, 'config']);
        Route::post('/monetization/register-apple', [MonetizationController::class, 'registerApple']);
        Route::post('/monetization/register-google', [MonetizationController::class, 'registerGoogle']);

        // User-specific routes
        Route::get('/vehicles/options', [VehicleController::class, 'options']);
        Route::apiResource('vehicles', VehicleController::class);
        Route::apiResource('charging-locations', ChargerLocationController::class)->except(['index', 'show']);
        Route::apiResource('my/charging-locations', UserChargerLocationController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('/my/charging-locations/{chargingLocation}/chargers', [UserChargerLocationController::class, 'addCharger']);
        Route::get('/my/charger-references', [UserChargerLocationController::class, 'references']);
        Route::get('/charging-sessions/analytics', [ChargingSessionController::class, 'analytics']);
        Route::get('/charging-sessions/latest', [ChargingSessionController::class, 'latest']);
        Route::get('/charging-sessions/journey', [ChargingSessionController::class, 'journey']);
        Route::apiResource('charging-sessions', ChargingSessionController::class);
        Route::get('/fuel-prices', [FuelPriceController::class, 'index']);
        Route::apiResource('state-of-health', StateOfHealthController::class);
        Route::get('/state-of-health/{vehicleId}/trend-analysis', [StateOfHealthController::class, 'trendAnalysis']);
        Route::apiResource('batteries', BatteryController::class);
        Route::apiResource('service-logs', ServiceLogController::class);
        Route::post('/service-logs/{serviceLog}/photos', [ServiceLogPhotoController::class, 'store']);
        Route::delete('/service-log-photos/{photo}', [ServiceLogPhotoController::class, 'destroy']);
        Route::post('/vehicles/{vehicle}/swap-battery', [BatteryController::class, 'swap']);
        Route::apiResource('home-charging-discounts', HomeChargingDiscountController::class);
        Route::post('/home-charging-discounts/apply', [HomeChargingDiscountController::class, 'apply']);

        // Analytics
        Route::get('/analytics/charging-patterns', [AnalyticsController::class, 'chargingPatterns']);
        Route::get('/analytics/cost-analysis', [AnalyticsController::class, 'costAnalysis']);
        Route::get('/analytics/reports', [AnalyticsController::class, 'reports']);
        Route::get('/analytics/visitor-profiles', [AnalyticsController::class, 'visitorProfiles']);

        // Dual-source location management (admin routes)
        Route::prefix('admin')->group(function () {
            Route::post('/pln-locations/import', [DualSourceLocationController::class, 'importPlnLocations']);
            Route::put('/pln-locations/{plnLocation}', [DualSourceLocationController::class, 'updatePlnLocation']);
            Route::get('/community-locations/pending', [DualSourceLocationController::class, 'getPendingCommunityLocations']);
            Route::post('/community-locations/{chargerLocation}/verify', [DualSourceLocationController::class, 'verifyCommunityLocation']);
            Route::post('/community-locations/{chargerLocation}/reject', [DualSourceLocationController::class, 'rejectCommunityLocation']);
            Route::get('/locations/duplicates', [DualSourceLocationController::class, 'detectDuplicates']);
            Route::post('/locations/consolidate', [DualSourceLocationController::class, 'consolidateLocations']);

// Location reports
            Route::get('/reports/pending', [LocationReportController::class, 'getPendingReports']);

            // Scrape ingestion (Chrome extension)
            Route::post('/scrape/ingest', [ScrapeIngestController::class, 'ingest']);

            // Source comparison (admin)
            Route::get('/spklu-source-comparison', [SpkluSourceComparisonController::class, 'index']);
        });

        // Community location submission
        Route::post('/community-locations', [DualSourceLocationController::class, 'submitCommunityLocation']);

        // Location reporting
        Route::post('/locations/{chargerLocation}/report', [LocationReportController::class, 'reportLocation']);
        Route::get('/locations/{chargerLocation}/reports', [LocationReportController::class, 'getLocationReports']);
        Route::post('/reports/{report}/process', [LocationReportController::class, 'processReport']);

        // Contributor management
        Route::get('/contributors/profile', [ContributorController::class, 'profile']);
        Route::get('/contributors/leaderboard', [ContributorController::class, 'leaderboard']);
        Route::get('/contributors/{id}/history', [ContributorController::class, 'history']);

        // Advertisement management (admin routes)
        Route::apiResource('advertisements', AdvertisementController::class);

        // Station reviews — auth (eligibility/store) + admin (delete)
        Route::get('/stations/{station}/reviews/eligibility', [StationReviewController::class, 'eligibility']);
        Route::post('/stations/{station}/reviews', [StationReviewController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/stations/{station}/reviews/{review}', [StationReviewController::class, 'destroy'])->scopeBindings();

        // Station photos (Fase 2) — auth upload (multipart, gate completed-session) + admin delete
        Route::post('/stations/{station}/photos', [StationPhotoController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/stations/{station}/photos/{photo}', [StationPhotoController::class, 'destroy'])->scopeBindings();

        // Saved stations / bookmark (Fase 3 — Peta User) — toggle/check/index.
        Route::post('/stations/{station}/save', [SavedStationController::class, 'toggle']);
        Route::get('/stations/{station}/save', [SavedStationController::class, 'check']);
        Route::get('/me/saved-stations', [SavedStationController::class, 'index']);

        // Data pasar kendaraan (sumber: import GAIKINDO) — auth required (Revisi 2, poin 4)
        Route::get('/vehicle-market/meta', [VehicleMarketController::class, 'meta']);
        Route::get('/vehicle-market/summary', [VehicleMarketController::class, 'summary']);
        Route::get('/vehicle-market/trend', [VehicleMarketController::class, 'trend']);
        Route::get('/vehicle-market/top', [VehicleMarketController::class, 'top']);
        Route::get('/vehicle-market/catalog', [VehicleMarketController::class, 'catalog']);
        Route::get('/vehicle-market/composition', [VehicleMarketController::class, 'composition']);
        Route::get('/vehicle-market/model-history', [VehicleMarketController::class, 'modelHistory']);

        // Testing funnel — register tester (auth)
        Route::post('/testers/register', [TesterController::class, 'register'])->middleware('throttle:10,1');

        // OBD2 routes (murni aditif, kill switch via config/obd.php)
        Route::prefix('obd')->group(function () {
            Route::get('/access', [ObdAccessController::class, 'index']);
            Route::get('/profiles', [ObdProfileController::class, 'index']);
            Route::post('/compatibility-reports', [ObdCompatibilityReportController::class, 'store']);
            Route::get('/adapter-products', [ObdAdapterProductController::class, 'index']);
        });
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
