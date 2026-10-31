<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Poll status real-time konektor ESDM Singgat tiap 10 menit.
// Mengisi esdm_singgat_connector_status (snapshot) + _status_log (history
// perubahan) + esdm_singgat_station_status (agregat per stasiun).
// Catatan: setiap hit ~35s & 5.6MB; rate-limit ESDM 400/window — aman.
Schedule::command('esdm:poll-status')
    ->everyTenMinutes()
    ->withoutOverlapping(15)         // jangan jalankan paralel; kill setelah 15 menit
    ->runInBackground()              // jangan blokir scheduler utama
    ->name('esdm-poll-status')
    ->description('Poll status real-time konektor ESDM Singgat');

// Hydrate tabel kanonik charging_stations dari master data ESDM.
// Re-roll master (nama, tarif, provider, geo) — cukup harian karena master
// ESDM hanya berubah saat import manual (esdm:import-singgat). Berbeda dari
// poll-status yang menangani status real-time (konektor) saja.
Schedule::command('esdm:hydrate-canonical')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->name('esdm-hydrate-canonical')
    ->description('Hydrate charging_stations (kanonik) dari master ESDM — daily');

// Hydrate tabel kanonik charging_stations dari master PLN.
// Re-roll master (nama, provinsi, provider, geo) — cukup harian karena master
// PLN hanya berubah saat import manual (ImportSpkluCsv). Paralel dgn hydrate
// ESDM; kedua source hidup berdampingan di charging_stations, serving memilih
// source via config spklu.serving_source.
Schedule::command('pln:hydrate-canonical')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->name('pln-hydrate-canonical')
    ->description('Hydrate charging_stations (kanonik) dari master PLN — daily');

// Sync POI OpenChargeMap per negara (default: OCM_COUNTRIES env, awalnya ID).
// Port fetch_openchargemap.py: paginasi + backoff 429 + deteksi halaman
// duplikat; incremental modifiedsince via tabel ocm_sync_state. Row hanya
// masuk openchargemap_pois + charging_stations source='ocm' — TIDAK di-serving
// di Indonesia (spklu.serving_source tetap pln/esdm).
Schedule::command('ocm:sync')
    ->dailyAt('04:00')
    ->withoutOverlapping(120)         // dataset besar per negara; jangan paralel
    ->runInBackground()
    ->name('ocm-sync')
    ->description('Sync POI OpenChargeMap per negara (incremental modifiedsince)');

// Match operator OCM → providers (auto-match + auto-provision) lalu hydrate
// canonical source='ocm'. Berjalan setelah ocm:sync supaya POI baru sudah ada.
Schedule::command('ocm:import-operators')
    ->dailyAt('04:20')
    ->withoutOverlapping(60)
    ->name('ocm-import-operators')
    ->description('Entity-resolution operator OCM ke providers');

Schedule::command('ocm:hydrate-canonical')
    ->dailyAt('04:30')
    ->withoutOverlapping(60)
    ->name('ocm-hydrate-canonical')
    ->description('Hydrate charging_stations (kanonik) dari POI OpenChargeMap — daily');

