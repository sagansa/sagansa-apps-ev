<?php

namespace Tests\Unit;

use App\Models\ChargerLocation;
use App\Models\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * URL media absolut berasal dari lingkungan lokal (APP_URL=http://localhost) tidak boleh
 * lolos ke output maupun tersimpan: browser pengunjung memintanya dari perangkat pengunjung
 * sendiri (loopback) dan Chrome memunculkan prompt
 * "wants to access other apps and services on this device" saat halaman dimuat.
 * Host eksternal tetap dibiarkan apa adanya.
 */
class LoopbackMediaUrlTest extends TestCase
{
    public static function urlLoopbackProvider(): array
    {
        return [
            'localhost' => ['http://localhost/storage/images/provider/astra.webp'],
            '127.0.0.1 dengan port' => ['http://127.0.0.1:8000/storage/images/provider/astra.webp'],
        ];
    }

    #[DataProvider('urlLoopbackProvider')]
    public function test_provider_meresolve_url_gambar_loopback_menjadi_path_relatif(string $url): void
    {
        // Baris lama di DB: nilai mentah masih URL absolut (setter tidak ikut dijalankan).
        $provider = new Provider;
        $provider->setRawAttributes(['image' => $url]);

        $this->assertSame('/storage/images/provider/astra.webp', $provider->image);
    }

    public function test_provider_tidak_menyimpan_url_gambar_loopback_sebagai_url_absolut(): void
    {
        $provider = new Provider;
        $provider->image = 'http://localhost/storage/images/provider/astra.webp';

        $this->assertSame('images/provider/astra.webp', $provider->getAttributes()['image']);
    }

    public function test_provider_membiarkan_url_gambar_host_eksternal_apa_adanya(): void
    {
        $provider = new Provider;
        $provider->image = 'https://cdn.example.com/logos/astra.webp';

        $this->assertSame('https://cdn.example.com/logos/astra.webp', $provider->getAttributes()['image']);
        $this->assertSame('https://cdn.example.com/logos/astra.webp', $provider->image);
    }

    #[DataProvider('urlLoopbackProvider')]
    public function test_charger_location_meresolve_url_gambar_loopback_menjadi_path_relatif(string $url): void
    {
        $location = new ChargerLocation;
        $location->setRawAttributes(['image' => $url]);

        $this->assertSame('/storage/images/provider/astra.webp', $location->image);
    }

    public function test_charger_location_tidak_menyimpan_url_gambar_loopback_sebagai_url_absolut(): void
    {
        $location = new ChargerLocation;
        $location->image = 'http://localhost/storage/images/provider/astra.webp';

        $this->assertSame('images/provider/astra.webp', $location->getAttributes()['image']);
    }

    public function test_charger_location_membiarkan_url_gambar_host_eksternal_apa_adanya(): void
    {
        $location = new ChargerLocation;
        $location->image = 'https://cdn.example.com/logos/astra.webp';

        $this->assertSame('https://cdn.example.com/logos/astra.webp', $location->getAttributes()['image']);
        $this->assertSame('https://cdn.example.com/logos/astra.webp', $location->image);
    }
}
