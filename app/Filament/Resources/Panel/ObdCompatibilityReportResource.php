<?php

namespace App\Filament\Resources\Panel;

use App\Models\ObdCompatibilityReport;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\Panel\ObdCompatibilityReportResource\Pages;

class ObdCompatibilityReportResource extends Resource
{
    protected static ?string $model = ObdCompatibilityReport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 13;
    protected static ?string $label = 'Compatibility Report';
    protected static ?string $pluralLabel = 'Compatibility Reports';

    // Read-only resource — no create/edit forms

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn ($q) => $q->with('modelVehicle'))
            ->columns([
                TextColumn::make('report_id')
                    ->label('Report ID')
                    ->limit(8)
                    ->copyable(),

                TextColumn::make('modelVehicle.name')
                    ->label('Vehicle')
                    ->searchable(),

                TextColumn::make('platform')
                    ->badge(),

                TextColumn::make('adapter_type')
                    ->badge(fn (string $state) => match ($state) {
                        'wifi' => 'info',
                        'ble' => 'success',
                        'spp' => 'warning',
                    }),

                TextColumn::make('supported_pids')
                    ->label('Supported PIDs')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) . ' PIDs' : '-'),

                TextColumn::make('opt_in')
                    ->label('Opt-in')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Tables\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListObdReports::route('/'),
            'view' => Pages\ViewObdReport::route('/{record}'),
        ];
    }
}
