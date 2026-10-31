<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Resources\Panel\OpenchargemapPoiResource\Pages;
use App\Models\OpenchargemapPoi;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Browse POI OpenChargeMap hasil ocm:sync / ocm:import / harvest nearby —
 * jalur verifikasi backend sebelum data masuk lapisan canonical.
 */
class OpenchargemapPoiResource extends Resource
{
    protected static ?string $model = OpenchargemapPoi::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|\UnitEnum|null $navigationGroup = 'Provider & SPKLU';

    protected static ?string $navigationLabel = 'POI OpenChargeMap';

    protected static ?string $modelLabel = 'POI OCM';

    protected static ?string $pluralModelLabel = 'POI OpenChargeMap';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('POI OpenChargeMap')->schema([
                Grid::make(3)->schema([
                    Placeholder::make('title')
                        ->label('Nama')
                        ->content(fn (OpenchargemapPoi $record): string => (string) ($record->title ?? '—')),
                    Placeholder::make('operator')
                        ->label('Operator')
                        ->content(fn (OpenchargemapPoi $record): string => (string) ($record->operator ?? '—')),
                    Placeholder::make('country_code')
                        ->label('Negara')
                        ->content(fn (OpenchargemapPoi $record): string => (string) ($record->country_code ?? '—')),
                    Placeholder::make('alamat')
                        ->label('Alamat')
                        ->content(fn (OpenchargemapPoi $record): string => collect([
                            $record->address_line1,
                            $record->address_line2,
                            $record->town,
                            $record->state_or_province,
                        ])->filter()->implode(', ') ?: '—'),
                    Placeholder::make('koordinat')
                        ->label('Koordinat')
                        ->content(fn (OpenchargemapPoi $record): string => $record->latitude !== null
                            ? $record->latitude.', '.$record->longitude
                            : '—'),
                    Placeholder::make('usage_cost')
                        ->label('Biaya')
                        ->content(fn (OpenchargemapPoi $record): string => (string) ($record->usage_cost ?? '—')),
                    Placeholder::make('connections_json')
                        ->label('Connections (JSON)')
                        ->content(fn (OpenchargemapPoi $record): string => $record->connections
                            ? json_encode($record->connections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                            : '—')
                        ->columnSpanFull(),
                ]),
            ])->compact(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('ocm_id')
                    ->label('OCM ID')
                    ->sortable(),
                TextColumn::make('country_code')
                    ->label('Negara')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Nama')
                    ->searchable()
                    ->limit(40)
                    ->weight('bold'),
                TextColumn::make('operator')
                    ->label('Operator')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('—'),
                TextColumn::make('town')
                    ->label('Kota')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('—'),
                TextColumn::make('state_or_province')
                    ->label('Provinsi/Negara Bagian')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('—'),
                TextColumn::make('number_of_points')
                    ->label('Poin')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('data_quality_level')
                    ->label('Kualitas')
                    ->badge()
                    ->sortable()
                    ->color(fn ($state): string => ((int) $state >= 3 ? 'success' : 'gray')),
                TextColumn::make('date_last_status_update')
                    ->label('Update Terakhir')
                    ->dateTime('d M Y H:i')
                    ->timezone('Asia/Jakarta')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label('Filter Negara')
                    ->options(fn () => OpenchargemapPoi::query()->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code', 'country_code')->toArray()),
                SelectFilter::make('operator')
                    ->label('Filter Operator')
                    ->options(fn () => OpenchargemapPoi::query()->whereNotNull('operator')->distinct()->orderBy('operator')->limit(500)->pluck('operator', 'operator')->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->defaultSort('ocm_id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOpenchargemapPois::route('/'),
            'view' => Pages\ViewOpenchargemapPoi::route('/{record}'),
        ];
    }
}
