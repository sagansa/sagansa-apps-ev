<?php

namespace App\Support;

/**
 * Koordinat encoder/decoder v1 — XOR keystream + Base64.
 *
 * Skema:
 *   payload  = "{lat:.8f},{lng:.8f}"
 *   keystream = SHA-256("v1|" + SECRET + "|" + id + "|" + counter) → loop sampai cukup
 *   encoded  = Base64(payload XOR keystream)
 *
 * `id` = id publik (external_id ?? id) yang dimiliki client → dekode deterministik.
 * Versi hash ("v1") di dalam digest → rotasi kunci = bump versi.
 */
class CoordinateCodec
{
    private string $secret;
    private string $version;

    public function __construct(?string $secret = null, ?string $version = null)
    {
        $this->secret = $secret ?? config('spklu.coord_secret', '');
        $this->version = $version ?? config('spklu.coord_codec_version', 'v1');
    }

    /**
     * Encode koordinat → Base64 string.
     *
     * @param  int|string  $id  ID publik stasiun.
     */
    public function encode(int|string $id, float $lat, float $lng): string
    {
        $payload = sprintf('%.8f,%.8f', $lat, $lng);
        $keystream = $this->buildKeystream((string) $id, strlen($payload));

        $xored = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $xored .= $payload[$i] ^ $keystream[$i];
        }

        return base64_encode($xored);
    }

    /**
     * Decode Base64 string → [lat, lng].
     *
     * @return array{lat: float, lng: float}|null  Null bila gagal decode.
     */
    public function decode(int|string $id, string $encoded): ?array
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }

        $keystream = $this->buildKeystream((string) $id, strlen($raw));

        $payload = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $payload .= $raw[$i] ^ $keystream[$i];
        }

        $parts = explode(',', $payload);
        if (count($parts) !== 2) {
            return null;
        }

        $lat = (float) $parts[0];
        $lng = (float) $parts[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Bangun keystream dari SHA-256 loop.
     */
    private function buildKeystream(string $id, int $length): string
    {
        $stream = '';
        $counter = 0;

        while (strlen($stream) < $length) {
            $input = $this->version . '|' . $this->secret . '|' . $id . '|' . $counter;
            $stream .= hash('sha256', $input, true);
            $counter++;
        }

        return substr($stream, 0, $length);
    }

    /**
     * Cek apakah codec aktif (secret terkonfigurasi).
     */
    public function isActive(): bool
    {
        return $this->secret !== '' && config('spklu.coord_codec_enforce', false);
    }
}
