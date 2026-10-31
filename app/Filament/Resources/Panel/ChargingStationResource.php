<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Concerns\AvailabilityLevelColors;
use App\Filament\Concerns\TypeChargeColors;
use App\Filament\Resources\Panel\ChargingStationResource\Pages;
use App\Filament\Resources\Panel\ChargingStationResource\RelationManagers\ChargersRelationManager;
use App\Models\ChargingStation;
use App\Models\Provider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ChargingStationResource extends Resource
{
    use AvailabilityLevelColors;
    use TypeChargeColors;

    protected static ?string $model = ChargingStation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Provider & SPKLU';

    protected static ?string $navigationLabel = 'Stasiun Charging (Publik)';

    protected static ?string $modelLabel = 'Stasiun Charging';

    protected static ?string $pluralModelLabel = 'Stasiun Charging (Publik)';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Lokasi')->schema([
                Grid::make(2)->schema([
                    TextInput::make('nama_lokasi')
                        ->label('Nama Lokasi')
                        ->required()
                        ->string(),
                    TextInput::make('provinsi')
                        ->label('Provinsi')
                        ->string(),
                    TextInput::make('alamat')
                        ->label('Alamat')
                        ->string()
                        ->columnSpanFull(),
                    TextInput::make('latitude')
                        ->label('Latitude')
                        ->numeric(),
                    TextInput::make('longitude')
                        ->label('Longitude')
                        ->numeric(),
                ]),
            ]),

            Section::make('Info Charging')->schema([
                Grid::make(3)->schema([
                    Select::make('type_charge')
                        ->label('Tier Pengisian')
                        ->options([
                            'Slow Charging' => 'Slow Charging',
                            'Medium Charging' => 'Medium Charging',
                            'Fast Charging' => 'Fast Charging',
                            'Ultra Fast Charging' => 'Ultra Fast Charging',
                        ]),
                    TextInput::make('watt')
                        ->label('Watt')
                        ->string(),
                    TextInput::make('total_charger')
                        ->label('Total Charger')
                        ->numeric(),
                    TextInput::make('total_konektor')
                        ->label('Total Konektor')
                        ->numeric(),
                    TextInput::make('nama_badan_usaha')
                        ->label('Badan Usaha')
                        ->string(),
                    Select::make('provider_id')
                        ->label('Provider EV')
                        ->options(fn () => Provider::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('—'),
                ]),
            ]),

            Section::make('Status Real-time (agregat dari poller)')->schema([
                Grid::make(3)->schema([
                    Select::make('availability_level')
                        ->label('Availability')
                        ->options([
                            'available' => 'Tersedia',
                            'partial' => 'Sebagian',
                            'occupied' => 'Penuh',
                            'offline' => 'Offline',
                            'unknown' => 'Tidak Diketahui',
                        ]),
                    TextInput::make('available_count')
                        ->label('Tersedia')
                        ->numeric(),
                    TextInput::make('charging_count')
                        ->label('Mengisi')
                        ->numeric(),
                    TextInput::make('finishing_count')
                        ->label('Menunggu')
                        ->numeric(),
                    TextInput::make('status_updated_at')
                        ->label('Terakhir Update')
                        ->disabled()
                        ->string(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('nama_lokasi')
                    ->label('Nama Lokasi')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40),
                TextColumn::make('type_charge')
                    ->label('Tier')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => static::typeChargeColor($state)),
                TextColumn::make('provinsi')
                    ->label('Provinsi')
                    ->searchable()
                    ->sortable(),
                // kabupaten_kota disembunyikan (selalu NULL di ESDM; search lewat alamat cukup)
                TextColumn::make('availability_level')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::availabilityLevelLabel($state))
                    ->color(fn (string $state): string => static::availabilityLevelColor($state)),
                TextColumn::make('available')
                    ->label('Siap/Konektor')
                    ->getStateUsing(fn (ChargingStation $record): string => $record->available_count.'/'.$record->total_konektor)
                    ->alignCenter(),
                // Tampilkan nama provider dari tabel providers (relasi), BUKAN
                // provider_name mentah (nama_badan_usaha ESDM). Yang unmatched
                // (tanpa provider_id) tampil "—" — bisa di-filter via TernaryFilter.
                TextColumn::make('provider.name')
                    ->label('Provider')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                // Badan Usaha = nama legal operator dari ESDM (sumber). Dipakai utk
                // identifikasi provider yg belum match (bulk edit mapping).
                TextColumn::make('nama_badan_usaha')
                    ->label('Badan Usaha')
                    ->searchable()
                    ->sortable()
                    ->limit(35)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'esdm' => 'info',
                        'pln' => 'success',
                        'ocm' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('country_code')
                    ->label('Negara')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status_updated_at')
                    ->label('Update Status')
                    ->dateTime('d M Y H:i')
                    ->timezone('Asia/Jakarta')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('availability_level')
                    ->label('Filter Status')
                    ->options([
                        'available' => 'Tersedia',
                        'partial' => 'Sebagian',
                        'occupied' => 'Penuh',
                        'offline' => 'Offline',
                        'unknown' => 'Tidak Diketahui',
                    ]),
                SelectFilter::make('type_charge')
                    ->label('Filter Tier')
                    ->options([
                        'Slow Charging' => 'Slow Charging',
                        'Medium Charging' => 'Medium Charging',
                        'Fast Charging' => 'Fast Charging',
                        'Ultra Fast Charging' => 'Ultra Fast Charging',
                    ]),
                SelectFilter::make('provinsi')
                    ->label('Filter Provinsi')
                    ->options(fn () => ChargingStation::query()->whereNotNull('provinsi')->distinct()->pluck('provinsi', 'provinsi')->toArray())
                    ->searchable(),
                SelectFilter::make('source')
                    ->label('Filter Sumber')
                    ->options([
                        'esdm' => 'esdm',
                        'pln' => 'pln',
                        'ocm' => 'ocm (OpenChargeMap)',
                    ]),
                SelectFilter::make('country_code')
                    ->label('Filter Negara')
                    ->options(fn () => ChargingStation::query()->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code', 'country_code')->toArray()),
                SelectFilter::make('provider_id')
                    ->label('Filter Provider')
                    ->relationship('provider', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('nama_badan_usaha')
                    ->label('Filter Badan Usaha')
                    ->options(fn () => ChargingStation::query()->whereNotNull('nama_badan_usaha')->distinct()->orderBy('nama_badan_usaha')->pluck('nama_badan_usaha', 'nama_badan_usaha')->toArray())
                    ->searchable(),
                TernaryFilter::make('has_provider')
                    ->label('Provider')
                    ->placeholder('Semua')
                    ->trueLabel('Punya provider')
                    ->falseLabel('Tanpa provider')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('provider_id'),
                        false: fn ($query) => $query->whereNull('provider_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                \Filament\Actions\ActionGroup::make([
                    \Filament\Actions\ViewAction::make(),
                    \Filament\Actions\EditAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ChargersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChargingStations::route('/'),
            'view' => Pages\ViewChargingStation::route('/{record}'),
            'edit' => Pages\EditChargingStation::route('/{record}/edit'),
        ];
    }
}
