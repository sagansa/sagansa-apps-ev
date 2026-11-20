<?php

namespace App\Filament\Resources\Panel\ObdVehicleResource\Pages;

use App\Filament\Resources\Panel\ObdVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditObdVehicle extends EditRecord
{
    protected static string $resource = ObdVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
