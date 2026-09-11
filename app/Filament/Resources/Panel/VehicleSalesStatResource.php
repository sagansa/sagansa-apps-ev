<?php

namespace App\Filament\Resources\Panel;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\VehicleSalesStat;
use Filament\Forms;
use Filament\Tables;
use Filament\Actions;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Panel\VehicleSalesStatResource\Pages;

class VehicleSalesStatResource extends Resource
{
    protected static ?string $model = VehicleSalesStat::class;

    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-chart-bar';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Referensi Kendaraan';
    }

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function getNavigationLabel(): string
    {
        return 'Statistik Penjualan Kendaraan';
    }

    public static function canCreate(): bool
    {
        return false; // data hanya dari import GAIKINDO
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Koreksi')->schema([
                Select::make('powertrain')
                    ->label('Powertrain')
                    ->options(['BEV' => 'BEV', 'PHEV' => 'PHEV', 'HEV' => 'HEV', 'G' => 'G', 'D' => 'D', 'CNG' => 'CNG', 'FCEV' => 'FCEV', 'ICE' => 'ICE (legacy)'])
                    ->required(),

                Select::make('brand_vehicle_id')
                    ->label('Brand katalog')
                    ->options(fn () => BrandVehicle::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Select::make('model_vehicle_id')
                    ->label('Model katalog')
                    ->options(function (Forms\Get $get) {
                        $brandId = $get('brand_vehicle_id');

                        return ModelVehicle::query()
                            ->when($brandId, fn (Builder $q) => $q->where('brand_vehicle_id', $brandId))
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable(),
            ])->columns(3),

            \Filament\Schemas\Components\Section::make('Data mentah (read-only)')->schema([
                TextInput::make('raw_brand')->disabled(),
                TextInput::make('raw_model')->disabled(),
                TextInput::make('year')->disabled(),
                TextInput::make('month')->disabled(),
                TextInput::make('units')->disabled(),
            ])->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label('Tahun')->sortable(),

                TextColumn::make('raw_brand')->label('Brand (raw)')->searchable(),

                TextColumn::make('raw_model')->label('Model (raw)')->searchable()->limit(30),

                TextColumn::make('modelVehicle.name')->label('Match katalog')->limit(22)->placeholder('—'),

                TextColumn::make('segment')->label('Segment')->placeholder('—'),

                TextColumn::make('powertrain')->label('Powertrain')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'BEV' => 'success',
                        'PHEV', 'HEV' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('month')->label('Bulan')
                    ->formatStateUsing(fn ($state) => $state === null ? 'TAHUNAN' : str_pad((string) $state, 2, '0', STR_PAD_LEFT)),

                TextColumn::make('units')->label('Unit')->numeric()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('year')->label('Tahun')
                    ->options(fn () => VehicleSalesStat::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all()),
                Tables\Filters\SelectFilter::make('powertrain')->label('Powertrain')
                    ->options(['BEV' => 'BEV', 'PHEV' => 'PHEV', 'HEV' => 'HEV', 'G' => 'G', 'D' => 'D', 'CNG' => 'CNG', 'FCEV' => 'FCEV', 'ICE' => 'ICE (legacy)']),
                Tables\Filters\SelectFilter::make('segment')->label('Segment')
                    ->options(fn () => VehicleSalesStat::query()->whereNotNull('segment')->distinct()->pluck('segment', 'segment')->all()),
                Tables\Filters\TernaryFilter::make('matched')->label('Sudah match katalog')
                    ->queries(
                        fn (Builder $query) => $query->whereNotNull('model_vehicle_id'),
                        fn (Builder $query) => $query->whereNull('model_vehicle_id'),
                    ),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    // Bulk koreksi powertrain untuk membersihkan salah klasifikasi.
                    Actions\BulkAction::make('setPowertrain')
                        ->label('Set Powertrain')
                        ->icon('heroicon-o-bolt')
                        ->form([
                            Select::make('powertrain')
                                ->options(['BEV' => 'BEV', 'PHEV' => 'PHEV', 'HEV' => 'HEV', 'G' => 'G', 'D' => 'D', 'CNG' => 'CNG', 'FCEV' => 'FCEV', 'ICE' => 'ICE (legacy)'])
                                ->required(),
                        ])
                        ->action(function (array $data, \Illuminate\Support\Collection $records) {
                            $records->each->update(['powertrain' => $data['powertrain']]);
                        }),
                ]),
            ])
            ->defaultSort('units', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleSalesStats::route('/'),
            'edit' => Pages\EditVehicleSalesStat::route('/{record}/edit'),
        ];
    }
}
