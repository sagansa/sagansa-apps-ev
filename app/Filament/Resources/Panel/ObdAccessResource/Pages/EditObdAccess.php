<?php

namespace App\Filament\Resources\Panel\ObdAccessResource\Pages;

use App\Filament\Resources\Panel\ObdAccessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditObdAccess extends EditRecord
{
    protected static string $resource = ObdAccessResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
