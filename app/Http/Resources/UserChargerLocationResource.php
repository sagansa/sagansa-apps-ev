<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializer utk lokasi custom/home milik user (endpoint /my/charging-locations).
 * Kontrak eksplisit-array supaya deserialize DTO mobile mudah (id, name,
 * koordinat, provider, region, is_home_charging).
 */
class UserChargerLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'provider_id' => $this->provider_id,
            'provider_name' => $this->whenLoaded('provider', fn () => $this->provider?->name),
            'provider_logo' => $this->whenLoaded('provider', fn () => $this->provider?->logo),
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,
            'province_name' => $this->province_name,
            'city_name' => $this->city_name,
            'is_home_charging' => (int) $this->location_on === 2,
            'status' => $this->status,
            'chargers' => $this->whenLoaded('chargers', function () {
                return $this->chargers->map(fn ($charger) => [
                    'id' => $charger->id,
                    'current_charger_id' => $charger->current_charger_id,
                    'type_charger_id' => $charger->type_charger_id,
                    'power_charger_id' => $charger->power_charger_id,
                    'unit' => $charger->unit,
                    'current_charger' => $charger->currentCharger ? [
                        'id' => $charger->currentCharger->id,
                        'name' => $charger->currentCharger->name,
                    ] : null,
                    'type_charger' => $charger->typeCharger ? [
                        'id' => $charger->typeCharger->id,
                        'name' => $charger->typeCharger->name,
                    ] : null,
                    'power_charger' => $charger->powerCharger ? [
                        'id' => $charger->powerCharger->id,
                        'name' => $charger->powerCharger->name,
                    ] : null,
                ]);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
