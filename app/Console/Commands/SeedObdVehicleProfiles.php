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
 * katalog model_vehicles) atau kolom "modelVehicleId"/"modelVehicleName"
 * di JSON — dicari juga di katalog bila --resolve aktif (default).
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

        $modelVehicleId = $this->resolveModelVehicleId($json);

        $attributes = [
            'model_vehicle_id' => $modelVehicleId,
            'platform' => (string) $json['platform'],
        ];

        // Upsert key: platform + model (atau vin_pattern bila tanpa model).
        $query = ObdVehicleProfile::query()
            ->where('platform', $attributes['platform'])
            ->where(function ($q) use ($modelVehicleId, $json) {
                if ($modelVehicleId !== null) {
                    $q->where('model_vehicle_id', $modelVehicleId);
                } else {
                    $q->whereNull('model_vehicle_id')
                        ->where('vin_pattern', $json['vinPattern'] ?? null);
                }
            });

        $existing = $query->first();

        $values = [
            'model_vehicle_id' => $modelVehicleId,
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
    private function resolveModelVehicleId(array $json): ?int
    {
        if ($explicit = $this->option('model')) {
            $found = $this->findModelByName((string) $explicit);
            if ($found === null) {
                throw new RuntimeException("--model \"$explicit\" tidak ketemu di katalog");
            }

            return $found;
        }

        if ($this->option('no-resolve')) {
            return null;
        }

        if (! empty($json['modelVehicleId']) && is_numeric($json['modelVehicleId'])) {
            return (int) $json['modelVehicleId'];
        }

        $name = trim(($json['brand'] ?? '') . ' ' . ($json['modelName'] ?? ''));
        if ($name === '') {
            return null;
        }

        return $this->findModelByName($name); // boleh null — universal/unknown.
    }

    private function findModelByName(string $name): ?int
    {
        $needle = mb_strtolower(trim($name));

        $model = ModelVehicle::query()
            ->with('brandVehicle')
            ->get()
            ->first(fn (ModelVehicle $m) => str_contains(
                mb_strtolower(trim(($m->brandVehicle?->name ?? '') . ' ' . $m->name)),
                $needle,
            ) || str_contains($needle, mb_strtolower(trim($m->name))));

        return $model?->id;
    }
}
