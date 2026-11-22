<?php

namespace App\Console\Commands;

use App\Models\ObdVehicleProfile;
use Illuminate\Console\Command;

/**
 * Audit + perbaikan massal mapping profil OBD yang salah model akibat bug
 * name-resolve substring di obd:seed-profiles (insiden 2026-11-22: 392 profil
 * katalog → Seres 3 karena nama model "3" cocok substring di mana-mana).
 *
 * Heuristik "salah brand": link pivot ke model katalog (platform CS-*,
 * grade community) yang brand-nya BERBEDA dari brand di metadata profil.
 * Seed resmi (grade official) dan profil non-CS tidak disentuh.
 *
 *   php artisan obd:audit-profile-mapping                 # laporan saja
 *   php artisan obd:audit-profile-mapping --fix           # lepas semua link salah brand
 *   php artisan obd:audit-profile-mapping --fix --model=7 # batasi satu model
 */
class AuditObdProfileMapping extends Command
{
    protected $signature = 'obd:audit-profile-mapping
        {--fix : Lepas link pivot profil katalog yang salah brand (tanpa ini = laporan saja)}
        {--model= : Batasi audit/fix ke satu model_vehicle_id}
        {--min-wrong=1 : Hanya tampilkan model dengan jumlah salah >= nilai ini}';

    protected $description = 'Audit & lepas link pivot profil OBD katalog yang salah model (bug name-resolve substring)';

    public function handle(): int
    {
        $modelId = $this->option('model');

        $profiles = ObdVehicleProfile::query()
            ->whereHas('modelVehicles', fn ($q) => $q->when($modelId, fn ($qq, $id) => $qq->where('model_vehicles.id', $id)))
            ->where('grade', 'community')
            ->where('platform', 'like', 'CS-%')
            ->with('modelVehicles.brandVehicle')
            ->get();

        // (key link => [profile, model]) — per LINK pivot, bukan per profil.
        // Key komposit: belongsToMany TIDAK men-select kolom id pivot secara
        // default ($model->pivot->id = null) — key tabrakan membuat hanya
        // link terakhir yang di-detach.
        $links = [];
        foreach ($profiles as $profile) {
            foreach ($profile->modelVehicles as $model) {
                if ($modelId !== null && (int) $model->id !== (int) $modelId) {
                    continue;
                }
                if (! $this->brandMatches($profile, $model)) {
                    $links[$profile->id . ':' . $model->id] = [$profile, $model];
                }
            }
        }

        if ($links === []) {
            $this->info('Semua link profil katalog konsisten dengan brand-nya. Tidak ada perbaikan.');

            return self::SUCCESS;
        }

        $byModel = collect($links)->groupBy(fn (array $l) => $l[1]->id);
        $rows = [];
        foreach ($profiles->flatMap->modelVehicles->unique('id') as $model) {
            $wrong = $byModel->get($model->id, collect());
            if ($wrong->isEmpty() || $wrong->count() < (int) $this->option('min-wrong')) {
                continue;
            }
            $samples = $wrong->take(3)
                ->map(fn (array $l) => (string) ($l[0]->metadata['model_name'] ?? '?'))
                ->implode(' | ');
            $rows[] = [
                $model->brandVehicle?->name ?? '?',
                $model->name,
                $wrong->count(),
                $samples,
            ];
        }

        if ($rows !== []) {
            $this->table(['Brand', 'Model', 'Link salah brand', 'Contoh Catalog Name'], $rows);
        }
        $this->warn(count($links) . ' link profil katalog salah brand.');

        if (! $this->option('fix')) {
            $this->comment('Jalankan ulang dengan --fix untuk melepas link yang salah brand.');

            return self::SUCCESS;
        }

        foreach ($links as [$profile, $model]) {
            $profile->modelVehicles()->detach([$model->id]);
        }

        // Primary (model_vehicle_id) diselaraskan ke link tersisa.
        $profiles->each(fn (ObdVehicleProfile $p) => $p->refresh());

        $profiles->unique('id')->each(fn (ObdVehicleProfile $p) => $p->refreshPrimaryModel());

        $this->info('Selesai: ' . count($links) . ' link dilepas. Profil yang brand-nya cocok tetap dipertahankan — sisanya dipetakan ulang via Filament (OBD Profiles → Ter-Map = False).');

        return self::SUCCESS;
    }

    /** True bila brand di metadata profil = brand model tujuan (exact, case-insensitive). */
    private function brandMatches(ObdVehicleProfile $p, $model): bool
    {
        $brand = mb_strtolower(trim($model->brandVehicle?->name ?? ''));
        if ($brand === '') {
            return false; // tanpa brand katalog tidak bisa divalidasi → anggap salah
        }

        $metaBrand = mb_strtolower(trim($p->metadata['brand'] ?? ''));
        $name = mb_strtolower((string) ($p->metadata['model_name'] ?? ''));

        return $metaBrand === $brand || str_contains($name, $brand);
    }
}
