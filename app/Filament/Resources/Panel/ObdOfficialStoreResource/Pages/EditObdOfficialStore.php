<?php

namespace App\Filament\Resources\Panel\ObdOfficialStoreResource\Pages;

use App\Filament\Resources\Panel\ObdOfficialStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditObdOfficialStore extends EditRecord
{
    protected static string $resource = ObdOfficialStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
