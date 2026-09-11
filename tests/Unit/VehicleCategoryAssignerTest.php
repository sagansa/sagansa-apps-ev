<?php

namespace Tests\Unit;

use App\Services\VehicleCategoryAssigner;
use App\Support\VehicleCategories;
use PHPUnit\Framework\TestCase;

class VehicleCategoryAssignerTest extends TestCase
{
    private VehicleCategoryAssigner $assigner;

    protected function setUp(): void
    {
        $this->assigner = new VehicleCategoryAssigner;
    }

    public function test_kamus_model_pasangan_category_size(): void
    {
        $r = $this->assigner->assign('TOYOTA', 'Avanza', null, 'G');

        $this->assertSame('MPV', $r['category']);
        $this->assertSame('Small', $r['size']);
        $this->assertSame('exact', $r['confidence']);
        $this->assertSame('G', $r['powertrain']);
    }

    public function test_override_brand_menang_atas_kamus_model(): void
    {
        // "Ranger": FORD = pickup, UD TRUCKS = truk berat.
        $ford = $this->assigner->assign('FORD', 'Ranger');
        $ud = $this->assigner->assign('UD TRUCKS', 'Ranger');

        $this->assertSame('Pickup', $ford['category']);
        $this->assertSame('Truk Berat', $ud['category']);

        // "ES": AION = sedan medium, LEXUS ES = sedan large.
        $this->assertSame('Medium', $this->assigner->assign('AION', 'ES')['size']);
        $this->assertSame('Large', $this->assigner->assign('LEXUS ES', 'ES')['size']);

        // "K-SERIES": KIA = truk ringan, SCANIA = bus.
        $this->assertSame('Truk Ringan', $this->assigner->assign('KIA', 'K-Series')['category']);
        $this->assertSame('Bus', $this->assigner->assign('SCANIA', 'K-Series')['category']);
    }

    public function test_aturan_pola_truk_hino_light_dan_heavy(): void
    {
        $light = $this->assigner->assign('HINO', '115LD');
        $heavy = $this->assigner->assign('HINO FM', 'FM 260JD');

        $this->assertSame('Truk Ringan', $light['category']);
        $this->assertSame('rule', $light['confidence']);

        $this->assertSame('Truk Berat', $this->assigner->assign('HINO FM', 'FM')['category']);
        $this->assertSame('Truk Berat', $heavy['category']);
    }

    public function test_aturan_pola_isuzu_dan_ud(): void
    {
        $this->assertSame('Truk Ringan', $this->assigner->assign('ISUZU', 'NMR71')['category']);
        $this->assertSame('Truk Berat', $this->assigner->assign('ISUZU', 'FVR')['category']);
        $this->assertSame('Truk Berat', $this->assigner->assign('UD TRUCKS', 'CDE')['category']);
    }

    public function test_aturan_pola_bus_mercedes_dan_chassis(): void
    {
        $this->assertSame('Bus', $this->assigner->assign('MERCEDES BENZ', 'O 500')['category']);
        $this->assertSame('Bus', $this->assigner->assign('HINO FC', 'FC Bus')['category']);
        $this->assertSame('Truk Berat', $this->assigner->assign('FAW', 'Tractor Head')['category']);
        $this->assertSame('Truk Berat', $this->assigner->assign('FAW', 'Mixer Truck')['category']);
    }

    public function test_model_tak_dikenal_confidence_low_category_null(): void
    {
        $r = $this->assigner->assign('BRAND BARU', 'Model Misterius');

        $this->assertNull($r['category']);
        $this->assertNull($r['size']);
        $this->assertSame('low', $r['confidence']);
    }

    public function test_size_dikosongkan_untuk_kategori_tak_berukuran(): void
    {
        // Kamus tidak pernah menyimpan size utk kategori non-sizable, tapi
        // guard harus tetap membuangnya bila terjadi.
        $r = $this->assigner->assign('SUZUKI', 'Jimny');

        $this->assertSame('Off-Road', $r['category']);
        $this->assertNull($r['size']);
    }

    public function test_powertrain_valid_dipakai_apa_adanya(): void
    {
        $r = $this->assigner->assign('TOYOTA', 'Avanza', null, 'G', 'bev');

        $this->assertSame('BEV', $r['powertrain']);
    }

    public function test_powertrain_diderivasi_dari_fuel(): void
    {
        $cases = [
            ['G', 'G'], ['D', 'D'], ['CNG', 'CNG'], ['FCEV', 'FCEV'],
            ['EV', 'BEV'], ['HYBRID', 'HEV'], ['HEV', 'HEV'], ['PHEV', 'PHEV'],
        ];

        foreach ($cases as [$fuel, $expected]) {
            $r = $this->assigner->assign('TOYOTA', 'Model Tak Dikenal', null, $fuel);
            $this->assertSame($expected, $r['powertrain'], "FUEL {$fuel}");
        }
    }

    public function test_powertrain_fallback_kamus_bev(): void
    {
        $r = $this->assigner->assign('BYD', 'Atto 1', null, null);

        $this->assertSame('BEV', $r['powertrain']);
        $this->assertSame('City Car', $r['category']);
    }

    public function test_powertrain_tidak_diketahui_null(): void
    {
        $r = $this->assigner->assign('BRAND BARU', 'Model Misterius', null, null, null);

        $this->assertNull($r['powertrain']);
    }

    public function test_normalisasi_kategori_dan_size(): void
    {
        $this->assertSame('MPV', VehicleCategories::normalizeCategory('mpv'));
        $this->assertSame('Truk Ringan', VehicleCategories::normalizeCategory('truk ringan'));
        $this->assertNull(VehicleCategories::normalizeCategory('Kapal Selam'));
        $this->assertSame('Small', VehicleCategories::normalizeSize('small'));
        $this->assertNull(VehicleCategories::normalizeSize('Humongous'));
    }

    public function test_grup_penumpang_dan_komersial(): void
    {
        $this->assertSame('Penumpang', VehicleCategories::groupOf('MPV'));
        $this->assertSame('Komersial', VehicleCategories::groupOf('Truk Berat'));
        $this->assertSame('Komersial', VehicleCategories::groupOf('Bus'));
        $this->assertNull(VehicleCategories::groupOf(null));
        $this->assertNull(VehicleCategories::groupOf('Ngawur'));
    }

    public function test_aturan_pola_penumpang_varian_panjang(): void
    {
        $cases = [
            ['TOYOTA', 'Avanza 1.5 G MT', 'MPV', 'Small'],
            ['TOYOTA', 'Kijang Innova Zenix', 'MPV', 'Medium'],
            ['TOYOTA', 'Alphard 2.5 HEV', 'MPV', 'Large'],
            ['TOYOTA', 'Hi-Ace Premio', 'Van/Minibus', null],
            ['SUZUKI', 'Ertiga Hybrid', 'MPV', 'Small'],
            ['SUZUKI', 'Carry Pick Up', 'Pickup', null],
            ['HONDA', 'BR-V Prestige', 'SUV', 'Small'],
            ['MAZDA', 'CX-5 GT', 'SUV', 'Medium'],
            ['SCANIA', 'K-Series', 'Bus', null],
            ['UD TRUCKS', 'CDE', 'Truk Berat', null],
            ['LEXUS', 'ES 250', 'Sedan', 'Large'],
            ['LEXUS', 'RX 350', 'SUV', 'Medium'],
            ['BMW', '218i Gran Coupe', 'Sedan', 'Medium'],
            ['BMW', 'X1 sDrive18i', 'SUV', 'Small'],
            ['MERCEDES BENZ PC', 'C 200 AVA Line (W206)', 'Sedan', 'Medium'],
            ['MERCEDES BENZ PC', 'CLA 200 AMG Line (C118)', 'Sedan', 'Medium'],
            ['MERCEDES BENZ', 'O 500 RSD', 'Bus', null],
            ['AUDI', 'A6 2.0TFSI AT', 'Sedan', 'Medium'],
        ];

        foreach ($cases as [$brand, $model, $category, $size]) {
            $r = $this->assigner->assign($brand, $model);
            $this->assertSame($category, $r['category'], "$brand $model category");
            $this->assertSame($size, $r['size'], "$brand $model size");
        }

        // MINI Cooper Countryman kena kamus (exact), bukan rule.
        $this->assertSame('SUV', $this->assigner->assign('MINI', 'Cooper Countryman')['category']);
    }
}
