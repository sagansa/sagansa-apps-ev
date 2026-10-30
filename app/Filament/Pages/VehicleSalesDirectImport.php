<?php

namespace App\Filament\Pages;

use App\Models\BrandVehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use App\Services\VehicleNameSplitter;
use App\Services\VehicleSalesPendingLinker;
use App\Support\VehicleCategories;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Halaman "Impor Langsung Penjualan" — jalur cepat bulanan: upload CSV/XLSX
 * GAIKINDO → stats LANGSUNG masuk (raw preserved, tanpa pra-analisis) →
 * kombinasi yang belum ter-link tampil sebagai worklist "Kombinasi Pending"
 * yang tinggal dilengkapi (Brand/Model/Type/Powertrain/Category/Size) lalu
 * di-tautkan satu klik. Jalur ketat (analisis dulu) tetap di halaman
 * Preview Impor Penjualan.
 */
class VehicleSalesDirectImport extends Page
{
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-m-arrow-up-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Referensi Kendaraan';

    protected static ?string $navigationLabel = 'Impor Langsung Penjualan';

    protected static ?string $title = 'Impor Langsung Penjualan';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.vehicle-sales-direct-import';

    /** Upload sementara (Livewire temporary upload). */
    public $file;

    /** Tahun periode — auto-deteksi dari nama file, boleh dikoreksi. */
    public string $importYear = '';

    #[Url]
    public ?int $month = null;

    public ?string $importMessage = null;

    public bool $importSuccess = false;

    /** Baris tabel ringkasan output command impor. @var list<array{0: string, 1: string}> */
    public array $importSummary = [];

    /** Form inline per baris pending: brand/model/type/powertrain/category/size. */
    public array $forms = [];

    public ?string $linkMessage = null;

    /** @return list<string> */
    public static function categoryOptions(): array
    {
        return VehicleCategories::CATEGORIES;
    }

    /** @return list<string> */
    public static function sizeOptions(): array
    {
        return VehicleCategories::SIZES;
    }

    /** @return list<string> */
    public static function powertrainOptions(): array
    {
        return ['BEV', 'HEV', 'PHEV', 'ICE'];
    }

    /**
     * FUEL laporan → bucket powertrain: G/D/CNG → ICE, Hybrid/HEV → HEV,
     * PHEV → PHEV, BEV/EV → BEV (kebijakan admin 2026-10-30) — delegasi
     * ke FUEL_MAP pemecah nama agar satu sumber kebenaran.
     */
    protected static function fuelToPowertrain(?string $fuel): ?string
    {
        return VehicleNameSplitter::fuelToPowertrain($fuel);
    }

    /** Normalisasi nilai powertrain stats/splitter ke bucket pilihan form. */
    protected static function normalizePowertrainBucket(mixed $value): ?string
    {
        $pt = self::fuelToPowertrain($value);

        if ($pt !== null) {
            return $pt;
        }

        $pt = strtoupper(trim((string) $value));

        return in_array($pt, self::powertrainOptions(), true) ? $pt : null;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::pendingGroups()->count();

        return $count > 0 ? ($count > 99 ? '99+' : (string) $count) : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /**
     * Kombinasi pending = stats NULL-link dikelompokkan per teks mentah
     * (squash huruf+angka — identitas sama dgn kunci CONNECTING).
     *
     * @return Collection<int, array{raw_brand: string, raw_model: string}>
     */
    protected static function pendingGroups()
    {
        return VehicleSalesStat::query()
            ->whereNull('model_vehicle_id')
            ->get(['raw_brand', 'raw_model'])
            ->groupBy(fn ($r) => (string) preg_replace(
                '/[^A-Z0-9]/u',
                '',
                mb_strtoupper(trim((string) $r->raw_brand).' '.trim((string) $r->raw_model)),
            ));
    }

    /**
     * Worklist pending lengkap dgn prefill: Brand = raw brand; Model/Type =
     * hasil splitter (keluarga + varian penuh); Powertrain dari stats/splitter.
     * Urut unit terbesar dulu — kombinasi ber-impact besar diputuskan duluan.
     *
     * @return list<array{key: string, raw: string, raw_brand: string, raw_model: string, units: int, years: string, brand: string, model: string, type: string, fuel: ?string, powertrain: string}>
     */
    public function pending(): array
    {
        $rows = VehicleSalesStat::query()
            ->whereNull('model_vehicle_id')
            ->get(['raw_brand', 'raw_model', 'powertrain', 'year', 'units']);

        $groups = [];

        foreach ($rows as $r) {
            $rawBrand = trim((string) $r->raw_brand);
            $rawModel = trim((string) $r->raw_model);
            $key = (string) preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($rawBrand.' '.$rawModel));

            $g = $groups[$key] ??= [
                'raw_brand' => $rawBrand,
                'raw_model' => $rawModel,
                'units' => 0,
                'years' => [],
                'powertrain' => null,
            ];

            $g['units'] += (int) $r->units;
            $g['years'][$r->year] = true;

            if ($g['powertrain'] === null && filled($r->powertrain)) {
                $g['powertrain'] = strtoupper((string) $r->powertrain);
            }

            $groups[$key] = $g;
        }

        // FUEL mentah file per kombinasi dari master CONNECTING (kolom fuel
        // diisi dari file saat master dibangun) — referensi utama powertrain.
        $connectingFuel = VehicleConnecting::query()
            ->whereIn('raw_gabungan_key', array_keys($groups))
            ->pluck('fuel', 'raw_gabungan_key');

        $options = self::powertrainOptions();
        $result = [];

        foreach ($groups as $key => $g) {
            $split = app(VehicleNameSplitter::class)->split($g['raw_brand'], $g['raw_model'], null);

            // Prioritas: FUEL file (master CONNECTING) → powertrain stats
            // (diisi splitter saat impor) → powertrain splitter sekarang.
            $fuel = $connectingFuel[$key] ?? null;

            $powertrain = self::fuelToPowertrain($fuel)
                ?? self::normalizePowertrainBucket($g['powertrain'])
                ?? self::normalizePowertrainBucket($split['powertrain'] ?? null);

            if (! in_array($powertrain, $options, true)) {
                $powertrain = '';
            }

            $result[] = [
                'key' => $key,
                'raw' => $g['raw_brand'].' '.$g['raw_model'],
                'raw_brand' => $g['raw_brand'],
                'raw_model' => $g['raw_model'],
                'units' => $g['units'],
                'years' => implode(', ', array_keys($g['years'])),
                'brand' => $g['raw_brand'],
                'model' => trim((string) ($split['model'] ?? '')) !== '' ? $split['model'] : $g['raw_model'],
                'type' => $g['raw_model'],
                'fuel' => $fuel !== null ? strtoupper(trim((string) $fuel)) : null,
                'powertrain' => (string) $powertrain,
            ];
        }

        usort($result, fn ($a, $b) => $b['units'] <=> $a['units']);

        return $result;
    }

    /**
     * Impor langsung TANPA pra-analisis — jalur ketat --require-full-link
     * sengaja tidak dipakai; kombinasi yang gagal link menjadi pending di
     * worklist bawah (stats tetap tersimpan dgn raw utuh).
     */
    public function importDirect(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ], [
            'file.required' => 'Pilih file laporan (.csv / .xlsx) terlebih dahulu.',
            'file.mimes' => 'File harus berformat .csv atau .xlsx',
        ]);

        if ($this->importYear === '' && preg_match('/(20\d{2})/', (string) $this->file->getClientOriginalName(), $m) === 1) {
            $this->importYear = $m[1];
        }

        $year = (int) $this->importYear;

        if ($year < 2015 || $year > 2100) {
            $this->importMessage = '✗ Isi tahun periode (2015-2100) terlebih dahulu.';
            $this->importSuccess = false;

            return;
        }

        $this->importMessage = null;
        $this->importSummary = [];
        $this->linkMessage = null;

        $path = $this->file->store('gaikindo-csv', 'local');

        try {
            $exit = Artisan::call('vehicle-sales:import-csv', [
                'file' => Storage::disk('local')->path($path),
                '--year' => (string) $year,
                ...($this->month ? ['--month' => (string) $this->month] : []),
            ]);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            $this->importMessage = '✗ Import gagal: '.$e->getMessage();
            $this->importSuccess = false;

            return;
        }

        // Tabel ringkasan command: baris "| Keterangan | Nilai |".
        $this->importSummary = collect(explode("\n", $output))
            ->filter(fn (string $line) => str_starts_with($line, '|'))
            ->map(fn (string $line) => array_map('trim', explode('|', trim($line, '|'))))
            ->filter(fn (array $cells) => count($cells) === 2
                && $cells[0] !== '' && $cells[1] !== ''
                && $cells[0] !== 'Keterangan'
                && ! str_starts_with($cells[0], '-'))
            ->map(fn (array $cells) => [0 => $cells[0], 1 => $cells[1]])
            ->values()
            ->all();

        $pending = count($this->pending());

        if ($exit !== 0) {
            $tail = collect(explode("\n", trim($output)))->filter()->last();
            $this->importMessage = '✗ Import ditolak command: '.(string) $tail;
            $this->importSuccess = false;

            return;
        }

        $this->importSuccess = true;
        $this->importMessage = $pending > 0
            ? "✓ Import selesai — {$pending} kombinasi pending menunggu kelengkapan di bawah."
            : '✓ Import selesai — semua kombinasi otomatis ter-link, tidak ada pekerjaan pending.';
    }

    /** Simpan & Tautkan satu baris pending (buat katalog bila perlu). */
    public function linkCombo(int $index): void
    {
        $pending = $this->pending();
        $row = $pending[$index] ?? null;

        if ($row === null) {
            return;
        }

        // Nilai form: apa yang diketik admin menang atas prefill.
        $form = array_merge(
            [
                'brand' => $row['brand'],
                'model' => $row['model'],
                'type' => $row['type'],
                'powertrain' => $row['powertrain'] !== '' ? $row['powertrain'] : null,
                'category' => null,
                'size' => null,
            ],
            $this->forms[$index] ?? [],
        );

        try {
            $result = app(VehicleSalesPendingLinker::class)->link(
                $row['raw_brand'],
                $row['raw_model'],
                $form,
            );
        } catch (RuntimeException $e) {
            $this->linkMessage = '✗ "'.$row['raw'].'": '.$e->getMessage();

            return;
        }

        unset($this->forms[$index]);

        $created = array_filter([
            $result['brand_created'] ? 'brand' : null,
            $result['model_created'] ? 'model' : null,
            $result['type_created'] ? 'type' : null,
        ]);

        $this->linkMessage = '✓ "'.$row['raw'].'" tertaut'.
            ($created !== [] ? ' (katalog baru: '.implode('+', $created).')' : '').
            " — {$result['stats_linked']} baris stats di-back-link.";
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), [
            'pendingRows' => $this->pending(),
            'brandNames' => BrandVehicle::query()->orderBy('name')->pluck('name'),
        ]);
    }
}
