<?php

namespace App\Support;

/**
 * Helper murni nama kendaraan katalog — SATU sumber untuk matcher (skor
 * strip-prefix brand) dan audit duplikat (ModelVehicleMergeService), agar
 * keduanya tidak pernah berbeda persepsi soal "nama tanpa brand".
 */
final class VehicleNameNormalizer
{
    /** Norm model tanpa token nama brand-nya (mis. "MG S5 EV" → "S5 EV"). */
    public static function stripBrandTokens(string $norm, string $brandNorm): string
    {
        if ($brandNorm === '') {
            return $norm;
        }

        $brandTokens = array_values(array_filter(explode(' ', $brandNorm), fn ($t) => $t !== ''));
        $modelTokens = array_values(array_filter(
            explode(' ', $norm),
            fn ($t) => $t !== '' && ! in_array($t, $brandTokens, true),
        ));

        return trim(implode(' ', $modelTokens));
    }
}
