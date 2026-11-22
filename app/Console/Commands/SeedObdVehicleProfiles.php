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
 * kolom "modelVehicleId" di JSON. Profil resmi (non-CS) dicocokkan otomatis
 * via nama exact; profil katalog CS-* TIDAK pernah di-resolve otomatis —
 * mapping-nya tugas admin via Filament (insiden 2026-11-22).
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
                [$profile, $action] = $this->seedFile($file);
                $label = trim(($profile->metadata['brand'] ?? '') . ' ' . ($profile->metadata['model_name'] ?? ''));
                $action === 'created' ? $created++ : $updated++;
                $this->line("  <info>$action</info> {$profile->platform} — {$label} (v{$profile->version}, id={$profile->id})");
            } catch (RuntimeException $e) {
                $this->warn("  <comment>lewati</comment> " . basename($file) . ": {$e->getMessage()}");
            }
        }

        $this->info("Selesai: $created baru, $updated diperbarui.");

        return self::SUCCESS;
    }

    /** @return array{0: ObdVehicleProfile, 1: string} */
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

        $modelVehicleId = $this->resolveModelVehicleId(
            $json,
            str_starts_with((string) $json['platform'], 'CS-'),
        );

        $attributes = [
            'model_vehicle_id' => $modelVehicleId,
            'platform' => (string) $json['platform'],
        ];

        // Kunci upsert: profil katalog (platform CS-<hash8>) unik per profil —
        // hash adalah ID internal Car Scanner yang stabil antar versi, jadi
        // kunci = platform saja agar assignment admin (model_vehicle_id) tidak
        // memecah baris saat re-seed. Profil manual (platform generik mis.
        // E-GMP) tetap komposit platform+model/vin karena platform bisa
        // dipakai beberapa model.
        $isCatalogPlatform = str_starts_with((string) $json['platform'], 'CS-');
        $query = ObdVehicleProfile::query()
            ->where('platform', $attributes['platform']);

        if (! $isCatalogPlatform) {
            $query->where(function ($q) use ($modelVehicleId, $json) {
                if ($modelVehicleId !== null) {
                    $q->where('model_vehicle_id', $modelVehicleId);
                } else {
                    $q->whereNull('model_vehicle_id')
                        ->where('vin_pattern', $json['vinPattern'] ?? null);
                }
            });
        }

        $existing = $query->first();

        // Lindungi assignment admin via Filament: resolve null tidak menimpa
        // model_vehicle_id existing — hanya resolve eksplisit (--model / JSON
        // / name-match) yang menimpa.
        $effectiveModelVehicleId = $modelVehicleId ?? $existing?->model_vehicle_id;

        $values = [
            'model_vehicle_id' => $effectiveModelVehicleId,
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

            return [$existing->refresh(), 'updated'];
        }

        $created = ObdVehicleProfile::create($values);

        return [$created->refresh(), 'created'];
    }

    /** Cari model_vehicle_id dari --model atau isi JSON (id / nama brand+model). */
    private function resolveModelVehicleId(array $json, bool $isCatalog): ?int
    {
        if ($explicit = $this->option('model')) {
            return $this->findModelByName((string) $explicit, true);
        }

        if ($this->option('no-resolve')) {
            return null;
        }

        if (! empty($json['modelVehicleId']) && is_numeric($json['modelVehicleId'])) {
            return (int) $json['modelVehicleId'];
        }

        // Profil katalog (CS-*) TIDAK di-resolve otomatis dari nama. Nama katalog
        // Car Scanner panjang dan sering multi-model ("Atto 2 / Atto 3 / Dolphin /
        // Seal ..."), dan pencocokan nama substring pernah menimpa ratusan baris
        // ke model salah (insiden 2026-11-22: 392 profil → Seres 3 karena nama
        // model "3" cocok substring di mana-mana). Mapping katalog = tugas admin
        // via Filament (design: sumber kebenaran mapping = database).
        if ($isCatalog) {
            return null;
        }

        $name = trim(($json['brand'] ?? '') . ' ' . ($json['modelName'] ?? ''));
        if ($name === '') {
            return null;
        }

        return $this->findModelByName($name, false); // boleh null — universal/unknown.
    }

    /**
     * Pencocokan STRICT: kesamaan penuh (case-insensitive, whitespace
     * dinormalisasi) antara "Brand Model" katalog vs "Brand Model" profil.
     * TANPA substring dua arah — nama model pendek ("3", "EV") pernah mencocokkan
     * hampir semua nama profil via str_contains (insiden 2026-11-22).
     * $throwOnAmbiguous=true untuk --model eksplisit: lebih baik gagal jelas
     * daripada memilih salah satu secara acak.
     */
    private function findModelByName(string $name, bool $throwOnAmbiguous): ?int
    {
        $needle = $this->normalizeName($name);

        $models = ModelVehicle::query()->with('brandVehicle')->get();
        $matches = $models->filter(
            fn (ModelVehicle $m) => $this->normalizeName(
                trim(($m->brandVehicle?->name ?? '') . ' ' . $m->name),
            ) === $needle,
        );

        // Nama tanpa brand (mis. "IONIQ 5" tanpa "Hyundai") — bandingkan ke
        // nama model saja, tetap exact.
        if ($matches->isEmpty() && str_contains($needle, ' ') === false) {
            $matches = $models->filter(
                fn (ModelVehicle $m) => $this->normalizeName((string) $m->name) === $needle,
            );
        }

        if ($matches->isEmpty()) {
            if ($throwOnAmbiguous) {
                throw new RuntimeException("--model \"$name\" tidak ketemu di katalog");
            }

            return null;
        }

        if ($matches->count() > 1) {
            if ($throwOnAmbiguous) {
                $names = $matches->take(5)
                    ->map(fn (ModelVehicle $m) => trim(($m->brandVehicle?->name ?? '').' '.$m->name))
                    ->implode(', ');
                throw new RuntimeException("--model \"$name\" ambigu ({$matches->count()} cocok: $names)");
            }

            return null;
        }

        return $matches->first()->id;
    }

    private function normalizeName(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '');
    }
}
