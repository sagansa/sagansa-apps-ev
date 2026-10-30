<?php

namespace Tests\Feature;

use App\Models\TypeVehicle;
use App\Models\VehicleSalesStat;
use App\Services\VehicleMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Import XLSX hasil gaikindo-converter (sheet "Data": judul baris 2-3,
 * header baris 4, data baris 5; kolom spec lengkap) via
 * vehicle-sales:import-csv — stats + koreksi negatif + upsert spesifikasi
 * type_vehicles (nilai terbaru menimpa, kosong tidak menghapus).
 */
class ImportVehicleSalesXlsxTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    /** Header persis output gaikindo-converter. */
    private const HEADER = [
        'BRAND', 'MODEL/TYPE', 'CC', 'TRANS', 'FUEL', 'TANK', 'GVW (KG)',
        'GEAR RATIO', 'WHEEL & TYRE', 'PS/HP', 'WHEEL BASE (MM)',
        'DIMENSION (MM)', 'SEATER', 'DRIVE SYS', 'SPEED', 'DOOR', 'WHEELS',
        'CBU/CKD', 'ORIGIN', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN',
        'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC', 'SEGMENT SHARE',
        'CATEGORY SHARE', 'TOTAL',
    ];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /** @param list<list<mixed>> $rows */
    private function writeXlsx(array $rows): string
    {
        $dir = storage_path('framework/testing/import-vehicle-sales-xlsx');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->setCellValue('B2', 'GAIKINDO Wholesales Data');
        $sheet->setCellValue('B3', 'Sumber: fixture — konversi 30-10-2026');

        foreach (self::HEADER as $i => $header) {
            $sheet->setCellValue([$i + 1, 4], $header);
        }

        foreach ($rows as $r => $row) {
            foreach ($row as $i => $value) {
                $sheet->setCellValue([$i + 1, 5 + $r], $value);
            }
        }

        $path = $dir.'/'.uniqid('gaikindo-', true).'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_yearly_xlsx_writes_stats_negative_units_and_specs(): void
    {
        $path = $this->writeXlsx([
            // CC, TRANS, FUEL, TANK, ..., JAN, FEB(-2 koreksi retur), SEP
            ['TOYOTA', 'Agya 1.2 G AT', 1200, 'AT', 'G', 40, 1200, null, null, null, 2450, '3695 x 1600 x 1515', 5, '4X2 FF', null, 4, 4, 'CBU', 'Japan', 13, -2, null, null, null, null, null, null, null, null, null, 0.241, 0.078, 11],
            ['HYUNDAI', 'Ioniq EV Prime', null, 'AT', 'BEV', null, 1970, null, '205/60/R16', null, 2700, '4470 x 1820 x 1475', 5, '4X2 FF', null, 5, 4, 'CBU', 'South Korea', null, null, null, null, null, null, null, null, 4, null, null, 0.002, 0.005, 4],
            ['TOTAL', 'CUMULATIVE', null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 13, -2, null, null, null, null, null, null, 4, null, null, null, null, 15],
        ]);

        $this->artisan('vehicle-sales:import-csv', [
            'file' => $path,
            '--year' => '2024',
        ])->assertSuccessful();

        // TOYOTA: JAN 13 + FEB -2 + agregat 11; HYUNDAI: SEP 4 + agregat 4.
        $this->assertSame(5, VehicleSalesStat::count());

        $feb = VehicleSalesStat::query()->where('month', 2)->firstOrFail();
        $this->assertSame(-2, (int) $feb->units);
        $this->assertSame('TOYOTA', $feb->raw_brand);

        $annual = VehicleSalesStat::query()
            ->whereNull('month')
            ->orderBy('units')
            ->pluck('units')
            ->map(fn ($units) => (int) $units)
            ->all();
        $this->assertSame([4, 11], $annual);

        // Spesifikasi ter-upsert ke type kendaraan.
        $agya = TypeVehicle::query()->where('name', 'Agya 1.2 G AT')->firstOrFail();
        $this->assertSame(1200, (int) $agya->cc);
        $this->assertSame('AT', $agya->trans);
        $this->assertSame('G', $agya->fuel);
        $this->assertSame(40, (int) $agya->tank);
        $this->assertSame(1200, (int) $agya->gvw);
        $this->assertSame('3695 x 1600 x 1515', $agya->dimension);
        $this->assertSame('CBU', $agya->cbu_ckd);
        $this->assertSame('Japan', $agya->origin_country);
        $this->assertSame('gaikindo', $agya->specs_source);
        $this->assertNotNull($agya->specs_updated_at);
    }

    public function test_specs_latest_non_empty_overwrites_and_empty_keeps_old(): void
    {
        $this->artisan('vehicle-sales:import-csv', [
            'file' => $this->writeXlsx([
                ['TOYOTA', 'Agya 1.2 G AT', 1200, 'AT', 'G', null, null, null, null, null, null, null, null, null, null, null, null, null, null, 13, null, null, null, null, null, null, null, null, null, null, null, null, 13],
            ]),
            '--year' => '2024',
        ])->assertSuccessful();

        // File kedua: CC berubah 1300, TRANS kosong (tidak boleh menghapus).
        $this->artisan('vehicle-sales:import-csv', [
            'file' => $this->writeXlsx([
                ['TOYOTA', 'Agya 1.2 G AT', 1300, null, 'G', null, null, null, null, null, null, null, null, null, null, null, null, null, null, 7, null, null, null, null, null, null, null, null, null, null, null, null, 7],
            ]),
            '--year' => '2024',
        ])->assertSuccessful();

        $agya = TypeVehicle::query()->where('name', 'Agya 1.2 G AT')->firstOrFail();
        $this->assertSame(1300, (int) $agya->cc);
        $this->assertSame('AT', $agya->trans);

        // Replace per periode: hanya angka file kedua yang tersisa.
        $this->assertSame(2, VehicleSalesStat::count()); // JAN 7 + agregat 7
        $this->assertSame(7, (int) VehicleSalesStat::query()->where('month', 1)->firstOrFail()->units);
    }

    public function test_model_history_types_include_specs(): void
    {
        $path = $this->writeXlsx([
            ['HYUNDAI', 'Ioniq EV Prime', null, 'AT', 'BEV', null, 1970, null, '205/60/R16', null, 2700, '4470 x 1820 x 1475', 5, '4X2 FF', null, 5, 4, 'CBU', 'South Korea', null, null, null, null, null, null, null, null, 4, null, null, null, null, 4],
        ]);

        $this->artisan('vehicle-sales:import-csv', [
            'file' => $path,
            '--year' => '2025',
        ])->assertSuccessful();

        $history = app(VehicleMarketService::class)->modelHistory('HYUNDAI', 'Ioniq');

        $this->assertNotEmpty($history['types']);

        $type = collect($history['types'])->firstWhere('name', 'Ioniq EV Prime');
        $this->assertNotNull($type);
        $this->assertNotNull($type['specs']);
        $this->assertSame('BEV', $type['specs']['fuel']);
        $this->assertSame('AT', $type['specs']['trans']);
        $this->assertSame(1970, (int) $type['specs']['gvw']);
        $this->assertSame('South Korea', $type['specs']['origin_country']);
        $this->assertArrayNotHasKey('cc', $type['specs']); // null tidak dikirim
    }
}
