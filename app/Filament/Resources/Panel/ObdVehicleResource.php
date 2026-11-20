<?php

namespace App\Filament\Resources\Panel;

use App\Models\ModelVehicle;
use App\Models\ObdVehicle;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use App\Filament\Resources\Panel\ObdVehicleResource\Pages;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registry kendaraan OBD2 PER-USER (2026-11-20 — pindahan internal storage
 * mobile). Admin bisa MENAMBAHKAN MANUAL bila ada data OBD2 baru: pilih user +
 * model kendaraan, isi kapasitas/efisiensi — metrik PID tetap di-resolve
 * mobile dari katalog `obd_vehicle_profiles` (model_vehicle_id/brand+model,
 * fallback Universal otomatis).
 */
class ObdVehicleResource extends Resource
{
    protected static ?string $model = ObdVehicle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 12;
    protected static ?string $label = 'OBD Vehicle';
    protected static ?string $pluralLabel = 'OBD Vehicles';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Ownership & Vehicle')->schema([
                Grid::make(['default' => 2])->schema([
                    Select::make('user_id')
                        ->label('User')
                        ->options(fn () => User::query()
                            ->orderBy('name')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => ($u->name.' · '.$u->email)]))
                        ->searchable()
                        ->required(),

                    Select::make('model_vehicle_id')
                        ->label('Model Vehicle')
                        ->options(fn () => ModelVehicle::with('brandVehicle')
                            ->get()
                            ->mapWithKeys(fn ($m) => [
                                $m->id => ($m->brandVehicle?->name.' '.$m->name),
                            ]))
                        ->searchable()
                        ->nullable(),

                    TextInput::make('garage_vehicle_id')
                        ->label('Garage Vehicle ID (uuid)')
                        ->maxLength(36),

                    TextInput::make('nickname')
                        ->label('Nickname')
                        ->maxLength(120),

                    Grid::make(['default' => 2])->schema([
                        TextInput::make('brand_name')
                            ->label('Brand (fallback Universal)')
                            ->maxLength(80),

                        TextInput::make('model_name')
                            ->label('Model (fallback Universal)')
                            ->maxLength(120),
                    ]),
                ]),
            ]),

            Section::make('Preferences')->schema([
                Grid::make(['default' => 2])->schema([
                    TextInput::make('platform')
                        ->label('Platform')
                        ->placeholder('E-GMP / BEP / universal')
                        ->maxLength(32),

                    TextInput::make('usable_kwh')
                        ->label('Usable kWh')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(200),

                    TextInput::make('efficiency_km_per_kwh')
                        ->label('Efficiency (km/kWh)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(50),

                    Toggle::make('invert_current')
                        ->label('Invert current sign'),
                ]),
            ]),

            Section::make('Advanced (JSON bentukan mobile)')->schema([
                Textarea::make('metric_config')
                    ->label('Metric Config (JSON)')
                    ->rows(5)
                    ->placeholder('[{"pid": {...}, "enabled": true, "custom": false, "modified": false}]')
                    ->afterStateHydrated(fn ($component, $state) => $component->state(
                        is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state
                    ))
                    ->dehydrated(fn ($state) => $state === null ? null : json_decode($state)),

                Textarea::make('discovered')
                    ->label('Discovered (JSON)')
                    ->rows(4)
                    ->afterStateHydrated(fn ($component, $state) => $component->state(
                        is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state
                    ))
                    ->dehydrated(fn ($state) => $state === null ? null : json_decode($state)),

                Textarea::make('usage')
                    ->label('Usage Stats (JSON)')
                    ->rows(4)
                    ->afterStateHydrated(fn ($component, $state) => $component->state(
                        is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state
                    ))
                    ->dehydrated(fn ($state) => $state === null ? null : json_decode($state)),

                Grid::make(['default' => 2])->schema([
                    TextInput::make('sort_order')
                        ->label('Sort Order')
                        ->numeric()
                        ->default(0),

                    TextInput::make('last_used_at')
                        ->label('Last Used (epoch ms)')
                        ->numeric(),
                ]),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['user', 'modelVehicle']))
            ->columns([
                TextColumn::make('nickname')
                    ->label('Nickname')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('modelVehicle.name')
                    ->label('Model')
                    ->searchable(),
                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('usable_kwh')
                    ->label('kWh')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                ToggleColumn::make('invert_current')
                    ->label('Inv.'),
                TextColumn::make('last_used_at')
                    ->label('Last Used (ms)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListObdVehicles::route('/'),
            'create' => Pages\CreateObdVehicle::route('/create'),
            'edit' => Pages\EditObdVehicle::route('/{record}/edit'),
        ];
    }
}
