<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Resources\Panel\OcmOperatorMatchResource\Pages;
use App\Models\OcmOperatorMatch;
use App\Models\Provider;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Review entity-resolution operator OCM → providers. Match 'auto' hasil
 * ocm:import-operators; admin bisa koreksi manual (ubah provider / status).
 */
class OcmOperatorMatchResource extends Resource
{
    protected static ?string $model = OcmOperatorMatch::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Provider & SPKLU';

    protected static ?string $navigationLabel = 'Match Operator OCM';

    protected static ?string $modelLabel = 'Match Operator OCM';

    protected static ?string $pluralModelLabel = 'Match Operator OCM';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Select::make('operator_title')
                ->label('Operator OCM (judul)')
                ->disabled(),
            Select::make('provider_id')
                ->label('Provider EV')
                ->options(fn () => Provider::query()->orderBy('name')->pluck('name', 'id')->toArray())
                ->searchable()
                ->placeholder('—'),
            Select::make('match_status')
                ->label('Status')
                ->options([
                    'auto' => 'auto',
                    'manual' => 'manual',
                    'pending' => 'pending',
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('ocm_operator_id')
                    ->label('OCM Op ID')
                    ->sortable()
                    ->placeholder('(by title)'),
                TextColumn::make('operator_title')
                    ->label('Operator OCM')
                    ->searchable()
                    ->limit(40)
                    ->weight('bold'),
                TextColumn::make('provider.name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('match_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'success',
                        'auto' => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('match_method')
                    ->label('Metode')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Diupdate')
                    ->dateTime('d M Y H:i')
                    ->timezone('Asia/Jakarta')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label('Filter Status')
                    ->options([
                        'auto' => 'auto',
                        'manual' => 'manual',
                        'pending' => 'pending',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOcmOperatorMatches::route('/'),
            'edit' => Pages\EditOcmOperatorMatch::route('/{record}/edit'),
        ];
    }
}
