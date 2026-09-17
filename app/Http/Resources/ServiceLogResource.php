<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ServiceLogResource extends JsonResource
{
    /**
     * Transform the resource into an array — kontrak eksplisit-array
     * (preseden StateOfHealthResource): tipe di-cast, tanggal ISO date-string
     * (kontrak mobile "YYYY-MM-DD").
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', function () {
                return [
                    'id' => $this->vehicle?->id,
                    'license_plate' => $this->vehicle?->license_plate,
                ];
            }),
            'date' => $this->date ? Carbon::parse($this->date)->toDateString() : null,
            'odometer_km' => isset($this->odometer_km) ? (int) $this->odometer_km : null,
            'service_type' => $this->service_type,
            'workshop' => $this->workshop,
            'cost_rp' => isset($this->cost_rp) ? (int) $this->cost_rp : null,
            'notes' => $this->notes,
            'interval_months' => isset($this->interval_months) ? (int) $this->interval_months : null,
            'interval_km' => isset($this->interval_km) ? (int) $this->interval_km : null,
            'next_due_date' => $this->next_due_date ? Carbon::parse($this->next_due_date)->toDateString() : null,
            'next_due_km' => isset($this->next_due_km) ? (int) $this->next_due_km : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
