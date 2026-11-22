<?php

namespace App\Filament\Resources\Panel;

use App\Models\ObdScanReport;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\Panel\ObdScanReportResource\Pages;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only review laporan scan OBD2 dari mobile. Bahan baku developer untuk
 * melahirkan baris ObdVehicleProfile baru (kendaraan unknown/universal yang
 * mulai terlihat polanya). Tidak ada create/edit/delete dari panel.
 */
class ObdScanReportResource extends Resource
{
    protected static ?string $model = ObdScanReport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 12;
    protected static ?string $label = 'Scan Report';
    protected static ?string $pluralLabel = 'Scan Reports';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Section::make('Kendaraan')->schema([
                Forms\Components\Placeholder::make('user')
                    ->label('User')
                    ->content(fn ($record) => $record?->user?->email ?? '-'),
                Forms\Components\Placeholder::make('model_vehicle')
                    ->label('Model (garasi)')
                    ->content(fn ($record) => $record?->modelVehicle?->name ?? '-'),
                Forms\Components\Placeholder::make('vin')
                    ->label('VIN')
                    ->content(fn ($record) => $record?->vin
                        ? (($record->vin_shared ? '' : 'prefix ') . $record->vin)
                        : '- (tidak dibagikan)'),
            ])->columns(3),

            Forms\Components\Section::make('Sesi & Adapter')->schema([
                Forms\Components\Placeholder::make('adapter_type')
                    ->label('Adapter')
                    ->content(fn ($record) => $record?->adapter_type ?? '-'),
                Forms\Components\Placeholder::make('elm_protocol')
                    ->label('Protokol ELM')
                    ->content(fn ($record) => $record?->elm_protocol ?? '-'),
                Forms\Components\Placeholder::make('atrv_voltage')
                    ->label('ATRV')
                    ->content(fn ($record) => $record?->atrv_voltage !== null ? $record->atrv_voltage . ' V' : '-'),
                Forms\Components\Placeholder::make('duration')
                    ->label('Durasi')
                    ->content(fn ($record) => $record?->duration_ms !== null
                        ? round($record->duration_ms / 1000) . ' s'
                        : '-'),
            ])->columns(4),

            Forms\Components\Section::make('Profil Dipakai')->schema([
                Forms\Components\Placeholder::make('profile_match')
                    ->label('Match')
                    ->content(fn ($record) => $record?->profile_match ?? '-'),
                Forms\Components\Placeholder::make('profile_key')
                    ->label('Key')
                    ->content(fn ($record) => $record?->profile_key ?? '-'),
                Forms\Components\Placeholder::make('profile_version')
                    ->label('Versi')
                    ->content(fn ($record) => $record?->profile_version ?? '-'),
                Forms\Components\Placeholder::make('review_status')
                    ->label('Review')
                    ->content(fn ($record) => $record?->review_status ?? '-'),
            ])->columns(4),

            Forms\Components\Section::make('ECU yang Membalas')->schema([
                Textarea::make('ecus_responded')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->rows(2)
                    ->disabled()
                    ->dehydrated(false),
            ]),

            Forms\Components\Section::make('PID')->schema([
                Forms\Components\Placeholder::make('pid_counts')
                    ->label('Ringkas')
                    ->content(fn ($record) => ($record?->supported_pids ? count($record->supported_pids) : 0)
                        . ' didukung / ' . ($record?->unsupported_pids ? count($record->unsupported_pids) : 0) . ' gagal'),
                Textarea::make('supported_pids')
                    ->label('Didukung')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->rows(6)
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('unsupported_pids')
                    ->label('Tidak Didukung')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->rows(6)
                    ->disabled()
                    ->dehydrated(false),
            ])->columns(2),

            Forms\Components\Section::make('Discovery (kendaraan unknown)')->schema([
                Textarea::make('discovery')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->rows(8)
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('dtc_codes')
                    ->label('DTC')
                    ->formatStateUsing(fn ($state) => json_encode($state))
                    ->rows(2)
                    ->disabled()
                    ->dehydrated(false),
            ]),

            Forms\Components\Section::make('Meta')->schema([
                Forms\Components\Placeholder::make('app_version')
                    ->label('App')
                    ->content(fn ($record) => trim(($record?->app_version ?? '-') . ' / ' . ($record?->device_platform ?? '-'))),
                Forms\Components\Placeholder::make('captured_at')
                    ->label('Captured')
                    ->content(fn ($record) => $record?->captured_at?->format('Y-m-d H:i:s') ?? '-'),
            ])->columns(2),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'modelVehicle']))
            ->columns([
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->limit(24),
                TextColumn::make('vin')
                    ->label('VIN')
                    ->placeholder('-')
                    ->copyable()
                    ->limit(17),
                TextColumn::make('modelVehicle.name')
                    ->label('Model')
                    ->placeholder('-'),
                TextColumn::make('adapter_type')
                    // DEMO-SEND (temporary): laporan sesi demo ditandai abu saat kurasi.
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'DEMO' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('profile_match')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'seed', 'backend' => 'success',
                        'universal' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'reviewed' => 'info',
                        'promoted' => 'success',
                        'discarded' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('atrv_voltage')
                    ->label('ATRV')
                    ->placeholder('-'),
                TextColumn::make('captured_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->filters([
                // DEMO-SEND (temporary): filter laporan demo utk kurasi.
                Tables\Filters\SelectFilter::make('adapter_type')
                    ->options([
                        'WIFI' => 'WIFI',
                        'BLE' => 'BLE',
                        'SPP' => 'SPP',
                        'DEMO' => 'DEMO (dev)',
                    ]),
                Tables\Filters\SelectFilter::make('profile_match')
                    ->options([
                        'unknown' => 'Unknown',
                        'universal' => 'Universal',
                        'seed' => 'Seed',
                        'backend' => 'Backend',
                    ]),
                Tables\Filters\SelectFilter::make('review_status')
                    ->label('Review')
                    ->options([
                        'new' => 'Baru',
                        'reviewed' => 'Direview',
                        'promoted' => 'Dipromosikan',
                        'discarded' => 'Dibuang',
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
            'index' => Pages\ListObdScanReports::route('/'),
            'view' => Pages\ViewObdScanReport::route('/{record}'),
        ];
    }
}
