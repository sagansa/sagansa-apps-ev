<?php

namespace App\Filament\Resources\Panel\OpenchargemapPoiResource\Pages;

use App\Filament\Resources\Panel\OpenchargemapPoiResource;
use Filament\Resources\Pages\ListRecords;

class ListOpenchargemapPois extends ListRecords
{
    protected static string $resource = OpenchargemapPoiResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
