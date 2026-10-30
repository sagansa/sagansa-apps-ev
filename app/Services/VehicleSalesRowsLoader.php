<?php

namespace App\Services;

/**
 * Dispatcher pembaca baris penjualan GAIKINDO: XLSX (hasil gaikindo-
 * converter, via PhpSpreadsheet) atau CSV (format lama). Deteksi via
 * magic bytes — file upload sementara Livewire TIDAK punya ekstensi,
 * jadi andalkan isi, bukan nama.
 */
class VehicleSalesRowsLoader
{
    public function __construct(
        protected VehicleSalesCsvReader $csv,
        protected VehicleSalesXlsxReader $xlsx,
    ) {
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array{brand: string, type_model: string, reason: string}>}
     */
    public function read(string $filePath, ?int $month): array
    {
        $reader = $this->isSpreadsheet($filePath) ? $this->xlsx : $this->csv;

        return $reader->read($filePath, $month);
    }

    /** XLSX/XLSM = zip (PK..); XLS lama = OLE (D0 CF). */
    protected function isSpreadsheet(string $filePath): bool
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return str_starts_with($magic, "PK\x03\x04")
            || str_starts_with($magic, "\xD0\xCF\x11\xE0");
    }
}
