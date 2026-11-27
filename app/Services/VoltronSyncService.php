<?php

namespace App\Services;

use App\Models\VoltronPoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ingest snapshot hasil fetch Voltron (data/spklu_points_voltron.json) ke
 * tabel adaptor voltron_points, lalu hydrate ke kanonik. SATU jantung dua
 * pintu: dipanggil command `spklu:sync-voltron` (CLI manual) dan endpoint
 * POST /api/v1/internal/spklu/voltron-import (otomatis dari runner pipeline).
 *
 * Replace-semantics: snapshot adalah kebenaran penuh — titik yang hilang
 * dari snapshot dihapus (consisten dgn "fetch = kebenaran" di spec).
 * Sanity gate: diterima < voltron_gate_ratio × baseline aktif → tolak,
 * tidak ada perubahan apa pun.
 */
class VoltronSyncService
{
    public function __construct(private readonly CanonicalStationHydrateService $hydrateService) {}

    /**
     * @param  array{metadata?: array<string, mixed>, points?: array<int, mixed>}  $snapshot
     * @return array{imported: int, updated: int, pruned: int, jumlah: int, baseline: int}
     */
    public function import(array $snapshot): array
    {
        $points = $snapshot['points'] ?? null;
        if (! is_array($points) || $points === []) {
            throw ValidationException::withMessages([
                'points' => 'snapshot tanpa points[] — import ditolak.',
            ]);
        }

        foreach ($points as $i => $point) {
            if (! is_array($point)
                || ! isset($point['external_id'], $point['nama'], $point['lat'], $point['lng'])
                || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])
                || (int) $point['external_id'] <= 0
            ) {
                throw ValidationException::withMessages([
                    "points.$i" => 'titik tidak valid (butuh external_id positif, nama, lat/lng numerik).',
                ]);
            }
        }

        // Baseline dihitung SEBELUM replace — gate melindungi dataset aktif.
        $baseline = VoltronPoint::count();
        $ratio = (float) config('spklu.voltron_gate_ratio', 0.8);
        if ($baseline > 0 && count($points) < (int) floor($baseline * $ratio)) {
            throw ValidationException::withMessages([
                'points' => sprintf(
                    'sanity gate: diterima %d titik < %d%% × baseline %d — import ditolak.',
                    count($points), (int) round($ratio * 100), $baseline
                ),
            ]);
        }

        $diambil = isset($snapshot['metadata']['diambil_pada'])
            ? Carbon::parse($snapshot['metadata']['diambil_pada'])
            : now();

        $stats = ['imported' => 0, 'updated' => 0, 'pruned' => 0, 'jumlah' => count($points), 'baseline' => $baseline];
        $ids = [];

        DB::connection('ev')->transaction(function () use ($points, $diambil, &$stats, &$ids): void {
            foreach ($points as $point) {
                $payload = [
                    'nama' => trim((string) $point['nama']),
                    'alamat' => $point['alamat'] ?? null,
                    'kota' => $point['kota'] ?? null,
                    'provinsi' => $point['provinsi'] ?? null,
                    'latitude' => (float) $point['lat'],
                    'longitude' => (float) $point['lng'],
                    'operator' => $point['operator'] ?? 'Voltron',
                    'status' => $point['status'] ?? null,
                    'jumlah_poin' => (int) ($point['jumlah_poin'] ?? count($point['konektor'] ?? [])),
                    'konektor' => array_values((array) ($point['konektor'] ?? [])),
                    'raw_payload' => $point,
                    'diambil_pada' => $diambil,
                ];
                $externalId = (int) $point['external_id'];

                $existing = VoltronPoint::query()->where('external_id', $externalId)->first();
                if ($existing === null) {
                    $payload['external_id'] = $externalId;
                    VoltronPoint::create($payload);
                    $stats['imported']++;
                } else {
                    $existing->update($payload);
                    $stats['updated']++;
                }
                $ids[] = $externalId;
            }

            $stats['pruned'] = VoltronPoint::query()->whereNotIn('external_id', $ids)->delete();
        });

        return $stats;
    }

    /** Delegasi hydrate ke kanonik (Task 3) — dipisah agar test Task 2 tanpa hydrate. */
    public function hydrate(): array
    {
        return $this->hydrateService->hydrateFromVoltron();
    }

    /** Satu chain untuk command & endpoint: import → hydrate. */
    public function sync(array $snapshot): array
    {
        return ['import' => $this->import($snapshot), 'hydrate' => $this->hydrate()];
    }
}
