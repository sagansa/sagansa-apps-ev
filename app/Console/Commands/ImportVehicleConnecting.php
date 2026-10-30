<?php

namespace App\Console\Commands;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\VehicleConnecting;
use App\Services\VehicleSalesMatcher;
use Illuminate\Console\Command;

/**
 * Impor file CONNECTING ke tabel database `vehicle_connectings` — persistensi
 * isi CONNECTING (termasuk teks mentah "BRAND MODEL TYPE") sebagai acuan
 * sumber. Upsert by raw_gabungan (idempoten); resolusi brand/model/type ke
 * katalog case-insensitive — baris yang belum bisa diresolusi tetap disimpan
 * dgn link NULL + dilaporkan (tandanya katalog perlu dilengkapi dulu).
 *
 * Contoh: php artisan vehicle-connecting:import docs/csv/GAIKINDO\ -\ CONNECTING.csv
 */
class ImportVehicleConnecting extends Command
{
    protected $signature = 'vehicle-connecting:import
        {csv : Path file CONNECTING CSV}
        {--prune : Hapus baris tabel yang tidak ada di CSV}';

    protected $description = 'Impor isi CONNECTING ke tabel vehicle_connectings (persistensi master mapping)';

    public function handle(VehicleSalesMatcher $matcher): int
    {
        $csv = (string) $this->argument('csv');

        if (! is_file($csv)) {
            $this->error("File tidak ada: {$csv}");

            return self::FAILURE;
        }

        $handle = fopen($csv, 'r');
        $hdr = array_map(fn ($c) => strtoupper(trim((string) $c)), fgetcsv($handle) ?: []);
        foreach (['BRAND MODEL TYPE', 'BRAND', 'MODEL', 'TYPE', 'POWERTRAIN', 'CATEGORY', 'SIZE'] as $need) {
            if (! in_array($need, $hdr, true)) {
                $this->error("Header wajib memuat kolom: {$need}");
                fclose($handle);

                return self::FAILURE;
            }
        }
        $i = fn (string $n): int => (int) array_search($n, $hdr, true);
        [$iG, $iF, $iB, $iM, $iT, $iP, $iC, $iS] = [
            $i('BRAND MODEL TYPE'), $i('FUEL') ?? 1, $i('BRAND'), $i('MODEL'),
            $i('TYPE'), $i('POWERTRAIN'), $i('CATEGORY'), $i('SIZE'),
        ];

        // Cache katalog utk resolusi cepat — kunci lewat nama KANONIK
        // (alias matcher, mis. MORRIS GARAGE → MG) sama seperti impor laporan.
        $brandsByKey = BrandVehicle::all()->keyBy(
            fn (BrandVehicle $b) => $matcher->normalize($matcher->canonicalBrandName($b->name)),
        );
        $modelsByBrand = ModelVehicle::all()
            ->groupBy('brand_vehicle_id')
            ->map(fn ($group) => $group->keyBy(fn (ModelVehicle $m) => $matcher->normalize($m->name)));

        $saved = 0;
        $unresolved = [];
        $seen = [];
        $pruned = 0;

        while (($r = fgetcsv($handle)) !== false) {
            if (count($r) < 8 && trim(implode('', $r)) === '') {
                continue;
            }

            // Rapatkan whitespace (baris bisa membawa newline artefak Excel).
            $gabungan = trim(preg_replace('/\s+/', ' ', (string) $r[$iG]));
            $brand = trim((string) $r[$iB]);
            $model = trim((string) $r[$iM]);
            $type = trim((string) $r[$iT]);

            if ($gabungan === '' && $brand === '' && $model === '') {
                continue;
            }

            if ($gabungan === '') {
                $gabungan = trim("$brand $model $type");
            }
            if ($gabungan === '') {
                continue;
            }
            // Identitas squash: huruf+angka saja — kebal spasi/kapitalisasi.
            $key = preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($gabungan));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;

            $brandVehicle = $brandsByKey[$matcher->normalize($matcher->canonicalBrandName($brand))] ?? null;
            $modelVehicle = $brandVehicle !== null
                ? ($modelsByBrand[$brandVehicle->id][$matcher->normalize($model)] ?? null)
                : null;
            $typeVehicle = ($modelVehicle !== null && $type !== '')
                ? TypeVehicle::where('model_vehicle_id', $modelVehicle->id)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($type)])->first()
                : null;

            VehicleConnecting::updateOrCreate(
                ['raw_gabungan_key' => $key],
                [
                    'raw_gabungan' => $gabungan,
                    // Nama mentah baris — applyToCatalog() memakai kolom ini
                    // untuk mempromosikan baris belum-ter-link ke katalog
                    // (jalur GUI addToConnecting selalu mengisinya; jalur CLI
                    // kini setara, bukan hanya FK).
                    'brand_name' => $brand !== '' ? $brand : null,
                    'model_name' => $model !== '' ? $model : null,
                    'type_name' => $type !== '' ? $type : null,
                    'fuel' => ($f = trim((string) $r[$iF])) !== '' ? strtoupper($f) : null,
                    'brand_vehicle_id' => $brandVehicle?->id,
                    'model_vehicle_id' => $modelVehicle?->id,
                    'type_vehicle_id' => $typeVehicle?->id,
                    'powertrain' => ($p = strtoupper(trim((string) $r[$iP]))) !== '' ? $p : null,
                    'category' => ($c = trim((string) $r[$iC])) !== '' ? $c : null,
                    'size_class' => ($s = trim((string) $r[$iS])) !== '' ? $s : null,
                ],
            );

            if ($brandVehicle === null || $modelVehicle === null) {
                $unresolved[] = "$brand | $model";
            } else {
                $saved++;
            }
        }
        fclose($handle);

        if ($this->option('prune')) {
            // Bandingkan via kunci squash — konsisten dgn identitas impor.
            $pruned = VehicleConnecting::query()
                ->where(fn ($q) => $q->whereNull('raw_gabungan_key')
                    ->orWhereNotIn('raw_gabungan_key', array_keys($seen)))
                ->delete();
        }

        $this->info('Baris tersimpan: '.($saved + count($unresolved))." (link katalog lengkap: $saved)");
        if ($unresolved !== []) {
            $this->warn('Link katalog belum lengkap: '.count($unresolved));
            $this->line('  contoh: '.implode('; ', array_slice($unresolved, 0, 8)));
        }
        if ($pruned > 0) {
            $this->info("Baris pruned (tidak ada di CSV): $pruned");
        }

        return self::SUCCESS;
    }
}
