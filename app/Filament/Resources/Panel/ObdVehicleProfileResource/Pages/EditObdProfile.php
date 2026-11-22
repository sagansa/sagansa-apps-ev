<?php

namespace App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages;

use App\Filament\Resources\Panel\ObdVehicleProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditObdProfile extends EditRecord
{
    protected static string $resource = ObdVehicleProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        // Kolom primary model_vehicle_id diselaraskan ke link pivot pertama
        // (API/mobile lama masih membaca kolom ini).
        $this->getRecord()->refreshPrimaryModel();
    }
}
