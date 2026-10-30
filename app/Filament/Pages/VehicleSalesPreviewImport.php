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
     * Kelompokkan kombinasi BARU per KELUARGA (brand + model hasil pemecah
     * nama): kategori/size melekat pada model — semua varian type di bawah
     * keluarga yang sama mengikuti SATU keputusan, tak mungkin beda-beda.
     *
     * @return list<array{key: string, brand: string, model: string, units: int, fuels: list<string>, brand_name: ?string, rows: list<int>}>
     */
    public function newGroups(): array
    {
        $groups = [];

        foreach ($this->result['new'] ?? [] as $i => $row) {
            $brandName = $row['brand_name'] ?? null;
            $groupBrand = $brandName ?: $row['raw_brand'];
            $key = (string) preg_replace(
                '/[^A-Z0-9]/u',
                '',
                mb_strtoupper(trim((string) $groupBrand).' '.trim((string) $row['model'])),
            );

            $g = $groups[$key] ??= [
                'key' => $key,
                'brand' => $groupBrand,
                'model' => $row['model'],
                'units' => 0,
                'fuels' => [],
                'brand_name' => $brandName,
                'rows' => [],
            ];

            $g['units'] += $row['units'];
            $g['fuels'][] = $row['fuel'] ?? '—';
            $g['rows'][] = (int) $i;

            $groups[$key] = $g;
        }

        $list = array_values($groups);
        usort($list, fn (array $a, array $b) => $b['units'] <=> $a['units']);

        return $list;
    }

    /**
     * Tautkan SATU KELOMPOK keluarga sekaligus: kategori/size diambil dari
     * form keluarga (satu keputusan), semua varian type di bawahnya dibuat
     * via VehicleSalesPendingLinker — analisis ulang otomatis memindahkan
     * seluruh varian ke "Ter-match Katalog".
     */
    public function addToConnecting(string $groupKey): void
    {
        $group = collect($this->newGroups())->first(fn (array $g): bool => $g['key'] === $groupKey);

        if ($group === null) {
            return;
        }

        $res = $this->linkGroup($group);

        if ($res['linked'] === 0) {
            $this->newRowMessage = '✗ "'.$group['brand'].' '.$group['model'].'": '.implode('; ', $res['errors'] ?: ['tidak ada varian tertaut']);

            return;
        }

        $category = VehicleCategories::normalizeCategory($this->newRowForms[$groupKey]['category'] ?? null);

        // Analisis disegarkan OTOMATIS dari salinan file — ringkasan, tabel,
        // dan gerbang impor langsung memantahkan/membuka tanpa perlu klik
        // "Mulai Analisis Laporan" ulang.
        $this->refreshAnalysis();

        $this->newRowMessage = sprintf(
            '✓ "%s %s" (kategori %s): %d varian type tertaut — analisis diperbarui, varian pindah ke Ter-match Katalog.%s',
            $group['brand'],
            $group['model'],
            $category ?? '?',
            $res['linked'],
            $res['errors'] !== [] ? ' Terlewat: '.implode('; ', array_slice($res['errors'], 0, 3)).'.' : '',
        );
    }

    /**
     * Tautkan satu kelompok keluarga — logika bersama tombol per-kelompok
     * dan bulk. Kategori wajib; powertrain per varian dari FUEL (prefill
     * baris), fallback default keluarga. Tiap varian transaksi sendiri.
     *
     * @param  array<string, mixed>  $group
     * @return array{linked: int, errors: list<string>}
     */
    protected function linkGroup(array $group): array
    {
        $form = $this->newRowForms[$group['key']] ?? [];
        $category = VehicleCategories::normalizeCategory($form['category'] ?? null);

        if ($category === null) {
            return ['linked' => 0, 'errors' => ['pilih kategori model dulu']];
        }

        $size = VehicleCategories::normalizeSize($form['size'] ?? null);

        if (! in_array($category, VehicleCategories::SIZABLE, true)) {
            $size = null; // size hanya untuk kategori ber-ukuran
        }

        $out = ['linked' => 0, 'errors' => []];

        foreach ($group['rows'] as $i) {
            $row = $this->result['new'][$i] ?? null;

            if ($row === null || isset($this->newRowSaved[$i])) {
                continue;
            }

            // Powertrain PER VARIAN: pilihan admin utk varian ini menang,
            // fallback prefill FUEL barisnya.
            $powertrain = strtoupper(trim((string) ($form['pt'][$i] ?? ''))) ?: $row['powertrain'];

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
                $out['errors'][] = $row['raw_model'].' ('.$e->getMessage().')';

                continue;
            }

            $this->newRowSaved[$i] = true;
            $out['linked']++;
        }

        return $out;
    }

    /**
     * Tautkan SEKALIGUS semua KELOMPOK yang kategorinya sudah diisi —
     * kelompok tanpa kategori dilewati & dilaporkan; tiap varian
     * bertransaksi sendiri agar satu kegagalan tidak membatalkan batch.
     */
    public function bulkAddToConnecting(): void
    {
        $linkedGroups = 0;
        $linkedVariants = 0;
        /** @var list<string> $skipped */
        $skipped = [];

        foreach ($this->newGroups() as $group) {
            $pending = array_filter(
                $group['rows'],
                fn (int $i): bool => ! isset($this->newRowSaved[$i]),
            );

            if ($pending === []) {
                continue;
            }

            $res = $this->linkGroup($group);

            if ($res['linked'] === 0) {
                $skipped[] = $group['brand'].' '.$group['model'].' ('.implode('; ', $res['errors']).')';

                continue;
            }

            $linkedGroups++;
            $linkedVariants += $res['linked'];

            if ($res['errors'] !== []) {
                $skipped[] = $group['brand'].' '.$group['model'].' — sebagian: '.implode('; ', array_slice($res['errors'], 0, 2));
            }
        }

        if ($linkedGroups === 0) {
            $this->newRowMessage = '✗ Tidak ada yang tertaut — '.($skipped !== []
                ? implode('; ', array_slice($skipped, 0, 5))
                : 'tidak ada kelompok baru tersisa.');

            return;
        }

        // Analisis disegarkan OTOMATIS — ringkasan & gerbang impor langsung
        // diperbarui tanpa klik "Mulai Analisis Laporan" ulang.
        $this->refreshAnalysis();

        $this->newRowMessage = sprintf(
            '✓ Bulk: %d keluarga (%d varian type) tertaut — analisis diperbarui%s.',
            $linkedGroups,
            $linkedVariants,
            $skipped !== [] ? '. Catatan: '.implode('; ', array_slice($skipped, 0, 3)) : '',
        );
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

        $this->mapMessage = '✓ Mapping tersimpan — analisis diperbarui otomatis. Untuk stats lama, jalankan vehicle-mapping:relink.';
        $this->mapRawBrand = $this->mapRawModel = $this->mapBrandName = $this->mapModelName = $this->mapCatatan = null;

        // Segarkan hasil analisis dari salinan file (bukan temp upload).
        $this->refreshAnalysis();
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
        $this->showSkipped = false;

        // Salin ke storage lokal SEKARANG — file temp Livewire hilang antar
        // request; salinan ini yang dipakai penyegaran analisis otomatis.
        $this->csvPath = $this->csvFile->store('gaikindo-csv', 'local');
        $this->importMessage = null;

        // Tahun SELALU mengikuti file terbaru yang diunggah (deteksi dari
        // nama file) — nilai manual hanya dipertahankan bila nama file
        // tidak memuat tahun, mencegah tahun basi dari file sebelumnya.
        if (preg_match('/(20\d{2})/', $this->csvFile->getClientOriginalName(), $m) === 1) {
            $this->importYear = $m[1];
        }

        $this->runAnalysis(Storage::disk('local')->path($this->csvPath));
    }

    /**
     * Jalankan analisis dari path file + prefill form varian — dipakai
     * upload awal MAUPUN penyegaran otomatis setelah menautkan keluarga /
     * mapping (isClean selalu segar, tombol impor tak perlu klik analisis
     * ulang manual). Isian form keluarga yang belum disimpan dipertahankan.
     */
    protected function runAnalysis(string $realPath): bool
    {
        $previousForms = $this->newRowForms;

        $this->newRowForms = [];
        $this->newRowSaved = [];
        $this->newRowMessage = null;

        try {
            $this->result = app(VehicleSalesPreviewService::class)->analyze(
                $realPath,
                $this->month,
            );
        } catch (RuntimeException $e) {
            $this->result = null;
            $this->error = $e->getMessage();

            return false;
        }

        // Prefill POWERTRAIN PER VARIAN (dari FUEL file: G/D/CNG → ICE,
        // Hybrid/HEV → HEV, PHEV → PHEV, BEV → BEV) ke STATE — atribut
        // selected server-side ditimpa sinkronisasi Livewire bila
        // propertinya belum ada di state. Kategori/size tetap level
        // keluarga; powertrain level type. Pilihan admin utk keluarga yang
        // belum disimpan dipertahankan.
        foreach ($this->newGroups() as $group) {
            $prev = $previousForms[$group['key']] ?? [];

            $this->newRowForms[$group['key']] = [
                'category' => $prev['category'] ?? null,
                'size' => $prev['size'] ?? null,
                'pt' => [],
            ];

            foreach ($group['rows'] as $i) {
                $prefill = ($this->result['new'][$i]['powertrain'] ?? '') !== ''
                    ? $this->result['new'][$i]['powertrain']
                    : null;

                $this->newRowForms[$group['key']]['pt'][$i] = $prev['pt'][$i] ?? $prefill;
            }
        }

        return true;
    }

    /** Segarkan analisis dari salinan file di disk (bila ada). */
    protected function refreshAnalysis(): bool
    {
        if ($this->csvPath === null) {
            return false;
        }

        return $this->runAnalysis(Storage::disk('local')->path($this->csvPath));
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
            $this->importMessage = "✗ Import ditolak: masih ada {$s['new']} kombinasi baru dan {$s['skipped']} baris dilewati. Bereskan lewat tabel di atas — analisis menyegarkan otomatis setiap penautan.";

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
