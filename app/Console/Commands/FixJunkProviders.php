<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membereskan provider sampah hasil import lama: nama lokasi yang dijadikan
 * provider ("Mall", "Kantor", "Hotel", "Rumah", "Resto Ayam ...", dst.).
 *
 * Lokasi yang masih menunjuk provider sampah dipindahkan ke provider yang
 * benar (dicocokkan dari nama lokasi; default PLN Mobile), lalu provider
 * sampah dinonaktifkan (status=2) — tidak dihapus, agar bisa ditinjau.
 * Dry-run default; --apply untuk mengeksekusi.
 */
class FixJunkProviders extends Command
{
    /**
     * Pola nama provider sah (normalized, contains-match). Provider di luar
     * pola ini dianggap sampah. Tambah pola baru di sini bila ada brand mitra.
     */
    private const POLA_PROVIDER_SAH = [
        'pln', 'voltron', 'starvo', 'wuling', 'hyundai', 'toyota', 'lexus',
        'astra', 'porsche', 'pertamina', 'shell', 'bluecharge', 'charge+',
        'chargepoint', 'charge point', 'terra', 'hvt', 'casion', 'dayagreen',
        'daya green', 'parking+', 'parking plus', 'igreen', 'stroom',
        'rhecharge', 'rhocharge', 'cazgo',
    ];

    /**
     * Kata kunci pada nama lokasi → provider tujuan. Dicocokkan berurutan
     * dari yang terpanjang. Bila provider tujuan tidak ada di tabel providers,
     * dipakai fallback PLN (tanpa membuat baris baru).
     */
    private const ALIAS_TUJUAN = [
        'BLUECHARGE' => 'BlueCharge',
        'RHECHARGE' => 'RheCharge',
        'RHOCHARGE' => 'RheCharge',
        'PARKING+' => 'Parking+',
        'DAYAGREEN' => 'Dayagreen',
        'TERRA' => 'Terra Charge',
        'TOYOTA' => 'Toyota Lexus',
        'LEXUS' => 'Toyota Lexus',
        'VOLTRON' => 'Voltron',
        'CHARGE+' => 'Charge+',
        'HYUNDAI' => 'Hyundai',
        'PERTAMINA' => 'Pertamina',
        'STARVO' => 'Starvo',
        'STROOM' => 'Stroom',
        'SHELL' => 'Shell Recharge',
        'IGREEN' => 'iGreen+',
        'CAZGO' => 'Cazgo',
        'WULING' => 'Wuling',
        'CASION' => 'Casion',
        'HVT' => 'HVT Charging',
    ];

    /** tabel lokasi bermember provider_id → kolom nama lokasinya */
    private const TABEL_LOKASI = [
        'spklu_locations' => 'nama_lokasi',
        'pln_charger_locations' => 'name',
        'charging_stations' => 'nama_lokasi',
        'charger_locations' => 'name',
    ];

    protected $signature = 'spklu:fix-junk-providers
        {--apply : benar-benar menulis perubahan (tanpa ini hanya laporan dry-run)}';

    protected $description = 'Pindahkan lokasi dari provider sampah ke provider yang benar, lalu nonaktifkan provider sampah (dry-run default).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'MODE: APPLY' : 'MODE: DRY-RUN (tambahkan --apply untuk mengeksekusi)');

        $providers = DB::table('providers')->select(['id', 'name', 'status'])->get();
        [$sah, $junk] = $providers->partition(fn ($p) => $this->providerSah($p->name));

        if ($junk->isEmpty()) {
            $this->info('✓ Tidak ada provider sampah.');

            return 0;
        }

        $this->info("Provider sampah terdeteksi ({$junk->count()}):");
        foreach ($junk->sortBy('name') as $p) {
            $this->line("  - {$p->name}".($p->status == 2 ? ' (sudah nonaktif)' : ''));
        }

        $sahByNorm = $sah->keyBy(fn ($p) => $this->norm($p->name));
        $plnId = $sahByNorm->get($this->norm('PLN Mobile'))?->id
            ?? $sah->first(fn ($p) => str_contains($this->norm($p->name), 'pln'))?->id;

        if ($plnId === null) {
            $this->error('Provider PLN tidak ditemukan di tabel providers — isi dulu provider "PLN Mobile".');

            return 1;
        }

        $junkIds = $junk->pluck('id')->all();
        $junkById = $junk->keyBy('id');
        $ringkasan = []; // [provider sampah][tujuan] => jumlah

        DB::transaction(function () use ($apply, $junkIds, $junkById, $sahByNorm, $plnId, &$ringkasan) {
            // Normalisasi nilai status legacy (0 dari command versi lama)
            // ke konvensi app: 1 = aktif, 2 = nonaktif.
            if ($apply) {
                DB::table('providers')->whereNotIn('status', [1, 2])->update(['status' => 2]);
            }

            foreach (self::TABEL_LOKASI as $tabel => $kolomNama) {
                if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, $kolomNama)) {
                    continue;
                }

                $rows = DB::table($tabel)
                    ->whereIn('provider_id', $junkIds)
                    ->select(['id', $kolomNama, 'provider_id'])
                    ->get();

                foreach ($rows as $row) {
                    $tujuanId = $this->resolveTujuan((string) $row->{$kolomNama}, $sahByNorm, $plnId);
                    $namaSampah = $junkById[$row->provider_id]->name ?? '?';
                    $namaTujuan = $sahByNorm->first(fn ($p) => $p->id === $tujuanId)?->name ?? '?';

                    $ringkasan[$namaSampah][$namaTujuan] = ($ringkasan[$namaSampah][$namaTujuan] ?? 0) + 1;

                    if ($apply) {
                        DB::table($tabel)->where('id', $row->id)->update(['provider_id' => $tujuanId]);
                    }
                }
            }

            if (Schema::hasTable('spklu_scrape_raw')) {
                if ($apply) {
                    DB::table('spklu_scrape_raw')->whereIn('guessed_provider_id', $junkIds)
                        ->update(['guessed_provider_id' => null]);
                }
            }

            if ($apply) {
                DB::table('providers')->whereIn('id', $junkIds)->update(['status' => 2]);
            }
        });

        if ($ringkasan === []) {
            $this->info('✓ Tidak ada lokasi yang memakai provider sampah.');
        } else {
            $baris = [];
            foreach ($ringkasan as $sampah => $tujuanList) {
                foreach ($tujuanList as $tujuan => $n) {
                    $baris[] = [$sampah, $tujuan, $n];
                }
            }
            $this->table(['Provider Sampah', 'Dipindah Ke', 'Jumlah Lokasi'], $baris);
        }

        $this->info($apply
            ? '✓ Selesai — provider sampah dinonaktifkan (status=2).'
            : 'Dry-run selesai. Jalankan ulang dengan --apply untuk mengeksekusi.');

        return 0;
    }

    private function providerSah(string $name): bool
    {
        $n = $this->norm($name);

        // Nama bergaya nama lokasi — mis. "(iGreen +) Jtown, Jatinegara" —
        // selalu sampah walau mengandung kata brand (igreen, dsb.).
        if (str_contains($n, '(') || str_contains($n, ',')) {
            return false;
        }

        foreach (self::POLA_PROVIDER_SAH as $pola) {
            if ($n !== '' && str_contains($n, $pola)) {
                return true;
            }
        }

        return false;
    }

    private function resolveTujuan(string $namaLokasi, $sahByNorm, string $plnId): string
    {
        $n = mb_strtoupper(trim($namaLokasi), 'UTF-8');

        $alias = self::ALIAS_TUJUAN;
        uksort($alias, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($alias as $kunci => $tujuan) {
            if ($n !== '' && str_contains($n, $kunci)) {
                return $sahByNorm->get($this->norm($tujuan))?->id ?? $plnId;
            }
        }

        return $plnId;
    }

    private function norm(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 'UTF-8');
    }
}
