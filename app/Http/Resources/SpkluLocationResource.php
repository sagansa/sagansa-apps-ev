<?php

namespace App\Http\Resources;

use App\Support\CoordinateCodec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpkluLocationResource extends JsonResource
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
        $id = (int) ($this->external_id ?? $this->id);

        $data = [
            'id' => $id,
            'provinsi' => $this->provinsi,
            'kabupaten_kota' => $this->kabupaten_kota,
            'nama_lokasi' => $this->nama_lokasi,
            'alamat' => $this->alamat,
            'keterangan' => $this->keterangan,
            'status' => (int) ($this->status ?? 1),
            'toll_category' => $this->toll_category ?? $this->kategori_tol,
            'location_category' => $this->location_category ?? $this->kategori_lokasi,
            'kategori_tol' => $this->kategori_tol ?? $this->toll_category,
            'kategori_lokasi' => $this->kategori_lokasi ?? $this->location_category,
            'type_charge' => $this->type_charge,
            'watt' => $this->watt,
            'total_charger' => (int) $this->total_charger,
            'total_konektor' => (int) $this->total_konektor,
            'availability_level' => $this->availability_level,
            'available_count' => (int) $this->available_count,
            'charging_count' => (int) $this->charging_count,
            'finishing_count' => (int) $this->finishing_count,
            'status_updated_at' => $this->status_updated_at?->setTimezone('Asia/Jakarta')->toDateTimeString(),
            'distance_km' => $this->when(isset($this->distance) && $this->distance !== null, fn () => round((float) $this->distance, 4)),
            'provider_id' => $this->provider_id,
            'provider' => $this->when($this->relationLoaded('provider') && $this->provider, function () {
                return [
                    'id' => $this->provider->id,
                    'name' => $this->provider->name,
                    'logo' => $this->provider->logo,
                    'contact' => $this->provider->contact,
                    'web' => $this->provider->web,
                    'google' => $this->provider->google,
                    'ios' => $this->provider->ios,
                ];
            }),
            'provider_name' => $this->provider?->name,
            'provider_logo' => $this->provider?->logo ?? null,
            'charger_boxes' => SpkluChargerBoxResource::collection($this->whenLoaded('chargerBoxes')),
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
