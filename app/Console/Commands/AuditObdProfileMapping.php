<?php

namespace App\Console\Commands;

use App\Models\ObdVehicleProfile;
use Illuminate\Console\Command;

/**
 * Audit + perbaikan massal mapping profil OBD yang salah model akibat bug
 * name-resolve substring di obd:seed-profiles (insiden 2026-11-22: 392 profil
 * katalog → Seres 3 karena nama model "3" cocok substring di mana-mana).
 *
 * Heuristik "salah brand": profil katalog (platform CS-*, grade community)
 * yang model_vehicle_id-nya menunjuk model dengan brand BERBEDA dari brand di
 * metadata profil. Seed resmi (grade official) dan profil non-CS tidak
 * disentuh — mapping-nya disengaja.
 *
 *   php artisan obd:audit-profile-mapping                 # laporan saja
 *   php artisan obd:audit-profile-mapping --fix           # reset semua yang salah
 *   php artisan obd:audit-profile-mapping --fix --model=7 # batasi satu model
 */
class AuditObdProfileMapping extends Command
{
    protected $signature = 'obd:audit-profile-mapping
        {--fix : Reset model_vehicle_id profil katalog yang salah brand (tanpa ini = laporan saja)}
        {--model= : Batasi audit/fix ke satu model_vehicle_id}
        {--min-wrong=1 : Hanya tampilkan model dengan jumlah salah >= nilai ini}';

    protected $description = 'Audit & reset mapping profil OBD katalog yang salah model (akibat bug name-resolve substring)';

    public function handle(): int
    {
        $modelId = $this->option('model');

        $profiles = ObdVehicleProfile::query()
            ->whereNotNull('model_vehicle_id')
            ->where('grade', 'community')
            ->where('platform', 'like', 'CS-%')
            ->when($modelId, fn ($q, $id) => $q->where('model_vehicle_id', $id))
            ->with('modelVehicle.brandVehicle')
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('Tidak ada profil katalog ter-mapping — tidak ada yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $wrong = $profiles->filter(fn (ObdVehicleProfile $p) => ! $this->brandMatches($p));

        $byModel = $wrong->groupBy('model_vehicle_id');
        $shown = 0;
        $header = ['Brand', 'Model', 'Ter-mapping', 'Salah brand', 'Contoh Catalog Name'];
        $rows = [];

        foreach ($profiles->groupBy('model_vehicle_id') as $mid => $group) {
            $model = $group->first()->modelVehicle;
            $brandName = $model?->brandVehicle?->name ?? '?';
            $modelName = $model?->name ?? '?';
            $wrongCount = $byModel->get($mid, collect())->count();
            if ($wrongCount < (int) $this->option('min-wrong')) {
                continue;
            }
            $samples = $byModel->get($mid, collect())
                ->take(3)
                ->map(fn (ObdVehicleProfile $p) => (string) ($p->metadata['model_name'] ?? '?'))
                ->implode(' | ');
            $rows[] = [$brandName, $modelName, $group->count(), $wrongCount, $samples];
            $shown++;
        }

        if ($rows === []) {
            $this->info('Semua mapping profil katalog konsisten dengan brand-nya. Tidak ada perbaikan.');

            return self::SUCCESS;
        }

        $this->table($header, $rows);
        $this->warn("{$shown} model memiliki profil katalog salah brand. Total: {$wrong->count()} profil.");

        if (! $this->option('fix')) {
            $this->comment('Jalankan ulang dengan --fix untuk mereset model_vehicle_id profil yang salah brand.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $wrong->each(function (ObdVehicleProfile $p) use (&$fixed) {
            $p->update(['model_vehicle_id' => null]);
            $fixed++;
        });

        $this->info("Selesai: {$fixed} profil di-reset ke belum ter-map. Profil yang brand-nya cocok tetap dipertahankan — sisanya dipetakan ulang via Filament (OBD Profiles → Ter-Map = False).");

        return self::SUCCESS;
    }

    /** True bila brand di metadata profil = brand model tujuan (exact, case-insensitive). */
    private function brandMatches(ObdVehicleProfile $p): bool
    {
        $brand = mb_strtolower(trim($p->modelVehicle?->brandVehicle?->name ?? ''));
        if ($brand === '') {
            return false; // tanpa brand katalog tidak bisa divalidasi → anggap salah
        }

        $metaBrand = mb_strtolower(trim($p->metadata['brand'] ?? ''));
        $name = mb_strtolower((string) ($p->metadata['model_name'] ?? ''));

        return $metaBrand === $brand || str_contains($name, $brand);
    }
}
