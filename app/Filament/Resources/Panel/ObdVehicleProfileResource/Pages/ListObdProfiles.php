<?php

namespace App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages;

use App\Filament\Resources\Panel\ObdVehicleProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListObdProfiles extends ListRecords
{
    protected static string $resource = ObdVehicleProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
