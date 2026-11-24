<?php

namespace App\Services\Obd;

use App\Models\ObdVehicle;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi registry OBD (spec penyatuan 2026-11-24 §4): satu kendaraan
 * garasi milik user = tepat satu baris obd_vehicles. Dipanggil dari
 * ObdVehicleController::index — idempoten, satu transaksi. Data OBD baris
 * ter-adopt (discovered, metric_config, usage, efisiensi, invert_current)
 * TIDAK disentuh; hanya identitas yang mengikuti garasi.
 */
class VehicleRegistryReconciler
{
    public function reconcileFor(int $userId): void
    {
        $garage = Vehicle::query()
            ->where('user_id', $userId)
            ->where('status', 1)
            ->with(['brandVehicle', 'modelVehicle', 'typeVehicle'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($userId, $garage) {
            $keptRowIds = [];

            foreach ($garage as $g) {
                $identity = self::identityOf($g);

                $row = ObdVehicle::query()
                    ->where('user_id', $userId)
                    ->where('garage_vehicle_id', (string) $g->id)
                    ->first();

                if ($row !== null) {
                    $row->update($identity);
                } else {
                    // Adopsi baris pra-migrasi tanpa garage_vehicle_id — HANYA
                    // bila cocok modelnya tunggal (ambigu = jangan menebak).
                    $adoptable = ObdVehicle::query()
                        ->where('user_id', $userId)
                        ->whereNull('garage_vehicle_id')
                        ->when(
                            $g->model_vehicle_id !== null,
                            fn ($q) => $q->where('model_vehicle_id', $g->model_vehicle_id),
                            fn ($q) => $q->whereNull('model_vehicle_id'),
                        )
                        ->orderByDesc('last_used_at')
                        ->get();
                    if ($adoptable->count() === 1) {
                        $row = $adoptable->first();
                        $row->update([...$identity, 'garage_vehicle_id' => (string) $g->id]);
                    } else {
                        $row = ObdVehicle::create([
                            ...$identity,
                            'user_id' => $userId,
                            'garage_vehicle_id' => (string) $g->id,
                        ]);
                    }
                }
                $keptRowIds[] = $row->id;
            }

            // Hapus sisanya: baris tanpa garage_vehicle_id yang tak ter-adopt
            // dan baris yang kendaraan garasinya sudah tidak ada (disetujui
            // owner — data OBD baris yatim ikut hilang).
            ObdVehicle::query()
                ->where('user_id', $userId)
                ->whereNotIn('id', $keptRowIds === [] ? [0] : $keptRowIds)
                ->delete();
        });
    }

    /** Identitas baris dari kendaraan garasi. */
    private static function identityOf(Vehicle $g): array
    {
        $brand = trim((string) ($g->brandVehicle?->name ?? ''));
        $model = trim((string) ($g->modelVehicle?->name ?? ''));
        $trim = trim((string) ($g->typeVehicle?->name ?? ''));

        return [
            'nickname' => self::nickname($brand, $model, $trim),
            'brand_name' => $brand !== '' ? $brand : null,
            'model_name' => $model !== '' ? $model : null,
            'model_vehicle_id' => $g->model_vehicle_id,
        ];
    }

    /** Gabungan kata brand+model+trim tanpa ulang (paritas ObdGarageLink.nicknameFor mobile). */
    private static function nickname(string ...$parts): string
    {
        $words = [];
        $seen = [];
        foreach ($parts as $part) {
            foreach (preg_split('/\s+/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $key = mb_strtolower($word);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $words[] = $word;
                }
            }
        }

        return implode(' ', $words) ?: 'Kendaraan';
    }
}
