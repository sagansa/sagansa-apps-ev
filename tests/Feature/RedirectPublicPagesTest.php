<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectPublicPages;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proteksi halaman publik situs marketing (spec
 * docs/superpowers/specs/2026-11-19-protect-public-pages-remove-signup-design.md):
 * semua route halaman publik dibungkus middleware RedirectPublicPages, sehingga
 * akses manual (mis. /products) dialihkan 302 ke homepage. Hanya homepage dan
 * /admin/login yang boleh diakses publik; route pendukung mobile app dan
 * endpoint AJAX homepage tidak ikut grup.
 */
class RedirectPublicPagesTest extends TestCase
{
    private const PROTECTED_PATHS = [
        '/pln-map',
        '/map',
        '/providers',
        '/products',
        '/contact',
        '/training',
        '/chargers',
        '/pln-chargers',
        '/youtube',
        '/youtube/y-123',
        '/find-nearby-chargers',
    ];

    public function test_halaman_publik_dialihkan_302_ke_homepage(): void
    {
        foreach (self::PROTECTED_PATHS as $path) {
            $response = $this->get($path);

            $response->assertStatus(302);
            $response->assertRedirect(route('home'));
        }
    }

    public function test_route_halaman_publik_terbungkus_middleware(): void
    {
        $wrappedNames = [
            'pln-map',
            'map',
            'providers',
            'products',
            'contact',
            'training',
            'chargers',
            'pln-chargers',
            'youtube.index',
            'youtube.show',
            'find.nearby.chargers.page',
        ];

        foreach ($wrappedNames as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} harus tetap terdaftar.");
            $this->assertContains(
                RedirectPublicPages::class,
                $route->gatherMiddleware(),
                "Route {$name} harus dibungkus RedirectPublicPages.",
            );
        }
    }

    public function test_route_pendukung_mobile_dan_ajax_homepage_tidak_terbungkus(): void
    {
        $exemptNames = [
            'home',                // homepage tetap hidup
            'app.download',        // QR download — pendukung mobile
            'terms.short',         // link paywall App Store
            'privacy.short',       // link paywall App Store
            'sitemap',             // sitemap.xml
            'verification.verify', // verifikasi email user mobile
            'get.cities',          // AJAX homepage
            'get.type.chargers',   // AJAX homepage
            'get.power.chargers',  // AJAX homepage
        ];

        foreach ($exemptNames as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} harus tetap ada.");
            $this->assertNotContains(
                RedirectPublicPages::class,
                $route->gatherMiddleware(),
                "Route {$name} tidak boleh dibungkus RedirectPublicPages.",
            );
        }
    }
}
