<?php

namespace App\Filament\Resources\Panel\VehicleConnectingResource\Pages;

use App\Filament\Resources\Panel\VehicleConnectingResource;
use App\Services\VehicleSalesStatsRelinker;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVehicleConnecting extends EditRecord
{
    protected static string $resource = VehicleConnectingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('relinkStats')
                ->label('🔁 Terapkan & Relink stats')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Terapkan master ke stats')
                ->modalDescription('Seluruh baris stats (lintas tahun) yang raw-nya sama dengan kombinasi ini dipindah ke brand/model/type master TERBARU — memperbaiki salah-link tanpa re-upload file. Pastikan perubahan form sudah DISIMPAN dulu.')
                ->action(function (EditRecord $livewire): void {
                    $count = app(VehicleSalesStatsRelinker::class)
                        ->relinkCombination($livewire->getRecord());

                    Notification::make()
                        ->title($count > 0
                            ? "✓ {$count} baris stats di-relink sesuai master"
                            : 'Tidak ada baris stats dengan raw ini (atau master belum ter-link)')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
