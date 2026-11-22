<?php

namespace App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages;

use App\Filament\Resources\Panel\ObdVehicleProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateObdProfile extends CreateRecord
{
    protected static string $resource = ObdVehicleProfileResource::class;

    protected function afterCreate(): void
    {
        // Kolom primary model_vehicle_id diselaraskan ke link pivot pertama
        // (API/mobile lama masih membaca kolom ini).
        $this->getRecord()->refreshPrimaryModel();
    }
}
