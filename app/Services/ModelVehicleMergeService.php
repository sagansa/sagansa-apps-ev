<?php

namespace App\Services;

use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\VehicleConnecting;
use App\Support\MergeReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge dua model katalog (hasil duplikat auto-create import GAIKINDO):
 * re-point seluruh relasi ke keeper dalam satu transaksi, verifikasi nol
 * referensi, lalu hapus duplikat. Dry-run menghitung dampak tanpa menulis.
 * Lihat spec docs/superpowers/specs/2026-11-23-model-vehicle-dedupe-merge-design.md
 */
class ModelVehicleMergeService
{
    public function dryRun(ModelVehicle $keeper, ModelVehicle $duplicate): MergeReport
    {
        $this->assertMergeable($keeper, $duplicate);

        $plan = $this->planTypes($keeper, $duplicate);

        $typeMerges = [];
        foreach ($plan['merges'] as $merge) {
            $batteryFilled = $merge['to']->battery_capacity === null
                && $merge['from']->battery_capacity !== null;
            $typeMerges[] = [
                'from' => $merge['from'],
                'to' => $merge['to'],
                'vehicles_remapped' => DB::connection($this->connectionName())
                    ->table('vehicles')
                    ->where('model_vehicle_id', $duplicate->id)
                    ->where('type_vehicle_id', $merge['from']->id)
                    ->count(),
                'battery_filled' => $batteryFilled,
            ];
        }

        // Usulan auto-mapping: pasangan raw stats yang belum punya mapping.
        $rawPairs = $this->rawPairsFromStats($duplicate);
        $mappingsCreated = [];
        foreach ($rawPairs as $pair) {
            $exists = DB::connection($this->connectionName())
                ->table('vehicle_name_mappings')
                ->where('raw_brand_norm', $this->normalizeName($pair['raw_brand']))
                ->where('raw_model_norm', $this->normalizeName($pair['raw_model']))
                ->exists();
            if (! $exists) {
                $mappingsCreated[] = $pair;
            }
        }

        return new MergeReport(
            keeper: $keeper,
            duplicate: $duplicate,
            dryRun: true,
            vehiclesMoved: DB::connection($this->connectionName())->table('vehicles')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            typeMerges: $typeMerges,
            typesMoved: array_values($plan['moves']),
            statsRowsMoved: DB::connection($this->connectionName())->table('vehicle_sales_stats')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            connectingsMoved: DB::connection($this->connectionName())->table('vehicle_connectings')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            obdProfilesMoved: DB::connection($this->connectionName())->table('obd_vehicle_profiles')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            pivotRowsMoved: $this->pivotRows($keeper, $duplicate)['move'],
            pivotRowsDeleted: $this->pivotRows($keeper, $duplicate)['delete'],
            obdVehiclesMoved: DB::connection($this->connectionName())->table('obd_vehicles')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            scanReportsMoved: DB::connection($this->connectionName())->table('obd_scan_reports')
                ->where('model_vehicle_id', $duplicate->id)->count(),
            // Tabel obd_compatibility_reports di-drop (migrasi 2026_11_19_000001,
            // endpoint tak pernah dipakai) — dijaga agar kontrak MergeReport utuh.
            compatReportsMoved: Schema::connection($this->connectionName())
                ->hasTable('obd_compatibility_reports')
                ? DB::connection($this->connectionName())->table('obd_compatibility_reports')
                    ->where('model_vehicle_id', $duplicate->id)->count()
                : 0,
            mappingsMoved: DB::connection($this->connectionName())->table('vehicle_name_mappings')
                ->where('model_vehicle_id', $duplicate->id)
                ->whereNotIn('id', $this->mappingIdsSupersededByKeeper($keeper, $duplicate))
                ->count(),
            mappingsDeleted: count($this->mappingIdsSupersededByKeeper($keeper, $duplicate)),
            mappingsCreated: $mappingsCreated,
        );
    }

    public function merge(ModelVehicle $keeper, ModelVehicle $duplicate, ?string $renameTo = null): MergeReport
    {
        throw new \LogicException('Belum diimplementasi — Task 4.');
    }

    // ---------------------------------------------------------------- helpers

    /** Guard merge: tidak boleh diri sendiri / lintas brand. */
    protected function assertMergeable(ModelVehicle $keeper, ModelVehicle $duplicate): void
    {
        if ($keeper->id === $duplicate->id) {
            throw new \InvalidArgumentException('Model keeper dan duplikat sama.');
        }
        if ($keeper->brand_vehicle_id !== $duplicate->brand_vehicle_id) {
            throw new \InvalidArgumentException('Merge lintas brand tidak didukung.');
        }
    }

    /**
     * Rencana merge varian: nama ternormalisasi sama → gabung (varian keeper
     * menang); beda nama → pindah utuh ke keeper.
     *
     * @return array{merges: array<int, array{from: TypeVehicle, to: TypeVehicle}>, moves: array<int, TypeVehicle>}
     */
    protected function planTypes(ModelVehicle $keeper, ModelVehicle $duplicate): array
    {
        $keeperTypes = [];
        foreach ($keeper->typeVehicles()->get() as $type) {
            $keeperTypes[$this->normalizeName($type->name)] = $type;
        }

        $merges = [];
        $moves = [];
        foreach ($duplicate->typeVehicles()->get() as $dupType) {
            $norm = $this->normalizeName($dupType->name);
            if (isset($keeperTypes[$norm])) {
                $merges[] = ['from' => $dupType, 'to' => $keeperTypes[$norm]];
            } else {
                $moves[] = $dupType;
            }
        }

        return ['merges' => $merges, 'moves' => $moves];
    }

    /** Pasangan raw (brand, model) distinct dari stats milik duplikat. */
    protected function rawPairsFromStats(ModelVehicle $duplicate): array
    {
        return DB::connection($this->connectionName())
            ->table('vehicle_sales_stats')
            ->where('model_vehicle_id', $duplicate->id)
            ->select('raw_brand', 'raw_model')
            ->distinct()
            ->get()
            ->map(fn ($row) => ['raw_brand' => $row->raw_brand, 'raw_model' => $row->raw_model])
            ->all();
    }

    /** Baris pivot OBD milik dup: berapa re-point, berapa hapus (keeper sudah punya profil sama). */
    protected function pivotRows(ModelVehicle $keeper, ModelVehicle $duplicate): array
    {
        $rows = DB::connection($this->connectionName())
            ->table('obd_profile_model_vehicles')
            ->where('model_vehicle_id', $duplicate->id)
            ->get(['obd_vehicle_profile_id']);

        $move = 0;
        $delete = 0;
        foreach ($rows as $row) {
            $keeperHas = DB::connection($this->connectionName())
                ->table('obd_profile_model_vehicles')
                ->where('obd_vehicle_profile_id', $row->obd_vehicle_profile_id)
                ->where('model_vehicle_id', $keeper->id)
                ->exists();
            $keeperHas ? $delete++ : $move++;
        }

        return ['move' => $move, 'delete' => $delete];
    }

    /** Mapping dup yang norm-nya sudah dipegang keeper → dihapus (keeper menang). */
    protected function mappingIdsSupersededByKeeper(ModelVehicle $keeper, ModelVehicle $duplicate): array
    {
        return DB::connection($this->connectionName())
            ->table('vehicle_name_mappings as dup')
            ->join('vehicle_name_mappings as keep', function ($join) use ($keeper) {
                $join->on('keep.raw_brand_norm', '=', 'dup.raw_brand_norm')
                    ->on('keep.raw_model_norm', '=', 'dup.raw_model_norm')
                    ->where('keep.model_vehicle_id', $keeper->id);
            })
            ->where('dup.model_vehicle_id', $duplicate->id)
            ->pluck('dup.id')
            ->all();
    }

    /** Upper, trim, collapse spasi — kunci perbandingan nama varian/model. */
    protected function normalizeName(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    protected function connectionName(): string
    {
        return app()->environment('testing') ? config('database.default') : 'ev';
    }
}
