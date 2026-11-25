<?php

namespace App\Filament\Resources\Panel\ObdOfficialStoreResource\Pages;

use App\Filament\Resources\Panel\ObdOfficialStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListObdOfficialStores extends ListRecords
{
    protected static string $resource = ObdOfficialStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
