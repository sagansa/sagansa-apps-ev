<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Jalur self-service auth web ditutup (spec
 * docs/superpowers/specs/2026-11-19-protect-public-pages-remove-signup-design.md):
 * tidak ada route register/forgot password di panel Filament (admin & user)
 * maupun Fortify, sehingga halaman login tidak menawarkan sign up / forgot
 * password dan tidak ada siapa pun yang bisa membuat akun dari luar.
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

    public function test_panel_filament_login_tetap_register_reset_mati(): void
    {
        foreach (['admin', 'user'] as $panelId) {
            $panel = Filament::getPanel($panelId);

            $this->assertTrue($panel->hasLogin(), "Panel {$panelId} tetap punya login.");
            $this->assertFalse($panel->hasRegistration(), "Panel {$panelId} tidak boleh punya registration.");
            $this->assertFalse($panel->hasPasswordReset(), "Panel {$panelId} tidak boleh punya passwordReset.");
        }
    }

    public function test_register_dan_password_reset_panel_mengembalikan_404(): void
    {
        $this->get('/admin/register')->assertNotFound();
        $this->get('/admin/password-reset/request')->assertNotFound();
        $this->get('/user/register')->assertNotFound();
        $this->get('/user/password-reset/request')->assertNotFound();
    }

    public function test_halaman_login_admin_tetap_bisa_diakses(): void
    {
        $this->get('/admin/login')->assertOk();
    }
}
