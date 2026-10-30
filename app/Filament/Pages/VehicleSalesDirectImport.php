<?php

namespace App\Filament\Pages;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use App\Services\VehicleNameSplitter;
use App\Services\VehicleSalesPendingLinker;
use App\Support\VehicleCategories;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Halaman "Impor Langsung Penjualan" — jalur cepat bulanan: upload CSV/XLSX
 * GAIKINDO → stats LANGSUNG masuk (raw preserved, tanpa pra-analisis) →
 * kombinasi belum ter-link tampil sebagai worklist yang dikelompokkan per
 * KELUARGA (brand + model): kategori/size melekat pada model (semua type
 * pasti sama — keputusan satu nilai per keluarga), powertrain per type
 * dari FUEL file. Satu grup ditautkan sekaligus; semua type di bawahnya
 * dibuat otomatis. Jalur ketat tetap di halaman Preview Impor Penjualan.
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

    /**
     * Form per GRUP keluarga (brand+model), berkunci squashed key grup —
     * kategori/size satu nilai per model; powertrain = default utk type
     * yang FUEL-nya kosong.
     *
     * @var array<string, array{brand?: string, model?: string, powertrain?: ?string, category?: ?string, size?: ?string}>
     */
    public array $forms = [];

    /** Grup terpilih untuk bulk. @var array<string, bool> */
    public array $selected = [];

    /** Isian massal: kategori/size yang diterapkan ke grup terpilih. */
    public ?string $bulkCategory = null;

    public ?string $bulkSize = null;

    public ?string $bulkMessage = null;

    public ?string $linkMessage = null;

    /** @var list<array<mixed>>|null memo pending per-request */
    protected ?array $pendingMemo = null;

    /**
     * Prefill form ke STATE (sinkronisasi Livewire menimpa select/input
     * yang propertinya belum ada di state). Kategori/size di-prefill dari
     * katalog bila keluarga sudah ada (keputusan model lama menang);
     * POWERTRAIN per TYPE dari FUEL barisnya (G/D/CNG → ICE, Hybrid/HEV
     * → HEV, PHEV → PHEV, BEV → BEV) — bukan level keluarga.
     */
    public function booted(): void
    {
        foreach ($this->pending() as $group) {
            $this->forms[$group['key']] ??= [];

            foreach (['brand' => $group['brand'], 'model' => $group['model']] as $field => $value) {
                $this->forms[$group['key']][$field] ??= $value;
            }

            if (! array_key_exists('category', $this->forms[$group['key']])) {
                $this->forms[$group['key']]['category'] = $group['catalog_category'];
            }

            if (! array_key_exists('size', $this->forms[$group['key']])) {
                $this->forms[$group['key']]['size'] = $group['catalog_size'];
            }

            foreach ($group['types'] as $type) {
                $this->forms[$group['key']]['pt'][$type['key']] ??= ($type['powertrain'] !== '' ? $type['powertrain'] : null);
            }

            $this->selected[$group['key']] ??= true;
        }
    }

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

    public static function getNavigationBadge(): ?string
    {
        $count = count(self::pendingFamilyGroups());

        return $count > 0 ? ($count > 99 ? '99+' : (string) $count) : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /** Squash identitas — sama dgn hook saving VehicleConnecting. */
    protected static function squash(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper(trim($value)));
    }

    /** Normalisasi nilai powertrain stats/splitter ke bucket pilihan form. */
    protected static function bucket(mixed $value): ?string
    {
        $pt = VehicleNameSplitter::fuelToPowertrain($value);

        if ($pt !== null) {
            return $pt;
        }

        $pt = strtoupper(trim((string) $value));

        return in_array($pt, self::powertrainOptions(), true) ? $pt : null;
    }

    /** Kombinasi mentah pending di-agregasi per teks (squashed key). */
    protected static function pendingCombos(): array
    {
        $combos = [];

        VehicleSalesStat::query()
            ->whereNull('model_vehicle_id')
            ->get(['raw_brand', 'raw_model', 'powertrain', 'year', 'units'])
            ->each(function ($r) use (&$combos): void {
                $rawBrand = trim((string) $r->raw_brand);
                $rawModel = trim((string) $r->raw_model);
                $key = self::squash($rawBrand.' '.$rawModel);

                $c = $combos[$key] ??= [
                    'raw_brand' => $rawBrand,
                    'raw_model' => $rawModel,
                    'units' => 0,
                    'years' => [],
                    'stat_powertrain' => null,
                ];

                $c['units'] += (int) $r->units;
                $c['years'][$r->year] = true;

                if ($c['stat_powertrain'] === null && filled($r->powertrain)) {
                    $c['stat_powertrain'] = strtoupper((string) $r->powertrain);
                }

                $combos[$key] = $c;
            });

        return $combos;
    }

    /**
     * Worklist pending dikelompokkan per KELUARGA (brand + model hasil
     * pemecah nama): kategori/size = satu keputusan per model; tiap
     * kombinasi mentah menjadi type di bawahnya dgn powertrain dari FUEL
     * barisnya (fallback powertrain stats/splitter). Urut unit terbesar.
     *
     * @return list<array{key: string, raw_brand: string, brand: string, model: string, units: int, years: string, fuels: list<string>, powertrain: string, catalog_model_id: ?int, catalog_category: ?string, catalog_size: ?string, types: list<array{key: string, raw: string, raw_model: string, fuel: ?string, powertrain: string, units: int}>}>
     */
    public function pending(): array
    {
        if ($this->pendingMemo !== null) {
            return $this->pendingMemo;
        }

        $combos = self::pendingCombos();

        // FUEL mentah file per kombinasi dari master CONNECTING — referensi
        // utama powertrain per type.
        $connectingFuel = VehicleConnecting::query()
            ->whereIn('raw_gabungan_key', array_keys($combos))
            ->pluck('fuel', 'raw_gabungan_key');

        // Kelompokkan kombinasi mentah → keluarga.
        $splitter = app(VehicleNameSplitter::class);
        $groups = [];

        foreach ($combos as $key => $c) {
            $split = $splitter->split($c['raw_brand'], $c['raw_model'], null);
            $family = trim((string) ($split['model'] ?? '')) !== '' ? $split['model'] : $c['raw_model'];

            $fuel = $connectingFuel[$key] ?? null;

            $powertrain = VehicleNameSplitter::fuelToPowertrain($fuel)
                ?? self::bucket($c['stat_powertrain'])
                ?? self::bucket($split['powertrain'] ?? null)
                ?? '';

            $gKey = self::squash($c['raw_brand'].' '.$family);

            $g = $groups[$gKey] ??= [
                'key' => $gKey,
                'raw_brand' => $c['raw_brand'],
                'family' => $family,
                'units' => 0,
                'years' => [],
                'fuels' => [],
                'types' => [],
            ];

            $g['units'] += $c['units'];
            $g['years'] += $c['years'];
            $g['fuels'][] = $fuel !== null ? strtoupper(trim((string) $fuel)) : '—';
            $g['types'][] = [
                'key' => $key,
                'raw' => $c['raw_brand'].' '.$c['raw_model'],
                'raw_model' => $c['raw_model'],
                'fuel' => $fuel !== null ? strtoupper(trim((string) $fuel)) : null,
                'powertrain' => (string) $powertrain,
                'units' => $c['units'],
            ];

            $groups[$gKey] = $g;
        }

        // Prefill kategori/size dari katalog bila keluarga sudah ada —
        // keputusan katalog lama menang, form tidak menimpanya.
        $catalogModels = ModelVehicle::query()->with('brandVehicle')->get();

        foreach ($groups as $gKey => $g) {
            foreach ($catalogModels as $m) {
                if (self::squash((string) $m->brandVehicle?->name) === self::squash($g['raw_brand'])
                    && self::squash((string) $m->name) === self::squash($g['family'])) {
                    $groups[$gKey]['catalog_model_id'] = $m->id;
                    $groups[$gKey]['catalog_category'] = $m->category;
                    $groups[$gKey]['catalog_size'] = $m->size_class;

                    break;
                }
            }
        }

        $result = [];

        foreach ($groups as $g) {
            usort($g['types'], fn ($a, $b) => $b['units'] <=> $a['units']);

            $result[] = [
                'key' => $g['key'],
                'raw_brand' => $g['raw_brand'],
                'brand' => $g['raw_brand'],
                'model' => $g['family'],
                'units' => $g['units'],
                'years' => implode(', ', array_keys($g['years'])),
                'fuels' => array_values(array_unique($g['fuels'])),
                'powertrain' => $g['types'][0]['powertrain'] ?? '',
                'catalog_model_id' => $g['catalog_model_id'] ?? null,
                'catalog_category' => $g['catalog_category'] ?? null,
                'catalog_size' => $g['catalog_size'] ?? null,
                'types' => $g['types'],
            ];
        }

        usort($result, fn ($a, $b) => $b['units'] <=> $a['units']);

        return $this->pendingMemo = $result;
    }

    /** Keluarga unik pending (versi ringan utk badge navigasi). @return list<string> */
    protected static function pendingFamilyGroups(): array
    {
        $families = [];
        $splitter = app(VehicleNameSplitter::class);

        foreach (self::pendingCombos() as $c) {
            $split = $splitter->split($c['raw_brand'], $c['raw_model'], null);
            $family = trim((string) ($split['model'] ?? '')) !== '' ? $split['model'] : $c['raw_model'];
            $families[self::squash($c['raw_brand'].' '.$family)] = true;
        }

        return array_keys($families);
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

        // Tahun SELALU mengikuti file yang diunggah (deteksi dari nama
        // file) — nilai manual hanya bertahan bila nama file tanpa tahun.
        if (preg_match('/(20\d{2})/', (string) $this->file->getClientOriginalName(), $m) === 1) {
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
            ? "✓ Import selesai — {$pending} keluarga model pending menunggu kelengkapan di bawah."
            : '✓ Import selesai — semua kombinasi otomatis ter-link, tidak ada pekerjaan pending.';
    }

    /**
     * Tautkan SATU GRUP keluarga: kategori/size model diambil dari form
     * grup (satu keputusan per model), tiap type di bawahnya dibuat dengan
     * powertrain dari FUEL barisnya (fallback default grup). Nilai yang
     * diketik admin menang atas prefill.
     *
     * @param  array<string, mixed>  $group
     * @return array{ok: bool, errors: list<string>, types: int, stats: int}
     */
    protected function linkGroup(array $group): array
    {
        $form = array_merge(
            [
                'brand' => $group['brand'],
                'model' => $group['model'],
                'category' => $group['catalog_category'],
                'size' => $group['catalog_size'],
            ],
            $this->forms[$group['key']] ?? [],
        );

        $category = VehicleCategories::normalizeCategory($form['category'] ?? null);

        if ($category === null) {
            return ['ok' => false, 'errors' => ['pilih kategori model dulu'], 'types' => 0, 'stats' => 0];
        }

        $out = ['ok' => true, 'errors' => [], 'types' => 0, 'stats' => 0];
        $linker = app(VehicleSalesPendingLinker::class);

        foreach ($group['types'] as $type) {
            // Powertrain PER TYPE: pilihan admin utk varian ini menang,
            // fallback prefill FUEL barisnya.
            $typePt = strtoupper(trim((string) ($form['pt'][$type['key']] ?? ''))) ?: ($type['powertrain'] !== '' ? $type['powertrain'] : null);

            try {
                $res = $linker->link(
                    $group['raw_brand'],
                    $type['raw_model'],
                    [
                        'brand' => $form['brand'],
                        'model' => $form['model'],
                        'category' => $category,
                        'size' => $form['size'] ?? null,
                        'type' => $type['raw_model'],
                        'powertrain' => $typePt,
                    ],
                );
            } catch (RuntimeException $e) {
                $out['errors'][] = $type['raw'].' ('.$e->getMessage().')';

                continue;
            }

            $out['types']++;
            $out['stats'] += $res['stats_linked'];
        }

        if ($out['types'] > 0) {
            unset($this->forms[$group['key']]);
        }

        return $out;
    }

    /** Simpan & Tautkan satu grup keluarga (semua type-nya sekaligus). */
    public function linkCombo(string $key): void
    {
        $group = collect($this->pending())->first(fn (array $g): bool => $g['key'] === $key);

        if ($group === null) {
            return;
        }

        $res = $this->linkGroup($group);

        if (! $res['ok'] || $res['types'] === 0) {
            $this->linkMessage = '✗ "'.$group['brand'].' '.$group['model'].'": '.implode('; ', $res['ok'] ? $res['errors'] : ['tidak ada type tertaut']);

            return;
        }

        $this->linkMessage = sprintf(
            '✓ "%s %s": %d type tertaut, %d baris stats di-back-link%s.',
            $group['brand'],
            $group['model'],
            $res['types'],
            $res['stats'],
            $res['errors'] !== [] ? ' — terlewat: '.implode('; ', array_slice($res['errors'], 0, 3)) : '',
        );
    }

    /**
     * Terapkan kategori (+size bila berlaku) ke seluruh grup terpilih —
     * batch homogen (mis. semua truk) sebelum Tautkan Semua.
     */
    public function applyBulkCategory(): void
    {
        $category = VehicleCategories::normalizeCategory($this->bulkCategory);

        if ($category === null) {
            $this->bulkMessage = '✗ Pilih kategori massal dulu.';

            return;
        }

        // Size massal opsional — hanya dipakai bila kategorinya ber-ukuran.
        $size = in_array($category, VehicleCategories::SIZABLE, true)
            ? VehicleCategories::normalizeSize($this->bulkSize)
            : null;

        $applied = 0;

        foreach ($this->pending() as $group) {
            if (! ($this->selected[$group['key']] ?? false)) {
                continue;
            }

            $this->forms[$group['key']]['category'] = $category;

            if ($size !== null) {
                $this->forms[$group['key']]['size'] = $size;
            }

            $applied++;
        }

        $this->bulkMessage = '✓ '.($applied > 0
            ? '"'.$category.'"'.($size !== null ? ' / '.$size : '')." diterapkan ke {$applied} keluarga terpilih — lanjut ⚡ Tautkan Semua."
            : 'Tidak ada keluarga terpilih.');
    }

    /**
     * Tautkan SEKALIGUS seluruh grup terpilih — grup tanpa kategori (dan
     * tanpa kategori katalog) dilewati & dilaporkan; tiap type bertransaksi
     * sendiri agar satu kegagalan tidak membatalkan batch.
     */
    public function bulkLink(): void
    {
        $linkedGroups = 0;
        $linkedTypes = 0;
        $statsLinked = 0;
        /** @var list<string> $skipped */
        $skipped = [];

        foreach ($this->pending() as $group) {
            if (! ($this->selected[$group['key']] ?? false)) {
                continue;
            }

            $res = $this->linkGroup($group);

            if (! $res['ok'] || $res['types'] === 0) {
                $skipped[] = $group['brand'].' '.$group['model'].' ('.implode('; ', $res['ok'] ? $res['errors'] : ['tidak ada type tertaut']).')';

                continue;
            }

            $linkedGroups++;
            $linkedTypes += $res['types'];
            $statsLinked += $res['stats'];

            if ($res['errors'] !== []) {
                $skipped[] = $group['brand'].' '.$group['model'].' — sebagian: '.implode('; ', array_slice($res['errors'], 0, 2));
            }
        }

        if ($linkedGroups === 0) {
            $this->linkMessage = '✗ Tidak ada yang tertaut — '.(count($skipped) > 0
                ? implode('; ', array_slice($skipped, 0, 5))
                : 'tidak ada keluarga terpilih.');

            return;
        }

        $this->linkMessage = sprintf(
            '✓ Bulk selesai: %d keluarga (%d type) tertaut, %d baris stats di-back-link%s.',
            $linkedGroups,
            $linkedTypes,
            $statsLinked,
            $skipped !== [] ? ' — catatan '.count($skipped).': '.implode('; ', array_slice($skipped, 0, 5)) : '',
        );
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), [
            'pendingRows' => $this->pending(),
            'brandNames' => BrandVehicle::query()->orderBy('name')->pluck('name'),
        ]);
    }
}
