<?php

namespace Tests\Unit;

use App\Models\ModelVehicle;
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
            mappingsCreated: [
                ['raw_brand' => 'MG', 'raw_model' => 'S5 EV'],
            ],
            renamedTo: 'S5 EV',
        );

        $summary = $report->toSummary();

        $this->assertSame('#11 MG S5 EV', $summary['keeper']);
        $this->assertSame('#22 S5 EV', $summary['duplicate']);
        $this->assertTrue($summary['dry_run']);
        $this->assertSame(9, $summary['vehicles_moved']);
        $this->assertSame(1, $summary['type_merges']);
        $this->assertSame(1, $summary['mappings_created']);
        $this->assertSame('S5 EV', $summary['renamed_to']);
    }
}
