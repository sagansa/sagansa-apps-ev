<?php

namespace Tests\Feature\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicle;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ObdVehicleDedupeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gabungkan kendaraan OBD2 ganda & tautkan entri lama ke garasi
 * (2026-11-24, feedback owner: tiap kendaraan tampil dua kali di pemilih
 * Cockpit; yang dihapus muncul lagi).
 *
 * Aturan yang diuji:
 *  - satu kendaraan garasi = maksimal satu baris OBD;
 *  - entri lama tanpa garasi (hasil create manual jadul) ditautkan otomatis ke
 *    kendaraan garasi yang brand+model-nya cocok, lalu digabung bila garasi itu
 *    sudah punya baris;
 *  - entri lama yang TIDAK cocok dengan garasi mana pun TETAP ADA — kendaraan
 *    tanpa profil katalog harus tetap bisa scan & mengirim laporan;
 *  - yang paling terakhir dipakai bertahan, data baris lain dibawa ikut.
 */
class ObdVehicleDedupeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function makeGarage(User $owner, string $brand, string $model): Vehicle
    {
        $brandRow = BrandVehicle::firstOrCreate(['name' => $brand]);
        $modelRow = ModelVehicle::firstOrCreate(['name' => $model, 'brand_vehicle_id' => $brandRow->id]);

        return Vehicle::create([
            'user_id' => $owner->id,
            'brand_vehicle_id' => $brandRow->id,
            'model_vehicle_id' => $modelRow->id,
            'status' => 1,
        ]);
    }

    protected function row(User $owner, array $overrides = []): ObdVehicle
    {
        return $owner->obdVehicles()->create(array_merge([
            'nickname' => 'IONIQ 5',
            'brand_name' => 'Hyundai',
            'model_name' => 'IONIQ 5',
        ], $overrides));
    }

    /**
     * Meniru data LAMA (prasesudah unique index): index diturunkan di dalam
     * transaksi test — RefreshDatabase mengembalikannya setelah test — supaya
     * dua baris untuk satu kendaraan garasi bisa ditulis langsung.
     */
    protected function tanpaUniqueIndex(): void
    {
        Schema::table('obd_vehicles', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'garage_vehicle_id']);
        });
    }

    protected function standar(): array
    {
        return ['nickname' => 'OBD2 Standar', 'brand_name' => 'Universal', 'model_name' => 'OBD2 Standar'];
    }

    protected function airEvLama(int $lastUsedAt = 0): array
    {
        return [
            'nickname' => 'Air EV',
            'brand_name' => 'Wuling',
            'model_name' => 'Air EV',
            'last_used_at' => $lastUsedAt,
        ];
    }

    // ---------- duplikat satu kendaraan garasi ----------

    public function test_duaBarisSatuGarasi_digabungKeYangTerakhirDipakai_dataTerbawa(): void
    {
        $this->tanpaUniqueIndex();
        $garage = $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $config = [['pid' => ['key' => 'soh', 'mode' => 34, 'pid' => '0105'], 'enabled' => true]];
        $baru = $this->row($this->user, ['garage_vehicle_id' => $garage->id, 'last_used_at' => 900]);
        $lama = $this->row($this->user, [
            'garage_vehicle_id' => $garage->id,
            'last_used_at' => 100,
            'metric_config' => $config,
            'usage' => ['lastOdometerKm' => 1200.0, 'samples' => 4],
        ]);

        $report = app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame([$lama->id], $report->removedIds());
        $keeper = ObdVehicle::query()->findOrFail($baru->id);
        $this->assertSame($config, $keeper->metric_config, 'konfigurasi metrik baris lama dibawa ke yang bertahan');
        // assertEquals: putaran JSON (cast array Laravel) membaca 1200.0 sebagai 1200.
        $this->assertEquals(['lastOdometerKm' => 1200.0, 'samples' => 4], $keeper->usage);
        $this->assertSame(900, (int) $keeper->last_used_at);
    }

    public function test_duaKendaraanGarasiModelSama_tidakDigabung(): void
    {
        $pertama = $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $kedua = $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $this->row($this->user, ['garage_vehicle_id' => $pertama->id]);
        $this->row($this->user, ['garage_vehicle_id' => $kedua->id]);

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame(2, ObdVehicle::query()->count(), 'dua mobil berbeda = dua entri');
    }

    // ---------- entri lama ditautkan ke garasi ----------

    public function test_entriLamaDitautkanKeGarasiYangCocok(): void
    {
        $garage = $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $lama = $this->row($this->user, $this->airEvLama());

        app(ObdVehicleDedupeService::class)->dedupe();

        $lama->refresh();
        $this->assertSame($garage->id, $lama->garage_vehicle_id);
        $this->assertSame($garage->model_vehicle_id, $lama->model_vehicle_id, 'profil kini di-resolve dari katalog brand-model');
        $this->assertSame('Air EV', $lama->nickname, 'panggilan user tidak diubah');
        $this->assertSame(1, ObdVehicle::query()->count());
    }

    public function test_cocokBrandModel_tanpaPedulikanHurufBesar(): void
    {
        // Garasi (impor GAIKINDO) memakai huruf besar; profil seed title case.
        $garage = $this->makeGarage($this->user, 'WULING', 'AIR EV');
        $lama = $this->row($this->user, $this->airEvLama());

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame($garage->id, $lama->refresh()->garage_vehicle_id);
    }

    public function test_entriLama_yangGarasinyaSudahPunyaBaris_digabungKeBarisGarasi(): void
    {
        $garage = $this->makeGarage($this->user, 'WULING', 'AIR EV');
        $garageRow = $this->row($this->user, [
            'nickname' => 'WULING AIR EV',
            'brand_name' => 'Wuling',
            'model_name' => 'Air EV',
            'garage_vehicle_id' => $garage->id,
            'last_used_at' => 100,
        ]);
        $lama = $this->row($this->user, $this->airEvLama(900));

        $report = app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame([$lama->id], $report->removedIds());
        $keeper = ObdVehicle::query()->findOrFail($garageRow->id);
        $this->assertSame('WULING AIR EV', $keeper->nickname, 'baris garasi (kanonis) yang bertahan');
        $this->assertSame(900, (int) $keeper->last_used_at, 'terakhir dipakai diambil yang terbaru');
        $this->assertSame(1, ObdVehicle::query()->count());
    }

    public function test_entriLama_duaGarasiModelSama_tidakDitautkan(): void
    {
        // Dua mobil sama model di garasi → ambigu, entri lama dibiarkan apa adanya.
        $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $lama = $this->row($this->user, $this->airEvLama());

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertNull($lama->refresh()->garage_vehicle_id);
        $this->assertSame(1, ObdVehicle::query()->count());
    }

    // ---------- entri lama tanpa garasi ----------

    public function test_duaEntriLamaIdentikal_digabung(): void
    {
        $this->row($this->user, $this->standar() + ['last_used_at' => 100]);
        $terpakai = $this->row($this->user, $this->standar() + ['last_used_at' => 900]);

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame([$terpakai->id], ObdVehicle::query()->pluck('id')->all());
    }

    public function test_entriLamaTanpaCocokGarasi_tidakDihapus(): void
    {
        // Kendaraan tanpa profil katalog tetap bisa scan & mengirim laporan.
        $lama = $this->row($this->user, $this->standar());

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertSame(1, ObdVehicle::query()->count());
        $this->assertNull($lama->refresh()->garage_vehicle_id);
    }

    // ---------- cakupan & dry-run ----------

    public function test_antarUserTidakTercampur(): void
    {
        $lain = User::factory()->create();
        $this->makeGarage($lain, 'Wuling', 'Air EV'); // garasi milik user LAIN
        $lama = $this->row($this->user, $this->airEvLama());

        app(ObdVehicleDedupeService::class)->dedupe();

        $this->assertNull($lama->refresh()->garage_vehicle_id, 'garasi user lain tidak boleh dipakai');
    }

    public function test_batasiUser_barisUserLainTidakDisentuh(): void
    {
        $lain = User::factory()->create();
        $this->row($lain, $this->standar() + ['last_used_at' => 100]);
        $this->row($lain, $this->standar() + ['last_used_at' => 900]);

        app(ObdVehicleDedupeService::class)->dedupe(userId: $this->user->id);

        $this->assertSame(2, ObdVehicle::query()->where('user_id', $lain->id)->count());
    }

    public function test_dryRun_hanyaRencana_tidakMengubahData(): void
    {
        $lama = $this->row($this->user, $this->standar() + ['last_used_at' => 100]);
        $terpakai = $this->row($this->user, $this->standar() + ['last_used_at' => 900]);

        $report = app(ObdVehicleDedupeService::class)->dedupe(dryRun: true);

        $this->assertTrue($report->isDryRun);
        $this->assertSame([$lama->id], $report->removedIds(), 'rencana: yang paling terakhir dipakai yang bertahan');
        $this->assertSame(2, ObdVehicle::query()->count(), 'dry-run tidak mengubah data');
        $this->assertSame(2, ObdVehicle::query()->whereKey([$lama->id, $terpakai->id])->count());
    }

    public function test_collapseGarageDuplicates_hanyaYangBerGarasi(): void
    {
        $this->tanpaUniqueIndex();
        $garage = $this->makeGarage($this->user, 'Wuling', 'Air EV');
        $this->row($this->user, ['garage_vehicle_id' => $garage->id, 'last_used_at' => 100]);
        $this->row($this->user, ['garage_vehicle_id' => $garage->id, 'last_used_at' => 900]);
        $this->row($this->user, $this->standar() + ['last_used_at' => 100]);
        $this->row($this->user, $this->standar() + ['last_used_at' => 900]);

        app(ObdVehicleDedupeService::class)->collapseGarageDuplicates();

        $this->assertSame(3, ObdVehicle::query()->count(), 'entri tanpa garasi tidak ikut (tugas dedupe penuh)');
    }

    // ---------- kasus layar owner: 6 baris → 3 ----------

    public function test_enamBarisGanda_menjadiTiga(): void
    {
        $wuling = $this->makeGarage($this->user, 'WULING', 'AIR EV');
        $this->makeGarage($this->user, 'Hyundai', 'IONIQ 5');
        $ioniq = ['nickname' => 'IONIQ 5', 'brand_name' => 'Hyundai', 'model_name' => 'IONIQ 5'];
        $wulingGarage = ['nickname' => 'WULING AIR EV', 'brand_name' => 'Wuling', 'model_name' => 'Air EV'];

        $this->row($this->user, $this->standar());
        $this->row($this->user, $wulingGarage + ['garage_vehicle_id' => $wuling->id, 'last_used_at' => 50]);
        $aktif = $this->row($this->user, $ioniq + ['last_used_at' => 900]);
        $this->row($this->user, $ioniq + ['last_used_at' => 10]);
        $this->row($this->user, $this->standar());
        $this->row($this->user, $this->airEvLama(5));

        app(ObdVehicleDedupeService::class)->dedupe();

        $sisa = ObdVehicle::query()->orderBy('nickname')->get();
        $this->assertSame(
            ['IONIQ 5', 'OBD2 Standar', 'WULING AIR EV'],
            $sisa->pluck('nickname')->all(),
        );
        $this->assertNotNull($sisa->firstWhere('id', $aktif->id)->garage_vehicle_id, 'kendaraan aktif bertahan & tertaut');
        $this->assertNull($sisa->firstWhere('nickname', 'OBD2 Standar')->garage_vehicle_id, 'yang tak cocok garasi tetap tanpa garasi');
    }
}
