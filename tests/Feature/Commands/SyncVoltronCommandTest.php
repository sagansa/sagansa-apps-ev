<?php

namespace Tests\Feature\Commands;

use App\Models\ChargingStation;
use App\Models\VoltronPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SyncVoltronCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_import_dan_hydrate_dari_file(): void
    {
        $file = sys_get_temp_dir().'/voltron_sync_test_'.uniqid().'.json';
        File::put($file, json_encode([
            'metadata' => ['diambil_pada' => '2026-11-28T01:00:00Z', 'jumlah' => 1],
            'points' => [[
                'source' => 'voltron', 'external_id' => 500150,
                'nama' => 'Voltron Test Mall', 'lat' => -6.2, 'lng' => 106.8,
                'operator' => 'Voltron', 'status' => 'Available', 'jumlah_poin' => 1,
                'konektor' => [['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 1, 'status' => 'Available']],
            ]],
        ]));

        $this->artisan('spklu:sync-voltron', ['file' => $file])
            ->assertExitCode(0);

        $this->assertSame(1, VoltronPoint::count());
        $this->assertSame(1, ChargingStation::where('source', 'voltron')->count());

        File::delete($file);
    }

    public function test_payload_invalid_exit_1(): void
    {
        $file = sys_get_temp_dir().'/voltron_sync_test_'.uniqid().'.json';
        File::put($file, json_encode(['metadata' => []]));

        $this->artisan('spklu:sync-voltron', ['file' => $file])
            ->assertExitCode(1);

        $this->assertSame(0, VoltronPoint::count());

        File::delete($file);
    }
}
