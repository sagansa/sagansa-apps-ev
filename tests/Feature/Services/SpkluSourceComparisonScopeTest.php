<?php

namespace Tests\Feature\Services;

use App\Models\OpenchargemapPoi;
use App\Services\SpkluSourceComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perbandingan sumber (ESDM↔PLN↔OCM) adalah diagnostik data INDONESIA —
 * ESDM/PLN memang hanya ada di ID. Saat OCM worldwide aktif (±270 ribu POI),
 * sisi OCM HARUS dibatasi ke ID: selain tidak relevan secara semantik,
 * memuat semuanya pernah meledakkan cache hingga 2,3 GB (insiden kuota
 * DB 3 GB, 2026-11).
 */
class SpkluSourceComparisonScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_only_counts_indonesian_ocm_pois(): void
    {
        OpenchargemapPoi::create([
            'ocm_id' => 101,
            'title' => 'SPKLU Dalam Negeri',
            'country_code' => 'ID',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'operator' => 'PLN',
        ]);

        OpenchargemapPoi::create([
            'ocm_id' => 102,
            'title' => 'Worldwide POI London',
            'country_code' => 'GB',
            'latitude' => 51.5,
            'longitude' => -0.1,
            'operator' => 'BP Pulse',
        ]);

        // Legacy import file lama — tanpa country_code, diasumsikan ID.
        OpenchargemapPoi::create([
            'ocm_id' => 103,
            'title' => 'SPKLU File Lama',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'operator' => 'Astra',
        ]);

        $result = app(SpkluSourceComparisonService::class)->cached(true);

        $this->assertSame(
            2,
            $result['summary']['totals']['ocm'] ?? null,
            'Sisi OCM perbandingan hanya boleh menghitung POI Indonesia (ID + legacy null)'
        );
    }
}
