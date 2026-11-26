<?php

namespace Tests\Feature;

use App\Models\PlnChargerLocation;
use App\Models\Provider;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Homepage menanamkan seluruh data SPKLU sebagai JSON di pln-map.blade.php dan JS marker
 * memuat gambar provider langsung dari situ. Baris lama yang gambar provider-nya tersimpan
 * sebagai URL absolut http://localhost/... membuat browser pengunjung meminta gambar dari
 * perangkatnya sendiri → Chrome memunculkan prompt
 * "wants to access other apps and services on this device" saat halaman dimuat.
 */
class HomepageLoopbackImageTest extends TestCase
{
    private const NAMA_FILE_GAMBAR = 'c0b04087-983c-440b-99f8-ab7874215abb.webp';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetik: layout memanggil <x-app-download-modal /> yang membaca app_download_settings
        // di koneksi 'ev' → sqlite :memory: (sama dengan AppDownloadSmartRedirectTest).
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
    }

    /**
     * @return array<int, array<string, mixed>> data yang dibaca JS marker dari const plnLocations
     */
    private function gambarProviderDiHomepage(string $gambarMentah): string
    {
        // Baris lama: nilai mentah di DB masih URL absolut (setter tidak ikut dijalankan).
        $provider = new Provider;
        $provider->setRawAttributes([
            'id' => 'ae8876dc-02e5-4e1d-a6c7-a01db36dc6da',
            'name' => 'Astra',
            'image' => $gambarMentah,
        ]);

        $location = new PlnChargerLocation;
        $location->setRawAttributes([
            'id' => 20,
            'name' => 'SPKLU Astra',
            'provider_id' => 'ae8876dc-02e5-4e1d-a6c7-a01db36dc6da',
            'kategori_tol' => 'NON TOL',
        ]);
        $location->setRelation('provider', $provider);
        $location->setRelation('pln_charger_location_details', collect());

        $html = view('layouts.ev.pln-map', [
            'plnLocations' => collect([$location]),
            'providers' => Collection::empty(),
            'chargingTypes' => Collection::empty(),
            'locationCategories' => Collection::empty(),
            'kategoriTols' => Collection::empty(),
            'evYoutubeVideoId' => null,
        ])->render();

        // Blade @json meng-escape slash ("http:\/\/localhost"), jadi baca nilainya lewat decode.
        preg_match('/const plnLocations = (\[.*\]);/', $html, $matches);

        $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $data[0]['provider']['image'];
    }

    public function test_homepage_meresolve_gambar_provider_loopback_menjadi_path_relatif(): void
    {
        $gambar = $this->gambarProviderDiHomepage(
            'http://localhost/storage/images/provider/' . self::NAMA_FILE_GAMBAR
        );

        $this->assertSame('/storage/images/provider/' . self::NAMA_FILE_GAMBAR, $gambar);
    }

    public function test_homepage_membiarkan_gambar_provider_host_eksternal_apa_adanya(): void
    {
        $gambar = $this->gambarProviderDiHomepage('https://cdn.example.com/logos/astra.webp');

        $this->assertSame('https://cdn.example.com/logos/astra.webp', $gambar);
    }
}
