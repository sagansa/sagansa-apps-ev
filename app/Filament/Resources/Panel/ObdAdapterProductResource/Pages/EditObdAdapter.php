<?php

namespace App\Filament\Resources\Panel\ObdAdapterProductResource\Pages;

use App\Filament\Resources\Panel\ObdAdapterProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditObdAdapter extends EditRecord
{
    protected static string $resource = ObdAdapterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
