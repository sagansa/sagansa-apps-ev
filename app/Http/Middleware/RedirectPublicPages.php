<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proteksi halaman publik situs marketing: semua akses ke route halaman
 * publik (mis. /products, /contact) dialihkan ke homepage. Hanya homepage
 * dan /admin/login yang boleh diakses publik — lihat spec
 * docs/superpowers/specs/2026-11-19-protect-public-pages-remove-signup-design.md.
 *
 * 302 (bukan 301) supaya browser tidak meng-cache redirect permanen dan
 * proteksi mudah dilepas bila halaman dibuka lagi untuk publik. Middleware
 * ini dipasang hanya pada grup route halaman publik di routes/web.php —
 * route pendukung mobile app (/download, /terms, /privacy, verifikasi
 * email, /api/v1/*) dan endpoint AJAX homepage tidak ikut grup.
 */
class RedirectPublicPages
{
    public function handle(Request $request, Closure $next): Response
    {
        return redirect()->to(route('home'), 302);
    }
}
