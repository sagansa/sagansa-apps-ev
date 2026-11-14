<?php

namespace Tests\Feature;

use App\Models\AppDownloadSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QR pintar download app: modal meng-encode route /download, lalu halaman itu
 * mendeteksi OS ponsel pemindai (User-Agent) — Android → link Android,
 * iOS → link iOS / halaman "Segera Hadir" saat link belum terisi,
 * desktop → landing dengan kedua tombol store.
 */
class AppDownloadSmartRedirectTest extends TestCase
{
    private const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    private const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';

    private const DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetik: migrasi app_download_settings dijalankan di sqlite :memory:
        // pada koneksi 'ev' (yang juga dipakai model saat env testing).
        config([
            'database.default' => 'ev',
            'database.connections.ev' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        foreach ([
            '2026_08_17_000001_create_app_download_settings_table',
            '2026_08_17_000002_add_whatsapp_to_app_download_settings',
            '2026_08_24_000001_add_pre_release_flow_to_app_download_settings',
        ] as $migration) {
            $this->artisan('migrate', ['--path' => "database/migrations/{$migration}.php"]);
        }

        // Baris setting tunggal dari seed migration; tiap test menimpa sesuai kebutuhan.
        AppDownloadSetting::query()->firstOrFail();
    }

    private function updateSetting(array $attributes): void
    {
        AppDownloadSetting::query()->firstOrFail()->fill($attributes)->save();
    }

    public function test_scanner_android_diarahkan_ke_link_android(): void
    {
        $this->updateSetting([
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
        ]);

        $this->get('/download', ['User-Agent' => self::ANDROID_UA])
            ->assertRedirect('https://play.google.com/store/apps/details?id=id.sagansa.ev');
    }

    public function test_scanner_ios_dengan_link_terisi_diarahkan_ke_link_ios(): void
    {
        $this->updateSetting([
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
            'ios_url' => 'https://apps.apple.com/id/app/ev/id1234567890',
        ]);

        $this->get('/download', ['User-Agent' => self::IOS_UA])
            ->assertRedirect('https://apps.apple.com/id/app/ev/id1234567890');
    }

    public function test_scanner_ios_saat_link_kosong_melihat_halaman_segera_hadir(): void
    {
        $this->updateSetting([
            'ios_url' => '',
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
        ]);

        $response = $this->get('/download', ['User-Agent' => self::IOS_UA]);

        $response->assertOk();
        $response->assertSee('Versi iOS Sedang Dalam Proses', false);
        // Link iOS tidak aktif (belum terisi) — hanya notice + fallback Android.
        $response->assertDontSee('href="https://apps.apple.com', false);
        // Tetap ada jalan keluar: unduh versi Android.
        $response->assertSee('https://play.google.com/store/apps/details?id=id.sagansa.ev', false);
    }

    public function test_scanner_desktop_melihat_landing_dengan_tombol_android(): void
    {
        $this->updateSetting([
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
            'ios_url' => '',
        ]);

        $response = $this->get('/download', ['User-Agent' => self::DESKTOP_UA]);

        $response->assertOk();
        $response->assertSee('href="https://play.google.com/store/apps/details?id=id.sagansa.ev"', false);
        $response->assertSee('Segera Hadir di', false);
    }

    public function test_scanner_desktop_melihat_tombol_ios_aktif_bila_link_terisi(): void
    {
        $this->updateSetting([
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
            'ios_url' => 'https://testflight.apple.com/join/abc123',
        ]);

        $response = $this->get('/download', ['User-Agent' => self::DESKTOP_UA]);

        $response->assertOk();
        $response->assertSee('href="https://testflight.apple.com/join/abc123"', false);
    }

    public function test_qr_pada_modal_mengencode_route_download_pintar(): void
    {
        $setting = new AppDownloadSetting([
            'is_active' => true,
            'qr_code_enabled' => true,
            'android_url' => 'https://play.google.com/store/apps/details?id=id.sagansa.ev',
            'ios_url' => '',
        ]);

        $html = view('components.app-download-modal', ['appDownloadSetting' => $setting])->render();

        $expectedData = 'data=' . urlencode(route('app.download'));
        $this->assertStringContainsString($expectedData, $html);
        // Link Play Store mentah tidak lagi menjadi isi QR.
        $this->assertStringNotContainsString(
            'data=' . urlencode('https://play.google.com/store/apps/details?id=id.sagansa.ev'),
            $html
        );
    }
}
