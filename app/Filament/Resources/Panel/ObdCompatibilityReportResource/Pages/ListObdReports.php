<?php

namespace App\Filament\Resources\Panel\ObdCompatibilityReportResource\Pages;

use App\Filament\Resources\Panel\ObdCompatibilityReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListObdReports extends ListRecords
{
    protected static string $resource = ObdCompatibilityReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
