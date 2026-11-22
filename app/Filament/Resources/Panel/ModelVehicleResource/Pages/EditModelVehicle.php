<?php

namespace App\Filament\Resources\Panel\ModelVehicleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Panel\ModelVehicleResource;

class EditModelVehicle extends EditRecord
{
    protected static string $resource = ModelVehicleResource::class;

    protected function getHeaderActions(): array
    {
        // Pilih profil OBD langsung dari halaman edit model (definisi sama
        // dengan aksi baris di daftar Model Vehicles).
        return [
            Actions\DeleteAction::make(),
            ModelVehicleResource::pilihProfilObdAction(),
        ];
    }
}
