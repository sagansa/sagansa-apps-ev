<?php

namespace App\Services;

use App\Models\ObdVehicle;
use App\Models\Vehicle;
use App\Support\VehicleNameNormalizer;
use Closure;
use Illuminate\Support\Collection;

/**
 * Rapikan registry kendaraan OBD2 per user (2026-11-24 — feedback owner: tiap
 * kendaraan tampil dua kali di pemilih Cockpit, dan yang dihapus muncul lagi).
 *
 * Invarian: SATU kendaraan garasi = maksimal satu baris `obd_vehicles`. Baris
 * ganda lahir dari sinkronisasi lama yang mengenali entri HANYA lewat id —
 * apa pun yang id-nya tak dikenal server di-create sebagai baris baru.
 *
 * Yang dilakukan [dedupe]:
 *  1. entri lama TANPA garasi (hasil create manual jadul) ditautkan ke kendaraan
 *     garasi user yang brand+model-nya cocok — hanya bila TEPAT SATU yang cocok;
 *  2. garasi yang kini punya lebih dari satu baris digabung;
 *  3. entri lama yang identik (panggilan + brand + model sama) digabung.
 * Entri lama yang tak cocok dengan garasi mana pun TIDAK dihapus: kendaraan
 * tanpa profil katalog tetap bisa scan & mengirim laporan (keputusan owner).
 *
 * Yang bertahan = paling terakhir dipakai (`last_used_at`, lalu `updated_at`);
 * bila garasi itu sudah punya baris, baris garasi itulah yang bertahan. Data
 * baris yang dihapus (metrik, discovery, usage, kapasitas) dibawa ke yang
 * bertahan bila belum ada. dry-run menjalankan alur yang SAMA di dalam
 * transaksi lalu mengembalikannya — rencana selalu sama dengan hasil.
 */
class ObdVehicleDedupeService
{
    /** Preferensi/JSON yang dibawa dari baris yang dihapus bila penampungnya masih kosong. */
    private const CARRIED = ['usable_kwh', 'efficiency_km_per_kwh', 'metric_config', 'discovered', 'usage'];

    /** Dedupe penuh: tautkan entri lama ke garasi, lalu gabungkan semua ganda. */
    public function dedupe(?int $userId = null, bool $dryRun = false): ObdVehicleDedupeReport
    {
        return $this->withinTransaction(
            $dryRun,
            fn () => $this->run($userId, withStandalone: true),
        );
    }

    /** Hanya gabungkan ganda (user, garage_vehicle_id) — prasyarat unique index (dipanggil migrasi). */
    public function collapseGarageDuplicates(bool $dryRun = false): ObdVehicleDedupeReport
    {
        return $this->withinTransaction(
            $dryRun,
            fn () => $this->run(null, withStandalone: false),
        );
    }

    private function run(?int $userId, bool $withStandalone): ObdVehicleDedupeReport
    {
        $linked = [];
        $merged = [];

        $userIds = ObdVehicle::query()
            ->whereNotNull('user_id')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        foreach ($userIds as $ownerId) {
            // lockForUpdate: tulisan aplikasi yang bersamaan menunggu kelompok
            // user ini selesai dirapikan (no-op di SQLite).
            $rows = ObdVehicle::query()
                ->where('user_id', $ownerId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($withStandalone) {
                $rows = $this->linkStandalone($ownerId, $rows, $linked, $merged);
            }
            $rows = $this->collapse(
                $rows,
                fn (ObdVehicle $r) => $r->garage_vehicle_id === null ? null : 'garage:'.$r->garage_vehicle_id,
                $merged,
            );
            if ($withStandalone) {
                $rows = $this->collapse(
                    $rows,
                    fn (ObdVehicle $r) => $r->garage_vehicle_id !== null
                        ? null
                        : 'lama:'.self::norm($r->nickname).'|'.self::norm($r->brand_name).'|'.self::norm($r->model_name),
                    $merged,
                );
            }
        }

        return new ObdVehicleDedupeReport($linked, $merged);
    }

    /**
     * (1) Tautkan entri lama tanpa garasi ke kendaraan garasi yang tepat satu
     * cocok. Garasi yang sudah punya baris → entri lama digabung ke baris itu.
     */
    private function linkStandalone(int|string $ownerId, Collection $rows, array &$linked, array &$merged): Collection
    {
        $standalone = $rows->filter(fn (ObdVehicle $r) => $r->garage_vehicle_id === null);
        if ($standalone->isEmpty()) {
            return $rows;
        }

        $garages = Vehicle::query()->where('user_id', $ownerId)
            ->with('brandVehicle', 'modelVehicle')
            ->get();

        $byGarage = [];
        foreach ($standalone as $row) {
            $matches = $garages->filter(fn (Vehicle $garage) => $this->isSameVehicle($garage, $row));
            if ($matches->count() === 1) {
                $byGarage[$matches->first()->id][] = $row;
            }
        }

        foreach ($byGarage as $garageId => $orphans) {
            $garage = $garages->firstWhere('id', $garageId);
            $alreadyLinked = $rows->filter(fn (ObdVehicle $r) => $r->garage_vehicle_id === $garageId);
            $keeper = $alreadyLinked->isNotEmpty()
                ? $this->pick($alreadyLinked)
                : $this->pick(collect($orphans));

            if ($keeper->garage_vehicle_id === null) {
                $keeper->garage_vehicle_id = $garageId;
                // Profil kini di-resolve dari katalog lewat model kendaraan garasi.
                if ($garage->model_vehicle_id !== null) {
                    $keeper->model_vehicle_id = $garage->model_vehicle_id;
                }
                $keeper->save();
                $linked[] = ['user_id' => $ownerId, 'row_id' => $keeper->id, 'garage_vehicle_id' => $garageId];
            }

            $losers = $alreadyLinked
                ->reject(fn (ObdVehicle $r) => $r->id === $keeper->id)
                ->merge(collect($orphans)->reject(fn (ObdVehicle $r) => $r->id === $keeper->id));
            $this->mergeInto($keeper, $losers, $merged);

            $removedIds = $losers->pluck('id')->all();
            $rows = $rows->reject(fn (ObdVehicle $r) => in_array($r->id, $removedIds, true));
        }

        return $rows;
    }

    /** Gabungkan tiap kelompok ber-key sama (null = tidak ikut) menjadi satu baris. */
    private function collapse(Collection $rows, Closure $groupKeyOf, array &$merged): Collection
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $groupKeyOf($row);
            if ($key !== null) {
                $groups[$key][] = $row;
            }
        }

        $removedIds = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $keeper = $this->pick(collect($group));
            $losers = collect($group)->reject(fn (ObdVehicle $r) => $r->id === $keeper->id);
            $this->mergeInto($keeper, $losers, $merged);
            array_push($removedIds, ...$losers->pluck('id')->all());
        }

        return $rows->reject(fn (ObdVehicle $r) => in_array($r->id, $removedIds, true));
    }

    /** Hapus `$losers`; data yang belum ada di `$keeper` dibawa ikut. */
    private function mergeInto(ObdVehicle $keeper, Collection $losers, array &$merged): void
    {
        if ($losers->isEmpty()) {
            return;
        }

        foreach ($losers as $loser) {
            foreach (self::CARRIED as $field) {
                if ($this->isEmpty($keeper->getAttribute($field)) && ! $this->isEmpty($loser->getAttribute($field))) {
                    $keeper->setAttribute($field, $loser->getAttribute($field));
                }
            }
            $keeper->last_used_at = max((int) $keeper->last_used_at, (int) $loser->last_used_at) ?: null;
            $loser->delete();
        }
        $keeper->save();

        $merged[] = [
            'user_id' => $keeper->user_id,
            'keeper_id' => $keeper->id,
            'removed_ids' => $losers->pluck('id')->values()->all(),
        ];
    }

    /** Paling terakhir dipakai → paling baru diubah → paling lama dibuat (id stabil). */
    private function pick(Collection $group): ObdVehicle
    {
        return $group->sort(function (ObdVehicle $a, ObdVehicle $b) {
            return [self::lastUsedAt($b), self::timestamp($b->updated_at), -self::timestamp($b->created_at)]
                <=> [self::lastUsedAt($a), self::timestamp($a->updated_at), -self::timestamp($a->created_at)];
        })->first();
    }

    /** Brand sama DAN model sama (huruf besar/kecil & token brand diabaikan). */
    private function isSameVehicle(Vehicle $garage, ObdVehicle $row): bool
    {
        $brand = self::norm($row->brand_name);
        $model = self::norm($row->model_name);
        $garageBrand = self::norm($garage->brandVehicle?->name);
        $garageModel = self::norm($garage->modelVehicle?->name);

        if ($brand === '' || $model === '' || $garageBrand === '' || $garageModel === '' || $brand !== $garageBrand) {
            return false;
        }

        return $model === $garageModel
            || VehicleNameNormalizer::stripBrandTokens($garageModel, $garageBrand)
                === VehicleNameNormalizer::stripBrandTokens($model, $garageBrand);
    }

    private function withinTransaction(bool $dryRun, Closure $work): ObdVehicleDedupeReport
    {
        $connection = (new ObdVehicle)->getConnection();
        $connection->beginTransaction();

        try {
            $report = $work();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        // dry-run: seluruh perubahan dikembalikan — alur yang sama, tanpa efek.
        if ($dryRun) {
            $connection->rollBack();

            return $report->asDryRun();
        }
        $connection->commit();

        return $report;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function lastUsedAt(ObdVehicle $row): int
    {
        return (int) ($row->last_used_at ?? 0);
    }

    private static function timestamp(mixed $at): int
    {
        return $at?->getTimestamp() ?? 0;
    }

    private static function norm(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $value))));
    }
}
