<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppDownloadController;
use App\Http\Controllers\EmailLinkVerificationController;
use App\Http\Controllers\EvController;
use App\Http\Controllers\PlnChargerLocationController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TesterExportController;
use App\Http\Controllers\YouTubeCollectionController;
use Filament\Http\Controllers\Auth\LoginController;
use Filament\Http\Controllers\Auth\RegisterController;

// Route::get('/', function () {
//     return view('ev
//     ');
// });

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

// Export CSV tester (Closed Testing) — role admin dicek di controller.
Route::get('/admin/testers/export', [TesterExportController::class, 'csv'])
    ->middleware('auth:sanctum')
    ->name('admin.testers.export');

// Verifikasi email via link sekali-tap bawaan Laravel (kontrak MustVerifyEmail):
// notifikasi VerifyEmail membangun URL bertanda tangan atas nama route
// 'verification.verify' ({id}/{hash sha1 email} + expires). Path dipertahankan
// agar link dari email lama tetap sah. Publik — signature URL mengotentikasi,
// tanpa session web, karena penerima adalah user aplikasi mobile yang belum login.
Route::get('/email/verify-link/{id}/{hash}', [EmailLinkVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('verification.verify');

Route::get('/', [EvController::class, 'plnMap'])->name('home');

// QR pintar download app — konten QR di popup desktop mengarah ke sini agar
// OS ponsel pemindai dideteksi saat link dibuka (lihat AppDownloadController).
Route::get('/download', AppDownloadController::class)->name('app.download');

// Route::get('/', [PlnChargerLocationController::class, 'index'])->name('home');
Route::get('/pln-map', [EvController::class, 'plnMap'])->name('pln-map');
Route::get('/map', [EvController::class, 'map'])->name('map');
Route::get('/providers', [EvController::class, 'providers'])->name('providers');
Route::get('/products', [EvController::class, 'products'])->name('products');
Route::get('/contact', [EvController::class, 'contact'])->name('contact');
Route::get('/training', [EvController::class, 'training'])->name('training');
Route::get('/chargers', [EvController::class, 'chargers'])->name('chargers');
Route::get('/pln-chargers', [EvController::class, 'plnChargers'])->name('pln-chargers');
Route::get('/get-cities/{province}', [EvController::class, 'getCities'])->name('get.cities');
Route::get('/get-type-chargers/{current}', [EvController::class, 'getTypeChargers'])->name('get.type.chargers');
Route::get('/get-power-chargers/{type}', [EvController::class, 'getPowerChargers'])->name('get.power.chargers');
Route::get('/get-provider-details/{provider}', [EvController::class, 'getProviderDetails']);
Route::get('/filter-pln-locations/{chargingType?}/{locationCategory?}', [EvController::class, 'filterPlnLocations'])->name('filter.pln.locations');

// YouTube Collection Routes
Route::get('/youtube', [YouTubeCollectionController::class, 'index'])->name('youtube.index');
Route::get('/youtube/{id}', [YouTubeCollectionController::class, 'show'])->name('youtube.show');

// Address Search Route
Route::post('/search-address', [EvController::class, 'searchAddress'])->name('search.address');

// Find Nearby Chargers Route
Route::post('/find-nearby-chargers', [EvController::class, 'findNearbyChargers'])->name('find.nearby.chargers');

// Show Find Nearby Chargers Page
Route::get('/find-nearby-chargers', [EvController::class, 'showFindNearbyChargers'])->name('find.nearby.chargers.page');

// Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Syarat & Ketentuan + Kebijakan Privasi — URL pendek untuk link paywall
// (wajib bagi auto-renewable subscription — App Store Guideline 3.1.2).
// Alih ke controller Jetstream yang sama dengan /terms-of-service & /privacy-policy.
Route::get('/terms', [\Laravel\Jetstream\Http\Controllers\Livewire\TermsOfServiceController::class, 'show'])
    ->name('terms.short');
Route::get('/privacy', [\Laravel\Jetstream\Http\Controllers\Livewire\PrivacyPolicyController::class, 'show'])
    ->name('privacy.short');
