<?php

namespace App\Services;

/**
 * Hasil satu proses [ObdVehicleDedupeService] — dipakai perintah
 * `obd:dedupe-vehicles` untuk tabel rencana & pemilihan user yang dicadangkan.
 */
final class ObdVehicleDedupeReport
{
    /**
     * @param  list<array{user_id: int|string|null, row_id: string, garage_vehicle_id: string}>  $linked  entri lama tanpa garasi yang ditautkan ke kendaraan garasi
     * @param  list<array{user_id: int|string|null, keeper_id: string, removed_ids: list<string>}>  $merged  kelompok yang digabung: `removed_ids` dihapus, datanya dibawa ke `keeper_id`
     */
    public function __construct(
        public readonly array $linked = [],
        public readonly array $merged = [],
        public readonly bool $isDryRun = false,
    ) {}

    /** @return list<string> id semua baris yang dihapus (rencana, bila dry-run) */
    public function removedIds(): array
    {
        return $this->merged === []
            ? []
            : array_merge(...array_column($this->merged, 'removed_ids'));
    }

    /** @return list<int|string|null> user yang barisnya tersentuh */
    public function affectedUserIds(): array
    {
        return array_values(array_unique([
            ...array_column($this->merged, 'user_id'),
            ...array_column($this->linked, 'user_id'),
        ]));
    }

    public function isEmpty(): bool
    {
        return $this->linked === [] && $this->merged === [];
    }

    public function asDryRun(): self
    {
        return new self($this->linked, $this->merged, true);
    }
}
