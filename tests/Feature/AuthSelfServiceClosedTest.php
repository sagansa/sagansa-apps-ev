<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Jalur self-service auth web ditutup (spec
 * docs/superpowers/specs/2026-11-19-protect-public-pages-remove-signup-design.md,
 * addendum 2026-11-19): panel Filament user dihapus sama sekali dan panel
 * admin tidak punya route register/forgot password, sehingga tidak ada
 * siapa pun yang bisa membuat akun dari luar.
 * Fitur profil user yang sudah login (update password, 2FA) tetap hidup.
 * API mobile /api/v1/auth/* tidak tersentuh (route api, bukan config ini).
 */
class AuthSelfServiceClosedTest extends TestCase
{
    public function test_route_fortify_register_dan_reset_sudah_hilang(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('password.update'));
    }

    public function test_halaman_register_dan_fortify_forgot_mengembalikan_404(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_panel_user_sudah_dihapus_dan_panel_admin_tetap_tertutup(): void
    {
        // Panel user dihapus (addendum 2026-11-19) — tidak boleh terdaftar
        // lagi di Filament supaya /user/* benar-benar 404, bukan cuma
        // "tersembunyi".
        $this->assertArrayNotHasKey('user', Filament::getPanels());

        // Panel admin tetap ada dengan login, tapi tanpa jalur self-service.
        $admin = Filament::getPanel('admin');
        $this->assertTrue($admin->hasLogin(), 'Panel admin tetap punya login.');
        $this->assertFalse($admin->hasRegistration(), 'Panel admin tidak boleh punya registration.');
        $this->assertFalse($admin->hasPasswordReset(), 'Panel admin tidak boleh punya passwordReset.');
    }

    public function test_register_dan_password_reset_panel_mengembalikan_404(): void
    {
        $this->get('/admin/register')->assertNotFound();
        $this->get('/admin/password-reset/request')->assertNotFound();
        // Login panel user juga sudah mati: panel-nya dihapus, jadi /user/login
        // tidak lagi 200 seperti sebelum addendum 2026-11-19.
        $this->get('/user/login')->assertNotFound();
        $this->get('/user/register')->assertNotFound();
        $this->get('/user/password-reset/request')->assertNotFound();
    }

    public function test_halaman_login_admin_tetap_bisa_diakses(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_halaman_login_fortify_tetap_bisa_diakses(): void
    {
        // /login milik Fortify (bukan panel Filament) harus tetap hidup —
        // pembersihan panel user tidak boleh ikut mematikan login web utama.
        $this->get('/login')->assertOk();
    }
}
