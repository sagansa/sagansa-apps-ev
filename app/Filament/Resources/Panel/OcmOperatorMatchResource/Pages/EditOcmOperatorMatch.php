<?php

namespace App\Filament\Resources\Panel\OcmOperatorMatchResource\Pages;

use App\Filament\Resources\Panel\OcmOperatorMatchResource;
use Filament\Resources\Pages\EditRecord;

class EditOcmOperatorMatch extends EditRecord
{
    protected static string $resource = OcmOperatorMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
