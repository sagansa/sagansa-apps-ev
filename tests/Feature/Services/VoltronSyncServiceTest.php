<?php

namespace Tests\Feature\Services;

use App\Models\VoltronPoint;
use App\Services\VoltronSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VoltronSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Bentuk identik dengan data/spklu_points_voltron.json hasil fetcher. */
    private function snapshot(array $titik, string $diambil = '2026-11-28T01:00:00Z'): array
    {
        return ['metadata' => ['diambil_pada' => $diambil, 'jumlah' => count($titik)], 'points' => $titik];
    }

    private function titik(int $id, string $nama = 'BYD Haka Auto', float $lat = -1.24, float $lng = 116.87): array
    {
        return [
            'source' => 'voltron', 'external_id' => $id, 'nama' => $nama,
            'lat' => $lat, 'lng' => $lng, 'alamat' => 'Jl. Ruhui Rahayu',
            'kota' => 'Kota Balikpapan', 'provinsi' => null,
            'operator' => 'Voltron', 'status' => 'Available', 'jumlah_poin' => 2,
            'konektor' => [['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 2, 'status' => 'Available']],
        ];
    }

    public function test_import_snapshot_baru(): void
    {
        $stats = app(VoltronSyncService::class)
            ->import($this->snapshot([$this->titik(1), $this->titik(2)]));

        $this->assertSame(2, $stats['imported']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['pruned']);
        $this->assertSame(2, VoltronPoint::count());
    }

    public function test_reimport_tidak_duplikasi_dan_mengubah_data(): void
    {
        $service = app(VoltronSyncService::class);
        $service->import($this->snapshot([$this->titik(1, 'Nama Lama')]));

        $stats = $service->import($this->snapshot([
            $this->titik(1, 'Nama Baru'), $this->titik(3),
        ], '2026-11-28T02:00:00Z'));

        $this->assertSame(1, $stats['imported']);   // titik 3 baru
        $this->assertSame(1, $stats['updated']);    // titik 1 berubah
        $this->assertSame('Nama Baru', VoltronPoint::where('external_id', 1)->value('nama'));
        $this->assertSame(2, VoltronPoint::count());
    }

    public function test_replace_semantics_hapus_titik_yang_hilang(): void
    {
        $service = app(VoltronSyncService::class);
        $service->import($this->snapshot([$this->titik(1), $this->titik(2)]));

        $stats = $service->import($this->snapshot([$this->titik(1)]));

        $this->assertSame(1, $stats['pruned']);
        $this->assertSame(1, VoltronPoint::count());
        $this->assertNull(VoltronPoint::where('external_id', 2)->first());
    }

    public function test_sanity_gate_menolak_penurunan_drastis(): void
    {
        $service = app(VoltronSyncService::class);
        $service->import($this->snapshot([$this->titik(1), $this->titik(2), $this->titik(3),
            $this->titik(4), $this->titik(5), $this->titik(6), $this->titik(7),
            $this->titik(8), $this->titik(9), $this->titik(10)]));

        // 2 < 80% × 10 = 8 → ditolak.
        $this->expectException(ValidationException::class);
        $service->import($this->snapshot([$this->titik(1), $this->titik(2)]));
    }

    public function test_payload_tanpa_points_ditolak(): void
    {
        $this->expectException(ValidationException::class);
        app(VoltronSyncService::class)->import(['metadata' => []]);
    }

    public function test_titik_tanpa_koordinat_numerik_ditolak(): void
    {
        $this->expectException(ValidationException::class);
        $rusak = $this->titik(1);
        $rusak['lat'] = 'bukan angka';
        app(VoltronSyncService::class)->import($this->snapshot([$rusak]));
    }

    public function test_diambil_pada_dari_metadata_tersimpan(): void
    {
        app(VoltronSyncService::class)->import($this->snapshot([$this->titik(1)], '2026-11-28T03:00:00Z'));

        $this->assertSame('2026-11-28T03:00:00.000000Z',
            VoltronPoint::where('external_id', 1)->first()->diambil_pada->toISOString());
    }
}
