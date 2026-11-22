<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Resources\Panel\ChargerResource\Pages;
use App\Filament\Resources\ChargerResource\RelationManagers;
use App\Models\Charger;
use App\Models\PowerCharger;
use App\Models\TypeCharger;
use App\Tables\Columns\StatusLocationColumn;
use Filament\Forms;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Illuminate\Support\Facades\Auth;
use Filament\Actions\BulkAction;

class ChargerResource extends Resource
{
    protected static ?string $model = Charger::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bolt';
    protected static string | \UnitEnum | null $navigationGroup = 'Aplikasi';
    protected static ?int $navigationSort = 4;




    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-bolt';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Aplikasi';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()->schema([
                    Section::make()->schema([
                        Select::make('charger_location_id')
                            ->relationship('chargerLocation', 'name', function (Builder $query) {
                                return $query->with('provider');
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                $chargerLocation = \App\Models\ChargerLocation::with('provider')->find($value);
                                if (!$chargerLocation) {
                                    return null;
                                }
                                return "{$chargerLocation->name} - {$chargerLocation->provider->name}";
                            })
                            ->inlineLabel()
                            ->required()
                            ->searchable(['name', 'provider.name']),

                        Select::make('current_charger_id')
                            ->relationship('currentCharger', 'name')
                            ->inlineLabel()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                $set('type_charger_id', null);
                                $set('power_charger_id', null);
                            }),

                        Select::make('type_charger_id')
                            ->relationship('typeCharger', 'name')
                            ->inlineLabel()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn(Set $set) => $set('power_charger_id', null))
                            ->options(function (Get $get) {
                                $currentChargerId = $get('current_charger_id');
                                if (!$currentChargerId) {
                                    return Collection::empty();
                                }
                                return TypeCharger::where('current_charger_id', $currentChargerId)->pluck('name', 'id');
                            }),

                        Select::make('power_charger_id')
                            ->relationship('powerCharger', 'name')
                            ->inlineLabel()
                            ->required()
                            ->options(function (Get $get) {
                                $typeChargerId = $get('type_charger_id');
                                if (!$typeChargerId) {
                                    return Collection::empty();
                                }
                                return PowerCharger::where('type_charger_id', $typeChargerId)->pluck('name', 'id');
                            }),

                        TextInput::make('unit')
                            ->inlineLabel()
                            ->required()
                            ->numeric(),
                    ])
                ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        $chargers = Charger::query();

        if (Auth::user()->hasRole('user')) {
            $chargers->where('user_id', Auth::id());
        }

        return $table
            ->query($chargers)
            ->poll('60s')
            ->columns([
                TextColumn::make('chargerLocation.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('chargerLocation.provider.name')
                    ->sortable(),

                TextColumn::make('currentCharger.name')
                    ->sortable(),

                TextColumn::make('typeCharger.name')
                    ->sortable(),

                TextColumn::make('powerCharger.name')
                    ->sortable(),

                TextColumn::make('chargerLocation.city.name')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('chargerLocation.province.name')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('status')
                    ->sortable()
                    ->formatStateUsing(
                        fn(string $state): string => match ($state) {
                            '1' => 'not verified',
                            '2' => 'verified',
                            '3' => 'closed',
                            '4' => 'external',
                            default => $state,
                        }
                    )
                    ->badge()
                    ->color(
                        fn(string $state): string => match ($state) {
                            '1' => 'warning',
                            '2' => 'success',
                            '3' => 'danger',
                            '4' => 'gray',
                            default => 'gray',
                        }
                    ),

                TextColumn::make('user.name')
                    ->visible(fn($record) => !auth()->user()->hasRole('user'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('currentCharger')
                    ->relationship('currentCharger', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        ->hidden(function ($record) {
                            $user = Auth::user();
                            // Sembunyikan jika user bukan admin dan status charger adalah 2
                            return $user->hasRole('user') && $record->status === 2;
                        }),
                ]),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    BulkAction::make('updateStatusVerified')
                        ->label('Change Status to Verified')
                        ->action(function (Collection $records) {
                            $records->each(function ($record) {
                                $record->update(['status' => 2]);
                            });
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn() => !Auth::user()->hasRole('user')),

                    BulkAction::make('updateStatusClosed')
                        ->label('Change Status to Closed')
                        ->action(function (Collection $records) {
                            $records->each(function ($record) {
                                $record->update(['status' => 3]);
                            });
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->color('gray')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn() => !Auth::user()->hasRole('user')),
                ])
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChargers::route('/'),
            'create' => Pages\CreateCharger::route('/create'),
            'view' => Pages\ViewCharger::route('/{record}'),
            'edit' => Pages\EditCharger::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query) {
                $query->where('status', '<>', 3)
                    ->orWhere(function (Builder $subQuery) {
                        $subQuery->where('status', 3)
                            ->where('user_id', Auth::id());
                    });
            });
    }
}
