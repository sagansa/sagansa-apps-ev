<?php

namespace App\Filament\Resources\Panel\SalesImportResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StatsRelationManager extends RelationManager
{
    protected static string $relationship = 'stats';

    protected static ?string $recordTitleAttribute = 'raw_model';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('raw_brand')->label('Brand (raw)')->searchable(),
                Tables\Columns\TextColumn::make('raw_model')->label('Model (raw)')->searchable()->limit(32),
                Tables\Columns\TextColumn::make('modelVehicle.name')->label('Match katalog')->limit(24)->placeholder('—'),
                Tables\Columns\TextColumn::make('segment')->label('Segment')->placeholder('—'),
                Tables\Columns\TextColumn::make('powertrain')->label('Powertrain')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'BEV' => 'success',
                        'PHEV', 'HEV' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('month')->label('Bulan')
                    ->formatStateUsing(fn ($state) => $state === null ? 'TAHUNAN' : str_pad((string) $state, 2, '0', STR_PAD_LEFT)),
                Tables\Columns\TextColumn::make('units')->label('Unit')->numeric()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('powertrain')->options([
                    'BEV' => 'BEV', 'PHEV' => 'PHEV', 'HEV' => 'HEV',
                    'G' => 'G', 'D' => 'D', 'CNG' => 'CNG', 'FCEV' => 'FCEV',
                    'ICE' => 'ICE (legacy)',
                ]),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('units', 'desc')
            ->paginated([25, 50]);
    }
}
