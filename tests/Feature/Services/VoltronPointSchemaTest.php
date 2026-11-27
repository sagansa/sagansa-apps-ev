<?php

namespace Tests\Feature\Services;

use App\Models\VoltronPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoltronPointSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabel_dan_model_bisa_menyimpan_titik(): void
    {
        VoltronPoint::create([
            'external_id' => 500138,
            'nama' => 'BYD Haka Auto Balikpapan',
            'alamat' => 'Jl. Ruhui Rahayu No.124',
            'kota' => 'Kota Balikpapan',
            'provinsi' => null,
            'latitude' => -1.2418181,
            'longitude' => 116.8770488,
            'operator' => 'Voltron',
            'status' => 'Available',
            'jumlah_poin' => 2,
            'konektor' => [
                ['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 1, 'status' => 'Available'],
            ],
            'raw_payload' => ['external_id' => 500138],
            'diambil_pada' => '2026-11-27T06:37:42Z',
        ]);

        $row = VoltronPoint::where('external_id', 500138)->first();

        $this->assertNotNull($row);
        $this->assertSame('BYD Haka Auto Balikpapan', $row->nama);
        // json_encode PHP membuang zero fraction (60.0 → 60) sehingga angka JSON
        // bulat ter-decode kembali sebagai int — cast ke float utk assert tipe.
        $this->assertSame(60.0, (float) $row->konektor[0]['daya_kw']);
        $this->assertSame(2, $row->jumlah_poin);
    }

    public function test_config_keys_tersedia(): void
    {
        $this->assertSame('', config('spklu.ingest_token'));
        $this->assertSame(0.8, config('spklu.voltron_gate_ratio'));
        $this->assertSame(['(VOLTRON)'], config('spklu.pln_slice_prefixes'));
    }
}
