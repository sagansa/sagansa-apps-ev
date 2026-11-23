<?php

namespace App\Support;

use App\Models\ModelVehicle;
use App\Models\TypeVehicle;

/**
 * Laporan hasil merge/dry-run dua model katalog. Dipakai modal preview
 * halaman audit, notifikasi sukses, dan log terstruktur.
 */
final class MergeReport
{
    /**
     * Bentuk entry `typeMerges`: `stats_type_remapped` hanya diisi jalur dryRun
     * (pratinjau); jalur eksekusi `merge()` tidak melaporkan count tersebut.
     *
     * @param array<int, array{from: ?TypeVehicle, to: ?TypeVehicle, vehicles_remapped: int, stats_type_remapped?: int, battery_filled: bool}> $typeMerges
     * @param array<int, TypeVehicle> $typesMoved
     * @param array<int, array{raw_brand: string, raw_model: string}> $mappingsCreated
     */
    public function __construct(
        public readonly ModelVehicle $keeper,
        public readonly ModelVehicle $duplicate,
        public readonly bool $dryRun,
        public readonly int $vehiclesMoved = 0,
        public readonly array $typeMerges = [],
        public readonly array $typesMoved = [],
        public readonly int $statsRowsMoved = 0,
        public readonly int $connectingsMoved = 0,
        public readonly int $obdProfilesMoved = 0,
        public readonly int $pivotRowsMoved = 0,
        public readonly int $pivotRowsDeleted = 0,
        public readonly int $obdVehiclesMoved = 0,
        public readonly int $scanReportsMoved = 0,
        public readonly int $compatReportsMoved = 0,
        public readonly int $mappingsMoved = 0,
        public readonly int $mappingsDeleted = 0,
        public readonly array $mappingsCreated = [],
        public readonly ?string $renamedTo = null,
    ) {}

    /** Ringkasan utk notifikasi Filament & entri log. */
    public function toSummary(): array
    {
        return [
            'keeper' => "#{$this->keeper->id} {$this->keeper->name}",
            'duplicate' => "#{$this->duplicate->id} {$this->duplicate->name}",
            'dry_run' => $this->dryRun,
            'vehicles_moved' => $this->vehiclesMoved,
            'type_merges' => count($this->typeMerges),
            'types_moved' => count($this->typesMoved),
            'stats_rows_moved' => $this->statsRowsMoved,
            'connectings_moved' => $this->connectingsMoved,
            'obd_profiles_moved' => $this->obdProfilesMoved,
            'pivot_rows_moved' => $this->pivotRowsMoved,
            'pivot_rows_deleted' => $this->pivotRowsDeleted,
            'obd_vehicles_moved' => $this->obdVehiclesMoved,
            'scan_reports_moved' => $this->scanReportsMoved,
            'compat_reports_moved' => $this->compatReportsMoved,
            'mappings_moved' => $this->mappingsMoved,
            'mappings_deleted' => $this->mappingsDeleted,
            'mappings_created' => count($this->mappingsCreated),
            'renamed_to' => $this->renamedTo,
        ];
    }
}
