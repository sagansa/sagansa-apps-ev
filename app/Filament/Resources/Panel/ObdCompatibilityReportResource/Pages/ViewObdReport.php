<?php

namespace App\Filament\Resources\Panel\ObdCompatibilityReportResource\Pages;

use App\Filament\Resources\Panel\ObdCompatibilityReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewObdReport extends ViewRecord
{
    protected static string $resource = ObdCompatibilityReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
