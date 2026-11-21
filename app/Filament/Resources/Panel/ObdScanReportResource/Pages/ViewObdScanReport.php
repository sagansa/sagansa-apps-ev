<?php

namespace App\Filament\Resources\Panel\ObdScanReportResource\Pages;

use App\Filament\Resources\Panel\ObdScanReportResource;
use App\Models\ObdScanReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewObdScanReport extends ViewRecord
{
    protected static string $resource = ObdScanReportResource::class;

    /**
     * Aksi review kurasi: hanya menggeser `review_status` (laporan tetap
     * read-only). Promosi profil tetap manual via ObdVehicleProfileResource —
     * laporan hanya berisi mode/pid/description tanpa formula, tidak cukup
     * untuk melahirkan profil utuh otomatis.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->reviewStatusAction('reviewed', 'Tandai Direview', 'heroicon-o-eye'),
            $this->reviewStatusAction('promoted', 'Tandai Dipromosikan', 'heroicon-o-arrow-up-circle'),
            $this->reviewStatusAction('discarded', 'Buang', 'heroicon-o-trash'),
        ];
    }

    /** Satu aksi status: set `review_status` bila belum berada di nilai itu. */
    private function reviewStatusAction(string $status, string $label, string $icon): Action
    {
        return Action::make("mark_{$status}")
            ->label($label)
            ->icon($icon)
            ->color(match ($status) {
                'reviewed' => 'info',
                'promoted' => 'success',
                default => 'gray',
            })
            ->requiresConfirmation($status === 'discarded')
            ->visible(fn (ObdScanReport $record) => $record->review_status !== $status)
            ->action(function (ObdScanReport $record) use ($status): void {
                $record->update(['review_status' => $status]);
                Notification::make()
                    ->title("Laporan ditandai \"{$status}\".")
                    ->success()
                    ->send();
            });
    }
}
