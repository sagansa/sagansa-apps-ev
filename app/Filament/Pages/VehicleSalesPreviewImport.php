<?php

namespace App\Filament\Pages;

use App\Models\VehicleNameMapping;
use App\Services\VehicleConnectingSyncService;
use App\Services\VehicleSalesPendingLinker;
use App\Services\VehicleSalesPreviewService;
use App\Support\VehicleCategories;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman "Preview Impor Penjualan" — GUI dari vehicle-sales:preview:
 * upload CSV laporan GAIKINDO → lihat kombinasi brand/model yang BELUM ada
 * di katalog base → unduh CSV siap gabung ke CONNECTING. Read-only, tidak
 * pernah menulis ke stats/katalog.
 */
class VehicleSalesPreviewImport extends Page
{
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-m-document-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'Referensi Kendaraan';

    protected static ?string $navigationLabel = 'Preview Impor Penjualan';

    protected static ?string $title = 'Preview Impor Penjualan vs Katalog';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.vehicle-sales-preview-import';

    /** Upload sementara (Livewire temporary upload). */
    public $csvFile;

    #[Url]
    public ?int $month = null;

    public ?array $result = null;

    public ?string $exportPath = null;

    public ?string $error = null;

    /** Form simpan mapping eksplisit dari baris "baru". */
    public ?string $mapRawBrand = null;

    public ?string $mapRawModel = null;

    public ?string $mapBrandName = null;

    public ?string $mapModelName = null;

    public ?string $mapCatatan = null;

    public ?string $mapMessage = null;

    /** Form inline per baris kombinasi baru: category/size/powertrain. */
    public array $newRowForms = [];

    /** Indeks baris yang sudah masuk CONNECTING. */
    public array $newRowSaved = [];

    public ?string $newRowMessage = null;

    /** Toggle tampil/sembunyi detail baris yang dilewati (junk). */
    public bool $showSkipped = false;

    /** Salinan persist file CSV (Livewire temp hilang antar request). */
    public ?string $csvPath = null;

    /** Tahun periode utk impor permanen. */
    public string $importYear = '';

    /** Pesan hasil impor permanen. */
    public ?string $importMessage = null;

    /** Analisis bersih = tidak ada kombinasi baru & tidak ada yang dilewati. */
    public function isClean(): bool
    {
        return $this->result !== null
            && ($this->result['summary']['new'] ?? 1) === 0
            && ($this->result['summary']['skipped'] ?? 1) === 0;
    }

    public function toggleSkipped(): void
    {
        $this->showSkipped = ! $this->showSkipped;
    }

    /** @return list<string> */
    public static function categoryOptions(): array
    {
        return VehicleCategories::CATEGORIES;
    }

    /**
     * Tautkan satu kombinasi baru SEKALIGUS: resolve/create katalog
     * (brand/model/type) + upsert CONNECTING dgn FK lengkap via
     * VehicleSalesPendingLinker — analisis ulang otomatis memindahkan
     * baris ini ke "Ter-match Katalog" (menggantikan alur lama
     * Sync CONNECTING → Terapkan ke Katalog yang tidak back-link).
     */
    public function addToConnecting(int $index): void
    {
        $row = $this->result['new'][$index] ?? null;

        if ($row === null) {
            return;
        }

        $form = $this->newRowForms[$index] ?? [];
        $powertrain = strtoupper(trim((string) ($form['powertrain'] ?? ''))) ?: $row['powertrain'];
        $category = VehicleCategories::normalizeCategory($form['category'] ?? null);
        $size = VehicleCategories::normalizeSize($form['size'] ?? null);

        if ($category === null) {
            $this->newRowMessage = "✗ '{$row['brand']}': pilih kategori dulu.";

            return;
        }

        if (! in_array($category, VehicleCategories::SIZABLE, true)) {
            $size = null; // size hanya untuk kategori ber-ukuran
        }

        // Brand katalog dipakai bila dikenali (badge "model baru di X");
        // selain itu brand mentah laporan — koreksi via Mapping Eksplisit
        // bila ejaannya berbeda dari katalog (mis. WULING-DBG → Wuling).
        try {
            app(VehicleSalesPendingLinker::class)->link(
                $row['raw_brand'],
                $row['raw_model'],
                [
                    'brand' => $row['brand_name'] ?: $row['raw_brand'],
                    'model' => $row['model'],
                    'type' => $row['type'],
                    'powertrain' => $powertrain !== '' ? $powertrain : null,
                    'category' => $category,
                    'size' => $size,
                ],
            );
        } catch (RuntimeException $e) {
            $this->newRowMessage = '✗ '.$e->getMessage();

            return;
        }

        $this->newRowSaved[$index] = true;
        $this->newRowMessage = "✓ '{$row['brand']}' tertaut ke katalog + CONNECTING ({$category}".($size !== null ? " / {$size}" : '').').'.
            ' Klik "Mulai Analisis Laporan" ulang — baris ini pindah ke Ter-match Katalog.';
    }

    public function saveMapping(): void
    {
        $this->validate([
            'mapRawBrand' => ['required', 'string', 'max:255'],
            'mapRawModel' => ['required', 'string', 'max:255'],
            'mapBrandName' => ['required', 'string', 'max:255'],
            'mapModelName' => ['required', 'string', 'max:255'],
        ], [
            'mapRawBrand.required' => 'Raw brand wajib diisi.',
            'mapRawModel.required' => 'Raw model wajib diisi.',
            'mapBrandName.required' => 'Brand katalog wajib diisi.',
            'mapModelName.required' => 'Model katalog wajib diisi.',
        ]);

        $mapping = VehicleNameMapping::record(
            $this->mapRawBrand,
            $this->mapRawModel,
            $this->mapBrandName,
            $this->mapModelName,
            null,
            $this->mapCatatan,
        );

        if ($mapping === null) {
            $this->mapMessage = "✗ Katalog '{$this->mapBrandName} / {$this->mapModelName}' belum ada — buat dulu lewat Brand Vehicles → Import.";

            return;
        }

        $this->mapMessage = '✓ Mapping tersimpan — laporan berikutnya otomatis ter-link. Untuk stats lama, jalankan vehicle-mapping:relink.';
        $this->mapRawBrand = $this->mapRawModel = $this->mapBrandName = $this->mapModelName = $this->mapCatatan = null;

        // Segarkan hasil analisis dengan mapping baru.
        if ($this->csvFile !== null) {
            try {
                $this->result = app(VehicleSalesPreviewService::class)->analyze(
                    $this->csvFile->getRealPath(),
                    $this->month,
                );
            } catch (RuntimeException) {
                // biarkan hasil lama
            }
        }
    }

    public function analyze(): void
    {
        $this->validate([
            'csvFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ], [
            'csvFile.required' => 'Pilih file laporan (.csv / .xlsx) terlebih dahulu.',
            'csvFile.mimes' => 'File harus berformat .csv atau .xlsx',
        ]);

        $this->error = null;
        $this->exportPath = null;
        $this->newRowForms = [];
        $this->newRowSaved = [];
        $this->newRowMessage = null;
        $this->showSkipped = false;

        try {
            $this->result = app(VehicleSalesPreviewService::class)->analyze(
                $this->csvFile->getRealPath(),
                $this->month,
            );

            // Prefill powertrain (dari FUEL file: G/D/CNG → ICE, Hybrid/HEV
            // → HEV, PHEV → PHEV, BEV → BEV) ke STATE — atribut selected
            // server-side ditimpa sinkronisasi Livewire bila propertinya
            // belum ada di state.
            foreach ($this->result['new'] as $i => $row) {
                $this->newRowForms[$i]['powertrain'] ??= ($row['powertrain'] !== '' ? $row['powertrain'] : null);
            }
        } catch (RuntimeException $e) {
            $this->result = null;
            $this->error = $e->getMessage();

            return;
        }

        // Salin ke storage lokal — file temp Livewire hilang antar request.
        $this->csvPath = $this->csvFile->store('gaikindo-csv', 'local');
        $this->importMessage = null;

        if ($this->importYear === '' && preg_match('/(20\d{2})/', $this->csvFile->getClientOriginalName(), $m) === 1) {
            $this->importYear = $m[1];
        }
    }

    /**
     * Impor permanen — HANYA bila analisis bersih (0 kombinasi baru,
     * 0 dilewati). Selalu menjalankan --require-full-link sebagai jaring
     * kedua di sisi command.
     */
    public function importSales(): void
    {
        if ($this->csvPath === null || $this->result === null) {
            $this->importMessage = '✗ Jalankan analisis terlebih dahulu.';

            return;
        }

        if (! $this->isClean()) {
            $s = $this->result['summary'];
            $this->importMessage = "✗ Import ditolak: masih ada {$s['new']} kombinasi baru dan {$s['skipped']} baris dilewati. Bereskan dulu lewat tabel di atas, lalu analisis ulang.";

            return;
        }

        $year = (int) $this->importYear;

        if ($year < 2015 || $year > 2100) {
            $this->importMessage = '✗ Isi tahun periode (2015-2100) terlebih dahulu.';

            return;
        }

        try {
            $exit = \Artisan::call('vehicle-sales:import-csv', [
                'file' => Storage::disk('local')->path($this->csvPath),
                '--year' => (string) $year,
                '--require-full-link' => true,
                ...($this->month ? ['--month' => (string) $this->month] : []),
            ]);
            $output = \Artisan::output();
        } catch (\Throwable $e) {
            $this->importMessage = '✗ Import gagal: '.$e->getMessage();

            return;
        }

        $tail = implode(' | ', array_slice(explode('\n', trim($output)), -3));

        if ($exit !== 0) {
            $this->importMessage = '✗ Import ditolak command: '.$tail;

            return;
        }

        app(VehicleConnectingSyncService::class)->flushMarketCache();
        $this->importMessage = '✓ Import berhasil — stats periode '.$year.($this->month ? ' bulan '.$this->month : '').' sudah diganti (data lama periode sama tergantikan). Cache Pasar EV segar. '.$tail;
    }

    /** Unduh kombinasi baru sebagai CSV siap gabung ke CONNECTING. */
    public function downloadNew(): ?StreamedResponse
    {
        if ($this->result === null || $this->result['new'] === []) {
            return null;
        }

        $path = 'previews/connecting-baru-'.now()->format('Ymd-His').'.csv';
        Storage::disk('local')->makeDirectory(dirname($path));
        app(VehicleSalesPreviewService::class)->exportNewCsv(
            $this->result['new'],
            Storage::disk('local')->path($path),
        );

        $this->exportPath = $path;

        return Storage::disk('local')->download($path, 'CONNECTING-baru.csv');
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), [
            'result' => $this->result,
        ]);
    }
}
