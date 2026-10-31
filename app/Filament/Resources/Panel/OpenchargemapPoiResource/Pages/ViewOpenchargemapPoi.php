<?php

namespace App\Filament\Resources\Panel\OpenchargemapPoiResource\Pages;

use App\Filament\Resources\Panel\OpenchargemapPoiResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOpenchargemapPoi extends ViewRecord
{
    protected static string $resource = OpenchargemapPoiResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
