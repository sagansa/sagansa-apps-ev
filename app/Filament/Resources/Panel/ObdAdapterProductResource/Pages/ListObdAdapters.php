<?php

namespace App\Filament\Resources\Panel\ObdAdapterProductResource\Pages;

use App\Filament\Resources\Panel\ObdAdapterProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListObdAdapters extends ListRecords
{
    protected static string $resource = ObdAdapterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
