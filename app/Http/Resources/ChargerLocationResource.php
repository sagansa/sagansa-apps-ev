<?php

namespace App\Http\Resources;

use App\Support\CoordinateCodec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk ChargerLocation (legacy table) dengan negosiasi encode koordinat.
 *
 * Saat client mengirim header X-Coord-Codec: v1 (atau enforce aktif):
 *   - latitude/longitude DIHAPUS dari JSON
 *   - field `loc` ditambahkan (Base64 encoded)
 * Tanpa header: format lama utuh (latitude/longitude plain).
 */
class ChargerLocationResource extends JsonResource
{
    private static ?CoordinateCodec $codec = null;

    private static function codec(): CoordinateCodec
    {
        if (self::$codec === null) {
            self::$codec = new CoordinateCodec;
        }

        return self::$codec;
    }

    public function toArray(Request $request): array
    {
        $encode = $this->shouldEncode($request);
        $id = (string) ($this->external_id ?? $this->id);

        $data = [
            'id' => $id,
            'name' => $this->name,
            'provider' => $this->when($this->relationLoaded('provider') && $this->provider, fn () => [
                'id' => $this->provider->id,
                'name' => $this->provider->name,
                'logo' => $this->provider->logo,
                'contact' => $this->provider->contact,
                'web' => $this->provider->web,
                'google' => $this->provider->google,
                'ios' => $this->provider->ios,
            ]),
            'provider_id' => $this->provider_id,
            'provider_name' => $this->provider?->name,
            'provider_logo' => $this->provider?->logo ?? null,
            'alamat' => $this->address,
            'provinsi' => $this->province?->name,
            'kabupaten_kota' => $this->city?->name,
            'distance_km' => $this->when(isset($this->distance) && $this->distance !== null, fn () => round((float) $this->distance, 4)),
        ];

        if ($encode) {
            $encoded = self::codec()->encode($id, (float) $this->latitude, (float) $this->longitude);
            $data['loc'] = $encoded;
        } else {
            $data['latitude'] = (float) $this->latitude;
            $data['longitude'] = (float) $this->longitude;
        }

        return $data;
    }

    private function shouldEncode(Request $request): bool
    {
        if (config('spklu.coord_codec_enforce', false)) {
            return true;
        }

        return strtolower(trim((string) $request->header('X-Coord-Codec'))) === 'v1';
    }
}
