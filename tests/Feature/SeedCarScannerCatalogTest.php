<?php

namespace Tests\Feature;

use App\Models\ObdVehicleProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seeder folder katalog Car Scanner (2026-11-21): idempotent, grade community,
 * tersaji via GET /api/v1/obd/profiles.
 */
class SeedCarScannerCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function writeProfile(string $dir, string $hash): void
    {
        $json = json_encode([
            'platform' => 'CS-' . substr($hash, 0, 8),
            'brand' => null,
            'modelName' => 'Katalog CS ' . substr($hash, 0, 8),
            'modelVehicleId' => null,
            'vinPattern' => null,
            'yearMin' => null,
            'yearMax' => null,
            'grade' => 'community',
            'version' => 1,
            'metrics' => [
                [
                    'key' => 'soc_bms', 'mode' => 34, 'pid' => '0101', 'ecuHeader' => '7E4',
                    'bytes' => 1, 'formula' => 'A*0.5', 'unit' => '%', 'priority' => 2,
                    'description' => 'Battery SoC BMS', 'minValue' => 0.0, 'maxValue' => 100.0,
                    'category' => 'EV_SPECIFIC', 'byteOffset' => 4, 'udsStatusByte' => false,
                    'signed' => false, 'cellArray' => null, 'cyclePeriod' => 1, 'cyclePhase' => 0,
                ],
            ],
            'initCommands' => [],
            'usableKwh' => null,
            'efficiencyKmPerKwh' => null,
            'dtcEcuHeaders' => [],
        ], JSON_UNESCAPED_UNICODE);

        file_put_contents($dir . '/' . $hash . '.json', $json);
    }

    public function test_seed_catalog_dir_idempotent_and_served(): void
    {
        Config::set('obd.enabled', true);

        // Catatan adaptasi: seeder me-resolve `path` via base_path() yang SELALU
        // menggabungkan (join_paths meng-ltrim '/' — path absolut /tmp/... jadi
        // "{base}/tmp/..." yang salah). Maka katalog uji dibuat di dalam app
        // (storage/framework/testing, gitignored) dan dikirim sebagai path RELATIF.
        $relativePath = 'storage/framework/testing/obd-catalog-test-' . uniqid();
        $dir = base_path($relativePath);
        mkdir($dir, 0777, true);

        try {
            $this->writeProfile($dir, 'aaaabbbbccccdddd');
            $this->writeProfile($dir, 'eeeeffffgggghhhh');

            $this->artisan('obd:seed-profiles', [
                'path' => $relativePath,
                '--grade' => 'community',
                '--no-resolve' => true,
            ])->expectsOutputToContain('Selesai: 2 baru, 0 diperbarui.');

            // Idempotent: jalankan lagi → 0 baru, 2 diperbarui.
            $this->artisan('obd:seed-profiles', [
                'path' => $relativePath,
                '--grade' => 'community',
                '--no-resolve' => true,
            ])->expectsOutputToContain('Selesai: 0 baru, 2 diperbarui.');

            $this->assertSame(2, ObdVehicleProfile::where('grade', 'community')->count());

            $user = User::factory()->create();
            Sanctum::actingAs($user);
            $response = $this->getJson('/api/v1/obd/profiles');
            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonCount(2, 'data');
            $first = $response->json('data.0');
            $this->assertSame('community', $first['grade']);
            $this->assertSame('A*0.5', $first['pid_list']['metrics'][0]['formula']);
        } finally {
            @unlink($dir . '/aaaabbbbccccdddd.json');
            @unlink($dir . '/eeeeffffgggghhhh.json');
            @rmdir($dir);
        }
    }

    /**
     * ETag mitigasi payload (13 MB): GET kedua dengan If-None-Match yang cocok
     * harus 304 TANPA body — bukan 500/fatal maupun re-render JSON penuh.
     */
    public function test_etag_304_on_if_none_match(): void
    {
        Config::set('obd.enabled', true);

        $relativePath = 'storage/framework/testing/obd-catalog-etag-test-' . uniqid();
        $dir = base_path($relativePath);
        mkdir($dir, 0777, true);

        try {
            $this->writeProfile($dir, '1111222233334444');
            $this->artisan('obd:seed-profiles', [
                'path' => $relativePath,
                '--grade' => 'community',
                '--no-resolve' => true,
            ])->expectsOutputToContain('Selesai: 1 baru, 0 diperbarui.');

            $user = User::factory()->create();
            Sanctum::actingAs($user);

            $first = $this->getJson('/api/v1/obd/profiles')->assertOk();
            $etag = $first->headers->get('ETag');
            $this->assertNotEmpty($etag);

            $this->getJson('/api/v1/obd/profiles', ['If-None-Match' => $etag])
                ->assertNoContent(304);
        } finally {
            @unlink($dir . '/1111222233334444.json');
            @rmdir($dir);
        }
    }
}
