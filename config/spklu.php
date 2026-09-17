<?php

use App\Services\CanonicalStationHydrateService;

/**
 * Konfigurasi serving SPKLU (lapisan kanonik charging_stations).
 *
 * `serving_source` memilih source mana yang di-serving oleh GET /api/v1/spklu
 * (ESDM vs PLN). Data dari source lain tetap dipertahankan di tabel, hanya
 * tidak disajikan. Switch source cukup lewat env — tanpa perubahan kode.
 *
 * Coordinate codec (v1): enkripsi koordinat agar tidak terbaca di network inspect.
 * - coord_secret: kunci rahasia (bump versi = ganti kunci)
 * - coord_codec_version: versi hash di dalam digest
 * - coord_codec_enforce: bila true, SEMUA response ter-encode (setelah app lama punah)
 */
return [
    'serving_source' => env('SPKLU_SERVING_SOURCE', CanonicalStationHydrateService::SOURCE_PLN),

    'coord_secret' => env('SPKLU_COORD_SECRET', ''),
    'coord_codec_version' => env('SPKLU_COORD_CODEC_VERSION', 'v1'),
    'coord_codec_enforce' => env('SPKLU_COORD_CODEC_ENFORCE', false),
];
