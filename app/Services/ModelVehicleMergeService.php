<?php

namespace App\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\Vehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleNameMapping;
use App\Models\VehicleSalesStat;
use App\Support\MergeReport;
use App\Support\VehicleNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Merge dua model katalog (hasil duplikat auto-create import GAIKINDO):
 * re-point seluruh relasi ke keeper dalam satu transaksi, verifikasi nol
 * referensi, lalu hapus duplikat. Dry-run menghitung dampak tanpa menulis.
 * Lihat spec ../docs/superpowers/specs/2026-11-23-model-vehicle-dedupe-merge-design.md
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
                'stats_type_remapped' => DB::connection($this->connectionName())
                    ->table('vehicle_sales_stats')
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

        // Hitung sekali — hasil yang sama dipakai dua kali di laporan.
        $pivot = $this->pivotRows($keeper, $duplicate);
        $supersededIds = $this->mappingIdsSupersededByKeeper($keeper, $duplicate);

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
            pivotRowsMoved: $pivot['move'],
            pivotRowsDeleted: $pivot['delete'],
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
                ->whereNotIn('id', $supersededIds)
                ->count(),
            mappingsDeleted: count($supersededIds),
            mappingsCreated: $mappingsCreated,
        );
    }

    public function merge(ModelVehicle $keeper, ModelVehicle $duplicate, ?string $renameTo = null): MergeReport
    {
        $this->assertMergeable($keeper, $duplicate);

        $report = DB::connection($this->connectionName())->transaction(
            fn () => $this->execute($keeper, $duplicate, $renameTo),
        );

        Log::info('model_vehicle.merged', $report->toSummary());

        return $report;
    }

    /** Seluruh langkah merge — dijalankan di dalam transaksi (Task 3 spec, langkah 1–11). */
    protected function execute(ModelVehicle $keeper, ModelVehicle $duplicate, ?string $renameTo): MergeReport
    {
        // (0) Data yang harus dikumpulkan SEBELUM re-point.
        $rawPairs = $this->rawPairsFromStats($duplicate);
        $plan = $this->planTypes($keeper, $duplicate);

        // (1)–(2) Kendaraan user: re-point model (+ remap varian gabungan),
        // termasuk soft-deleted.
        $vehiclesMoved = Vehicle::withTrashed()
            ->where('model_vehicle_id', $duplicate->id)->count();
        Vehicle::withTrashed()
            ->where('model_vehicle_id', $duplicate->id)
            ->update(['model_vehicle_id' => $keeper->id]);

        $typeMerges = [];
        foreach ($plan['merges'] as $merge) {
            $remapped = Vehicle::withTrashed()
                ->where('type_vehicle_id', $merge['from']->id)
                ->where('model_vehicle_id', $keeper->id)
                ->count();
            Vehicle::withTrashed()
                ->where('type_vehicle_id', $merge['from']->id)
                ->where('model_vehicle_id', $keeper->id)
                ->update(['type_vehicle_id' => $merge['to']->id]);

            $batteryFilled = $merge['to']->battery_capacity === null
                && $merge['from']->battery_capacity !== null;
            if ($batteryFilled) {
                $merge['to']->update(['battery_capacity' => $merge['from']->battery_capacity]);
            }
            $typeMerges[] = [
                'from' => $merge['from'],
                'to' => $merge['to'],
                'vehicles_remapped' => $remapped,
                'battery_filled' => $batteryFilled,
            ];
        }

        // (3) Stats GAIKINDO: re-point model, lalu remap type hint varian
        // gabungan — dilakukan sebelum (5) menghapus type ter-gabung.
        $statsRowsMoved = VehicleSalesStat::where('model_vehicle_id', $duplicate->id)
            ->update(['model_vehicle_id' => $keeper->id]);
        foreach ($plan['merges'] as $merge) {
            DB::connection($this->connectionName())
                ->table('vehicle_sales_stats')
                ->where('type_vehicle_id', $merge['from']->id)
                ->update(['type_vehicle_id' => $merge['to']->id]);
        }

        // (4) Vehicle connecting: re-point model, lalu remap type hint varian
        // gabungan — wajib sebelum (5) menghapus type ter-gabung, karena FK
        // nullOnDelete akan meng-null hint yang belum diremap.
        $connectingsMoved = VehicleConnecting::where('model_vehicle_id', $duplicate->id)
            ->update(['model_vehicle_id' => $keeper->id]);
        foreach ($plan['merges'] as $merge) {
            DB::connection($this->connectionName())
                ->table('vehicle_connectings')
                ->where('type_vehicle_id', $merge['from']->id)
                ->update(['type_vehicle_id' => $merge['to']->id]);
        }

        foreach ($plan['merges'] as $merge) {
            // Name mapping: FK type_vehicle_id RESTRICT — tanpa remap, (5)
            // menghapus varian gagal (SQLSTATE 23000). Hanya re-point hint,
            // baris mapping tidak dihapus di sini.
            DB::connection($this->connectionName())
                ->table('vehicle_name_mappings')
                ->where('type_vehicle_id', $merge['from']->id)
                ->update(['type_vehicle_id' => $merge['to']->id]);
        }

        // Guard pre-delete: FK vehicles.type_vehicle_id bersifat cascade. Remap
        // varian di atas hanya menyentuh kendaraan keeper — kendaraan milik
        // model LAIN yang menunjuk varian ter-gabung akan terhapus diam-diam
        // bersama variannya. Ada sisa = anomali → seluruh transaksi dibatalkan
        // (tidak ada data yang hilang).
        foreach ($plan['merges'] as $merge) {
            $stale = Vehicle::withTrashed()
                ->where('type_vehicle_id', $merge['from']->id)
                ->where('model_vehicle_id', '!=', $keeper->id)
                ->exists();
            if ($stale) {
                throw new \RuntimeException("Varian #{$merge['from']->id} ({$merge['from']->name}) masih dipakai kendaraan di luar model #{$keeper->id} — merge dibatalkan.");
            }
        }

        // (5) Hapus varian dup yang ter-gabung (nol kendaraan menunjuknya —
        // dijamin guard pre-delete di atas).
        foreach ($plan['merges'] as $merge) {
            $merge['from']->delete();
        }

        // (6) Varian nama-beda pindah utuh ke keeper (id tetap).
        foreach ($plan['moves'] as $type) {
            $type->update(['model_vehicle_id' => $keeper->id]);
        }

        // (7) Profil OBD + pivot (hormati unique pivot).
        $obdProfilesMoved = DB::connection($this->connectionName())
            ->table('obd_vehicle_profiles')
            ->where('model_vehicle_id', $duplicate->id)
            ->update(['model_vehicle_id' => $keeper->id]);

        $pivotRowsMoved = 0;
        $pivotRowsDeleted = 0;
        $pivotRows = DB::connection($this->connectionName())
            ->table('obd_profile_model_vehicles')
            ->where('model_vehicle_id', $duplicate->id)
            ->get(['obd_vehicle_profile_id']);
        foreach ($pivotRows as $row) {
            $keeperHas = DB::connection($this->connectionName())
                ->table('obd_profile_model_vehicles')
                ->where('obd_vehicle_profile_id', $row->obd_vehicle_profile_id)
                ->where('model_vehicle_id', $keeper->id)
                ->exists();
            if ($keeperHas) {
                DB::connection($this->connectionName())
                    ->table('obd_profile_model_vehicles')
                    ->where('obd_vehicle_profile_id', $row->obd_vehicle_profile_id)
                    ->where('model_vehicle_id', $duplicate->id)
                    ->delete();
                $pivotRowsDeleted++;
            } else {
                DB::connection($this->connectionName())
                    ->table('obd_profile_model_vehicles')
                    ->where('obd_vehicle_profile_id', $row->obd_vehicle_profile_id)
                    ->where('model_vehicle_id', $duplicate->id)
                    ->update(['model_vehicle_id' => $keeper->id]);
                $pivotRowsMoved++;
            }
        }

        // (8) Tabel OBD lain.
        $obdVehiclesMoved = DB::connection($this->connectionName())->table('obd_vehicles')
            ->where('model_vehicle_id', $duplicate->id)->update(['model_vehicle_id' => $keeper->id]);
        $scanReportsMoved = DB::connection($this->connectionName())->table('obd_scan_reports')
            ->where('model_vehicle_id', $duplicate->id)->update(['model_vehicle_id' => $keeper->id]);
        // Tabel obd_compatibility_reports di-drop (migrasi 2026_11_19_000001,
        // endpoint tak pernah dipakai) — guard sama dengan dryRun.
        $compatReportsMoved = Schema::connection($this->connectionName())
            ->hasTable('obd_compatibility_reports')
            ? DB::connection($this->connectionName())->table('obd_compatibility_reports')
                ->where('model_vehicle_id', $duplicate->id)->update(['model_vehicle_id' => $keeper->id])
            : 0;

        // (9) Name mappings — mapping bentukan keeper menang; sisanya re-point.
        $supersededIds = $this->mappingIdsSupersededByKeeper($keeper, $duplicate);
        $mappingsDeleted = count($supersededIds);
        VehicleNameMapping::where('model_vehicle_id', $duplicate->id)
            ->whereIn('id', $supersededIds)
            ->delete();
        $mappingsMoved = VehicleNameMapping::where('model_vehicle_id', $duplicate->id)
            ->update(['model_vehicle_id' => $keeper->id]);

        // (10) Auto-mapping dari raw stats — import berikutnya kena LAYER 1.
        $mappingsCreated = [];
        foreach ($rawPairs as $pair) {
            $exists = VehicleNameMapping::query()
                ->where('raw_brand_norm', $this->normalizeName((string) $pair['raw_brand']))
                ->where('raw_model_norm', $this->normalizeName((string) $pair['raw_model']))
                ->exists();
            if ($exists) {
                continue;
            }
            VehicleNameMapping::record(
                (string) $pair['raw_brand'],
                (string) $pair['raw_model'],
                (string) $keeper->brandVehicle?->name,
                (string) $keeper->name,
                null,
                "auto-merge dari model #{$duplicate->id}",
            );
            $mappingsCreated[] = $pair;
        }

        // (11) Verifikasi nol referensi — ada sisa = bug → rollback total.
        // obd_compatibility_reports di-drop (migrasi 2026_11_19_000001) —
        // hanya diverifikasi bila tabelnya ada (guard sama dengan dryRun).
        $referenceTables = [
            'vehicles' => 'model_vehicle_id',
            'type_vehicles' => 'model_vehicle_id',
            'vehicle_sales_stats' => 'model_vehicle_id',
            'vehicle_connectings' => 'model_vehicle_id',
            'obd_vehicle_profiles' => 'model_vehicle_id',
            'obd_profile_model_vehicles' => 'model_vehicle_id',
            'obd_vehicles' => 'model_vehicle_id',
            'obd_scan_reports' => 'model_vehicle_id',
            'vehicle_name_mappings' => 'model_vehicle_id',
        ];
        if (Schema::connection($this->connectionName())->hasTable('obd_compatibility_reports')) {
            $referenceTables['obd_compatibility_reports'] = 'model_vehicle_id';
        }
        foreach ($referenceTables as $table => $column) {
            $left = DB::connection($this->connectionName())
                ->table($table)
                ->where($column, $duplicate->id)
                ->count();
            if ($left > 0) {
                throw new \RuntimeException("Merge gagal: {$table} masih memiliki {$left} baris menunjuk model #{$duplicate->id}.");
            }
        }

        // (12) Hapus duplikat + rename keeper (opsional).
        $duplicate->delete();
        $renamedTo = null;
        $newName = trim((string) $renameTo);
        if ($newName !== '' && $newName !== $keeper->name) {
            $keeper->update(['name' => $newName]);
            $renamedTo = $newName;
        }

        return new MergeReport(
            keeper: $keeper,
            duplicate: $duplicate,
            dryRun: false,
            vehiclesMoved: $vehiclesMoved,
            typeMerges: $typeMerges,
            typesMoved: array_values($plan['moves']),
            statsRowsMoved: $statsRowsMoved,
            connectingsMoved: $connectingsMoved,
            obdProfilesMoved: $obdProfilesMoved,
            pivotRowsMoved: $pivotRowsMoved,
            pivotRowsDeleted: $pivotRowsDeleted,
            obdVehiclesMoved: $obdVehiclesMoved,
            scanReportsMoved: $scanReportsMoved,
            compatReportsMoved: $compatReportsMoved,
            mappingsMoved: $mappingsMoved,
            mappingsDeleted: $mappingsDeleted,
            mappingsCreated: $mappingsCreated,
            renamedTo: $renamedTo,
        );
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
            ->distinct()
            ->pluck('dup.id')
            ->all();
    }

    /**
     * Deteksi pasangan model kandidat duplikat per brand: nobrand sama persis,
     * ATAU nospace satu mengandung yang lain (min 4 char). Saran keeper:
     * lebih banyak link OBD > lebih banyak user > metadata terisi > lebih tua.
     *
     * @return array<int, array{brand: string, a: array{model: ModelVehicle, vehicles: int, types: int, obd: int}, b: array{model: ModelVehicle, vehicles: int, types: int, obd: int}, keeper_id: int}>
     */
    public function auditCandidates(): array
    {
        $pairs = [];

        // Urutkan brand agar urutan pasangan di halaman audit deterministik.
        $brands = BrandVehicle::query()->has('modelVehicles', '>=', 2)->orderBy('id')->get();
        foreach ($brands as $brand) {
            $brandNorm = $this->normalizeName($brand->name);
            $models = ModelVehicle::query()
                ->where('brand_vehicle_id', $brand->id)
                ->withCount(['vehicles', 'typeVehicles', 'obdVehicleProfiles'])
                ->orderBy('id')
                ->get();

            $entries = $models->map(fn (ModelVehicle $m) => [
                'model' => $m,
                'norm' => $this->normalizeName($m->name),
                'nospace' => preg_replace('/\s+/', '', $this->normalizeName($m->name)),
                'nobrand' => VehicleNameNormalizer::stripBrandTokens($this->normalizeName($m->name), $brandNorm),
                'vehicles' => $m->vehicles_count,
                'types' => $m->type_vehicles_count,
                'obd' => $m->obd_vehicle_profiles_count,
                'filled' => ($m->category !== null ? 1 : 0) + ($m->size_class !== null ? 1 : 0),
                'age' => $m->created_at?->getTimestamp() ?? 0,
            ])->all();

            for ($i = 0; $i < count($entries); $i++) {
                for ($j = $i + 1; $j < count($entries); $j++) {
                    $x = $entries[$i];
                    $y = $entries[$j];
                    if (! $this->isDuplicateCandidate($x, $y)) {
                        continue;
                    }
                    $pairs[] = [
                        'brand' => $brand->name,
                        'a' => $this->pairSide($x),
                        'b' => $this->pairSide($y),
                        'keeper_id' => $this->suggestKeeper($x, $y),
                    ];
                }
            }
        }

        return $pairs;
    }

    /** @param array<string, mixed> $x @param array<string, mixed> $y */
    protected function isDuplicateCandidate(array $x, array $y): bool
    {
        if ($x['nobrand'] !== '' && $x['nobrand'] === $y['nobrand']) {
            return true;
        }

        $xNospace = (string) $x['nospace'];
        $yNospace = (string) $y['nospace'];

        return (mb_strlen($xNospace) >= 4 && str_contains($yNospace, $xNospace))
            || (mb_strlen($yNospace) >= 4 && str_contains($xNospace, $yNospace));
    }

    /** @param array<string, mixed> $entry @return array{model: ModelVehicle, vehicles: int, types: int, obd: int} */
    protected function pairSide(array $entry): array
    {
        return [
            'model' => $entry['model'],
            'vehicles' => (int) $entry['vehicles'],
            'types' => (int) $entry['types'],
            'obd' => (int) $entry['obd'],
        ];
    }

    /** @param array<string, mixed> $x @param array<string, mixed> $y */
    protected function suggestKeeper(array $x, array $y): int
    {
        $score = fn (array $e) => ((int) $e['obd']) * 1_000_000
            + ((int) $e['vehicles']) * 10
            + ((int) $e['filled'])
            - ($e['age'] / 1_000_000_000_000); // usia hanya tie-breaker murni

        return $score($x) >= $score($y) ? $x['model']->id : $y['model']->id;
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
