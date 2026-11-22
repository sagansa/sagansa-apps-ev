<?php

namespace App\Console\Commands;

use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Seed/upsert baris `obd_vehicle_profiles` dari file JSON profil mobile
 * (kontrak: serialisasi `ObdVehicleProfile` di shared KMP — key camelCase,
 * metrics, initCommands, dll.; di-parse kembali oleh `ObdProfileJson.parse`
 * di mobile, `ignoreUnknownKeys` aman terhadap kolom envelope).
 *
 * Sumber kebenaran profil ada di mobile (ObdSeedProfiles) — file JSON di-
 * generate dari sana dan di-commit di database/seeders/data/obd-profiles/.
 * Kolom `pid_list` menyimpan JSON profil UTUH (bukan array PID sederhana).
 *
 * Match model_vehicle_id: opsional via --model="Brand Model" (dicari di
 * katalog model_vehicles — STRICT exact match, ambigu = gagal jelas) atau
 * kolom "modelVehicleId" di JSON. Semua grade (official & community) dicocokkan
 * otomatis via nama exact — brand wajib cocok, nama model dibersihkan dari
 * ornamen katalog (tahun/[RU]/[EN]/var.N), nama multi-model ("A / B / C")
 * di-skip, ambigu = gagal jelas. Substring TIDAK pernah dipakai (insiden
 * 2026-11-22: 392 profil → Seres 3). Nama yang tidak cocok katalog → mapping
 * via admin Filament.
 *
 * Contoh:
 *   php artisan obd:seed-profiles database/seeders/data/obd-profiles
 *   php artisan obd:seed-profiles path/ioniq5.json --model="Hyundai IONIQ 5"
 */
class SeedObdVehicleProfiles extends Command
{
    protected $signature = 'obd:seed-profiles
        {path : File JSON profil atau direktori berisi *.json}
        {--model= : Nama model katalog (mis. "Hyundai IONIQ 5") untuk semua file}
        {--grade=official : Grade baris yang dibuat}
        {--no-resolve : Jangan coba match model dari isi JSON}';

    protected $description = 'Seed/upsert obd_vehicle_profiles dari JSON profil mobile (kontrak ObdProfileJson)';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));

        if (! file_exists($path)) {
            $this->error("Path tidak ada: $path");

            return self::FAILURE;
        }

        $files = is_dir($path)
            ? glob(rtrim($path, '/') . '/*.json') ?: []
            : [$path];

        if ($files === []) {
            $this->error("Tidak ada file JSON di: $path");

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach ($files as $file) {
            try {
                [$profile, $action, $resolveNote] = $this->seedFile($file);
                $label = trim(($profile->metadata['brand'] ?? '') . ' ' . ($profile->metadata['model_name'] ?? ''));
                $action === 'created' ? $created++ : $updated++;
                $this->line("  <info>$action</info> {$profile->platform} — {$label} (v{$profile->version}, id={$profile->id})");
                if ($resolveNote !== null) {
                    $this->warn("      ↳ $resolveNote");
                }
            } catch (RuntimeException $e) {
                $this->warn("  <comment>lewati</comment> " . basename($file) . ": {$e->getMessage()}");
            }
        }

        $this->info("Selesai: $created baru, $updated diperbarui.");

        return self::SUCCESS;
    }

    /** @return array{0: ObdVehicleProfile, 1: string, 2: ?string} */
    private function seedFile(string $file): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('gagal baca file');
        }

        $json = json_decode($raw, true);
        if (! is_array($json) || ! isset($json['platform'])) {
            throw new RuntimeException('JSON tidak valid / tanpa "platform"');
        }

        [$modelVehicleIds, $resolveNote] = $this->resolveModelVehicleIds($json);

        $primaryModelVehicleId = $modelVehicleIds[0] ?? null;

        $attributes = [
            'model_vehicle_id' => $primaryModelVehicleId,
            'platform' => (string) $json['platform'],
        ];

        // Kunci upsert: profil katalog (platform CS-<hash8>) unik per profil —
        // hash adalah ID internal Car Scanner yang stabil antar versi, jadi
        // kunci = platform saja agar mapping (pivot obd_profile_model_vehicles)
        // tidak memecah baris saat re-seed. Profil manual (platform generik
        // mis. E-GMP) tetap komposit platform+model/vin karena platform bisa
        // dipakai beberapa model.
        $isCatalogPlatform = str_starts_with((string) $json['platform'], 'CS-');
        $query = ObdVehicleProfile::query()
            ->where('platform', $attributes['platform']);

        if (! $isCatalogPlatform) {
            $query->where(function ($q) use ($primaryModelVehicleId, $json) {
                if ($primaryModelVehicleId !== null) {
                    $q->where('model_vehicle_id', $primaryModelVehicleId);
                } else {
                    $q->whereNull('model_vehicle_id')
                        ->where('vin_pattern', $json['vinPattern'] ?? null);
                }
            });
        }

        $existing = $query->first();

        // Lindungi mapping admin via Filament: resolve kosong tidak mengubah
        // pivot/primary existing — hanya resolve hasil (exact-match / --model /
        // JSON id) yang menimpa.
        $effectivePrimary = $primaryModelVehicleId ?? $existing?->model_vehicle_id;

        $values = [
            'model_vehicle_id' => $effectivePrimary,
            'platform' => (string) $json['platform'],
            'vin_pattern' => $json['vinPattern'] ?? null,
            'year_min' => $json['yearMin'] ?? null,
            'year_max' => $json['yearMax'] ?? null,
            'grade' => (string) $this->option('grade'),
            'version' => (string) ($json['version'] ?? '1.0'),
            'status' => 'active',
            'pid_list' => $json, // Kontrak: JSON profil mobile UTUH.
            'metadata' => [
                'source' => 'obd:seed-profiles',
                'brand' => $json['brand'] ?? null,
                'model_name' => $json['modelName'] ?? null,
            ],
        ];

        if ($existing) {
            $existing->fill($values)->save();
            if ($modelVehicleIds !== []) {
                $existing->modelVehicles()->sync($modelVehicleIds);
            }

            return [$existing->refresh(), 'updated', $resolveNote];
        }

        $created = ObdVehicleProfile::create($values);
        if ($modelVehicleIds !== []) {
            $created->modelVehicles()->sync($modelVehicleIds);
        }

        return [$created->refresh(), 'created', $resolveNote];
    }

    /**
     * @return array{0: list<int>, 1: ?string} id model katalog — BISA lebih
     * dari satu: nama multi-model ("Atto 2 / Atto 3 / Dolphin") menghubungkan
     * SEMUA model katalog yang exact-match per bagian (many-to-many, keputusan
     * owner 2026-11-22). Substring TIDAK pernah dipakai — insiden 2026-11-22
     * (392 profil → Seres 3) berasal dari substring dgn nama model pendek.
     */
    private function resolveModelVehicleIds(array $json): array
    {
        if ($explicit = $this->option('model')) {
            [$id] = $this->findModelByName((string) $explicit, true);

            return [$id === null ? [] : [$id], null];
        }

        if ($this->option('no-resolve')) {
            return [[], null];
        }

        if (! empty($json['modelVehicleId']) && is_numeric($json['modelVehicleId'])) {
            return [[(int) $json['modelVehicleId']], null];
        }

        $brand = trim((string) ($json['brand'] ?? ''));
        $modelName = trim((string) ($json['modelName'] ?? ''));
        if ($brand === '' || $modelName === '' || str_starts_with($modelName, 'Katalog CS ')) {
            return [[], null]; // profil generik / tanpa identitas → mapping admin.
        }

        $parts = str_contains($modelName, ' / ') ? explode(' / ', $modelName) : [$modelName];

        $ids = [];
        $notes = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$id, $reason] = $this->findModelByName($brand . ' ' . $part, false);
            if ($id !== null) {
                $ids[] = $id;
            } elseif ($reason !== null && count($notes) < 3) {
                $notes[] = "\"{$part}\" tidak exact-match katalog";
            }
        }

        $ids = array_values(array_unique($ids));

        return [$ids, $notes === [] ? null : 'sebagian tidak terhubung: ' . implode('; ', $notes)];
    }

    /**
     * Pencocokan STRICT: kesamaan penuh (case-insensitive, whitespace
     * dinormalisasi, ornamen katalog dibuang) antara "Brand Model" katalog vs
     * "Brand Model" profil. TANPA substring dua arah — nama model pendek
     * ("3", "EV") pernah mencocokkan hampir semua nama profil via str_contains
     * (insiden 2026-11-22). $throwOnAmbiguous=true untuk --model eksplisit:
     * lebih baik gagal jelas daripada memilih salah satu secara acak.
     *
     * @return array{0: ?int, 1: ?string} id model + alasan bila gagal terhubung.
     */
    private function findModelByName(string $name, bool $throwOnAmbiguous): array
    {
        $needle = $this->normalizeModelName($name);

        $models = ModelVehicle::query()->with('brandVehicle')->get();
        $matches = $models->filter(
            fn (ModelVehicle $m) => $this->normalizeModelName(
                trim(($m->brandVehicle?->name ?? '') . ' ' . $m->name),
            ) === $needle,
        );

        // Nama tanpa brand (mis. "IONIQ 5" tanpa "Hyundai") — bandingkan ke
        // nama model saja, tetap exact.
        if ($matches->isEmpty() && str_contains($needle, ' ') === false) {
            $matches = $models->filter(
                fn (ModelVehicle $m) => $this->normalizeModelName((string) $m->name) === $needle,
            );
        }

        if ($matches->isEmpty()) {
            if ($throwOnAmbiguous) {
                throw new RuntimeException("--model \"$name\" tidak ketemu di katalog");
            }

            return [null, "tidak terhubung: \"$name\" tidak exact-match katalog (cek ejaan Brand/Model di Model Vehicles, atau pakai --model=)"];
        }

        if ($matches->count() > 1) {
            $names = $matches->take(5)
                ->map(fn (ModelVehicle $m) => trim(($m->brandVehicle?->name ?? '').' '.$m->name).'(id '.$m->id.')')
                ->implode(', ');
            if ($throwOnAmbiguous) {
                throw new RuntimeException("--model \"$name\" ambigu ({$matches->count()} cocok: $names)");
            }

            return [null, "tidak terhubung: \"$name\" ambigu — {$matches->count()} model katalog cocok ($names). Rapikan duplikat katalog atau pakai --model= dengan nama unik"];
        }

        return [$matches->first()->id, null];
    }

    /**
     * Normalisasi nama model: case-insensitive, whitespace rapi, dan buang
     * ornamen katalog Car Scanner — "(2022-2025)", "(2023-n.d.)", "[RU]",
     * "[EN]", "var.1", rentang tahun di luar kurung — sehingga
     * "Mustang Mach-E (2023-)" ≡ "Mustang Mach-E" dan "IONIQ 5 (2022-2025)"
     * ≡ "IONIQ 5". Hanya pembanding EQUALITY — bukan substring.
     */
    private function normalizeModelName(string $value): string
    {
        $v = preg_replace('/\([^)]*\)|\[[^\]]*\]/', ' ', $value) ?? $value;
        $v = preg_replace('/\b(?:19|20)\d{2}\s*-\s*(?:(?:19|20)\d{2}|n\.d\.|present)?\b/i', ' ', $v) ?? $v;
        $v = preg_replace('/\bvar\.\s*\d+/i', ' ', $v) ?? $v;
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return mb_strtolower(trim($v, " \t\r\n\0\x0B-,.:;"));
    }
}
