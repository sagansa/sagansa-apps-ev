<?php

namespace App\Filament\Resources\Panel\ObdVehicleResource\Pages;

use App\Filament\Resources\Panel\ObdVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListObdVehicles extends ListRecords
{
    protected static string $resource = ObdVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
