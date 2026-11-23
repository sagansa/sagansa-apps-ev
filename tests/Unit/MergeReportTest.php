<?php

namespace Tests\Unit;

use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Support\MergeReport;
use Tests\TestCase;

class MergeReportTest extends TestCase
{
    public function test_to_summary_memuat_semua_kunci_dan_counter(): void
    {
        // `id` tidak fillable di ModelVehicle, jadi diisi via forceFill.
        $keeper = ModelVehicle::make(['name' => 'MG S5 EV'])->forceFill(['id' => 11]);
        $dup = ModelVehicle::make(['name' => 'S5 EV'])->forceFill(['id' => 22]);

        $report = new MergeReport(
            keeper: $keeper,
            duplicate: $dup,
            dryRun: true,
            vehiclesMoved: 9,
            typeMerges: [
                ['from' => null, 'to' => null, 'vehicles_remapped' => 3, 'battery_filled' => true],
            ],
            typesMoved: [
                TypeVehicle::make(['name' => 'S5 EV Long Range']),
            ],
            mappingsCreated: [
                ['raw_brand' => 'MG', 'raw_model' => 'S5 EV'],
            ],
            renamedTo: 'S5 EV',
        );

        $summary = $report->toSummary();

        // Kontrak toSummary: tepat 18 kunci.
        $this->assertSame(18, count($summary));
        $this->assertSame('#11 MG S5 EV', $summary['keeper']);
        $this->assertSame('#22 S5 EV', $summary['duplicate']);
        $this->assertTrue($summary['dry_run']);
        $this->assertSame(9, $summary['vehicles_moved']);
        $this->assertSame(1, $summary['type_merges']);
        $this->assertSame(1, $summary['types_moved']);
        // Counter yang tidak diisi lewat konstruktor tetap harus muncul bernilai 0.
        $this->assertSame(0, $summary['stats_rows_moved']);
        $this->assertSame(0, $summary['connectings_moved']);
        $this->assertSame(0, $summary['obd_profiles_moved']);
        $this->assertSame(0, $summary['pivot_rows_moved']);
        $this->assertSame(0, $summary['pivot_rows_deleted']);
        $this->assertSame(0, $summary['obd_vehicles_moved']);
        $this->assertSame(0, $summary['scan_reports_moved']);
        $this->assertSame(0, $summary['compat_reports_moved']);
        $this->assertSame(0, $summary['mappings_moved']);
        $this->assertSame(0, $summary['mappings_deleted']);
        $this->assertSame(1, $summary['mappings_created']);
        $this->assertSame('S5 EV', $summary['renamed_to']);
    }

    public function test_to_summary_dengan_konstruktor_minimal_memakai_nilai_default(): void
    {
        // Konstruktor minimal: hanya keeper, duplicate, dan dryRun.
        $report = new MergeReport(
            keeper: ModelVehicle::make(['name' => 'Kicker']),
            duplicate: ModelVehicle::make(['name' => 'Duplikat']),
            dryRun: true,
        );

        $summary = $report->toSummary();

        // Semua kunci kontrak tetap hadir dengan nilai default.
        $this->assertSame(18, count($summary));
        $this->assertSame('# Kicker', $summary['keeper']);
        $this->assertSame('# Duplikat', $summary['duplicate']);
        $this->assertTrue($summary['dry_run']);
        $this->assertSame(0, $summary['vehicles_moved']);
        $this->assertSame(0, $summary['type_merges']);
        $this->assertSame(0, $summary['types_moved']);
        $this->assertSame(0, $summary['stats_rows_moved']);
        $this->assertSame(0, $summary['connectings_moved']);
        $this->assertSame(0, $summary['obd_profiles_moved']);
        $this->assertSame(0, $summary['pivot_rows_moved']);
        $this->assertSame(0, $summary['pivot_rows_deleted']);
        $this->assertSame(0, $summary['obd_vehicles_moved']);
        $this->assertSame(0, $summary['scan_reports_moved']);
        $this->assertSame(0, $summary['compat_reports_moved']);
        $this->assertSame(0, $summary['mappings_moved']);
        $this->assertSame(0, $summary['mappings_deleted']);
        $this->assertSame(0, $summary['mappings_created']);
        $this->assertNull($summary['renamed_to']);
    }
}
