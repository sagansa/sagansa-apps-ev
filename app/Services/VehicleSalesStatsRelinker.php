<?php

namespace App\Services;

use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use Illuminate\Support\Facades\DB;

/**
 * Sinkronkan ulang FK stats (brand/model/type/powertrain) dari master
 * CONNECTING — memperbaiki baris SALAH-link (bukan hanya NULL-link):
 * koreksi master pasca-impor cukup diterapkan ulang tanpa re-upload
 * file tahunnya. Master adalah sumber kebenaran; relink tidak pernah
 * melepas link bila master tidak mengenal kombinasinya.
 */
class VehicleSalesStatsRelinker
{
    public function __construct(
        protected VehicleSalesMatcher $matcher,
        protected VehicleConnectingSyncService $sync,
    ) {}

    /** Squash identitas — sama dgn hook saving VehicleConnecting. */
    protected static function squash(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper(trim($value)));
    }

    /**
     * Relink SATU kombinasi master — dipakai tombol halaman edit master.
     * Seluruh baris stats (semua tahun/bulan) yang raw-nya identik dengan
     * key master dipindah ke FK master terbaru.
     */
    public function relinkCombination(VehicleConnecting $connecting): int
    {
        if ($connecting->model_vehicle_id === null) {
            return 0; // master belum ter-link — tak ada tujuan relink
        }

        $key = (string) $connecting->raw_gabungan_key;
        $ids = [];

        VehicleSalesStat::query()
            ->select(['id', 'raw_brand', 'raw_model'])
            ->chunkById(1000, function ($chunk) use ($key, &$ids): void {
                foreach ($chunk as $stat) {
                    if (self::squash($stat->raw_brand.' '.$stat->raw_model) === $key) {
                        $ids[] = $stat->id;
                    }
                }
            });

        if ($ids === []) {
            return 0;
        }

        $updated = VehicleSalesStat::query()->whereIn('id', $ids)->update([
            'brand_vehicle_id' => $connecting->brand_vehicle_id,
            'model_vehicle_id' => $connecting->model_vehicle_id,
            'type_vehicle_id' => $connecting->type_vehicle_id,
            'powertrain' => $connecting->powertrain ?? DB::raw('powertrain'),
        ]);

        $this->sync->flushMarketCache();

        return $updated;
    }

    /**
     * Sweep seluruh stats: NULL-link diisi, SALAH-link dipindah ke master.
     * Hit connecting di-memo per teks gabungan unik (baris stats banyak
     * yang berbagi kombinasi sama).
     *
     * @param  bool  $dryRun  laporankan tanpa menulis
     * @return array{checked: int, filled: int, fixed: int, unchanged: int, samples: list<string>}
     */
    public function relinkAll(?int $year = null, bool $dryRun = false): array
    {
        $report = ['checked' => 0, 'filled' => 0, 'fixed' => 0, 'unchanged' => 0, 'samples' => []];

        /** @var array<string, ?array> $hitCache */
        $hitCache = [];

        VehicleSalesStat::query()
            ->when($year !== null, fn ($q) => $q->where('year', $year))
            ->select(['id', 'raw_brand', 'raw_model', 'brand_vehicle_id', 'model_vehicle_id', 'type_vehicle_id'])
            ->chunkById(1000, function ($chunk) use (&$report, &$hitCache, $dryRun): void {
                foreach ($chunk as $stat) {
                    $report['checked']++;

                    $gabungan = trim((string) $stat->raw_brand).' '.trim((string) $stat->raw_model);

                    if (! array_key_exists($gabungan, $hitCache)) {
                        $hitCache[$gabungan] = $this->matcher->connectingHitRaw($gabungan);
                    }

                    $hit = $hitCache[$gabungan];

                    if ($hit === null) {
                        $report['unchanged']++; // tak dikenal master — biarkan

                        continue;
                    }

                    $same = $stat->brand_vehicle_id === $hit['brand_vehicle_id']
                        && $stat->model_vehicle_id === $hit['model_vehicle_id']
                        && $stat->type_vehicle_id === ($hit['type_vehicle_id'] ?? null);

                    if ($same) {
                        $report['unchanged']++;

                        continue;
                    }

                    if ($stat->model_vehicle_id === null) {
                        $report['filled']++;
                    } else {
                        $report['fixed']++;
                    }

                    $report['samples'][] = $gabungan.' → '.($hit['model_name'] ?? '?');

                    if (! $dryRun) {
                        $stat->update([
                            'brand_vehicle_id' => $hit['brand_vehicle_id'],
                            'model_vehicle_id' => $hit['model_vehicle_id'],
                            'type_vehicle_id' => $hit['type_vehicle_id'] ?? null,
                        ]);
                    }
                }
            });

        if (! $dryRun && ($report['filled'] > 0 || $report['fixed'] > 0)) {
            $this->sync->flushMarketCache();
        }

        $report['samples'] = array_slice(array_values(array_unique($report['samples'])), 0, 10);

        return $report;
    }
}
