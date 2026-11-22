<?php

namespace App\Filament\Resources\Panel\ModelVehicleResource\RelationManagers;

use App\Filament\Forms\ImageFileUpload;
use Filament\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TypeVehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'typeVehicles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Varian & Tipe Kendaraan';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Grid::make(['default' => 1, 'md' => 2])->schema([
                ImageFileUpload::make('image')
                    ->directory('images/type')
                    ->label('Foto Type'),

                TextInput::make('name')
                    ->required()
                    ->string()
                    ->label('Nama Varian / Type')
                    ->placeholder('cth. Standard Range, Long Range, Performance AWD'),

                TextInput::make('battery_capacity')
                    ->nullable()
                    ->numeric()
                    ->suffix('kWh')
                    ->inputMode('decimal')
                    ->label('Kapasitas Baterai'),

                Select::make('type_charger')
                    ->required()
                    ->multiple()
                    ->searchable()
                    ->options([
                        '1' => 'CCS2 (DC Fast Charging)',
                        '2' => 'Chademo (DC Fast Charging)',
                        '3' => 'DC GBT (Chinese DC Standard)',
                        '4' => 'Type 2 (AC Standard Mennekes)',
                        '5' => 'AC GBT (Chinese AC Standard)',
                    ])
                    ->label('Tipe Charger'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Foto')
                    ->visibility('public'),

                TextColumn::make('name')
                    ->label('Nama Type')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('battery_capacity')
                    ->label('Kapasitas Baterai')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' kWh')
                    ->sortable(),

                TextColumn::make('type_charger')
                    ->label('Tipe Charger')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state) {
                        $options = [
                            '1' => 'CCS2',
                            '2' => 'Chademo',
                            '3' => 'DC GBT',
                            '4' => 'Type 2',
                            '5' => 'AC GBT',
                        ];
                        if (is_array($state)) {
                            return implode(', ', array_map(fn ($item) => $options[$item] ?? $item, $state));
                        }
                        $values = array_filter(array_map('trim', explode(',', (string) $state)));
                        return implode(', ', array_map(fn ($item) => $options[$item] ?? $item, $values));
                    }),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
