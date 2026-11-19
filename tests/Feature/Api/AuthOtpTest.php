<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Alur register + verifikasi email memakai pipeline bawaan Laravel
 * (kontrak MustVerifyEmail → notifikasi VerifyEmail → link signed URL).
 * Kontrak respons API dipertahankan identik untuk app versi lama.
 */
class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_register_sends_laravel_verify_email_notification(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('users', ['email' => 'budi@example.com']);

        $user = User::where('email', 'budi@example.com')->first();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_register_ulang_email_belum_verifikasi_memperbarui_kredensial_dan_resend(): void
    {
        // Simulasi user yang macet: register pertama gagal kirim email →
        // register ulang harus menyembuhkan, bukan mentok error unique.
        $user = User::factory()->unverified()->create([
            'email' => 'macet@example.com',
            'name' => 'Nama Lama',
            'password' => Hash::make('passwordlama'),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nama Baru',
            'email' => 'macet@example.com',
            'password' => 'passwordbaru',
            'password_confirmation' => 'passwordbaru',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'macet@example.com');

        $fresh = $user->fresh();
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertTrue(Hash::check('passwordbaru', $fresh->password));
        Notification::assertSentTo($fresh, VerifyEmail::class);
    }

    public function test_register_email_terverifikasi_tetap_ditolak_bentuk_validasi_laravel(): void
    {
        User::factory()->create(['email' => 'terpakai@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Lain',
            'email' => 'terpakai@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email has already been taken.');
    }

    public function test_login_gated_until_email_verified(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'belum@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->assertNull($user->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'belum@example.com',
            'password' => 'password123',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.needs_verification', true)
            ->assertJsonPath('data.email', 'belum@example.com')
            ->assertJsonMissingPath('data.token');
    }

    public function test_klik_link_verifikasi_membuka_login(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verif@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Resend endpoint mengirim notifikasi native Laravel.
        $this->postJson('/api/v1/auth/verify-email', ['email' => 'verif@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Link verifikasi terkirim ke email Anda.');

        Notification::assertSentTo($user, VerifyEmail::class);

        // Buka link bertanda tangan (route name 'verification.verify', path lama).
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($link)->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);

        // Login kini berhasil.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'verif@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.token', fn ($token) => is_string($token) && strlen($token) > 0);
    }

    public function test_resend_verification_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'limit@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/auth/verify-email', ['email' => 'limit@example.com'])->assertOk();

        // Immediate resend → 429
        $this->postJson('/api/v1/auth/verify-email', ['email' => 'limit@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Mohon tunggu 1 menit sebelum mengirim ulang kode.');

        Notification::assertSentTimes(VerifyEmail::class, 1);
    }

    public function test_endpoint_verify_otp_deprecated(): void
    {
        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => 'siapa@example.com',
            'otp' => '123456',
        ])->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'tautan verifikasi'));
    }

    public function test_forgot_password_noop_generic_dan_stub_reset_otp_422(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
        ]);

        // Known & unknown email → sama-sama generic 200 anti-enumeration.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);
        $unknown->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Jika email terdaftar, link reset password telah dikirim.');

        // Reset via email tidak dipakai lagi (auth mobile via Google/Gmail
        // Sign-In) — endpoint hanya no-op, TIDAK boleh ada notifikasi keluar.
        Notification::assertNothingSent();

        // Stub /auth/verify otp-reset tanpa cache → 422, tanpa mengubah password.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'otp' => '000000',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
