<?php

namespace App\Filament\Resources\Panel;

use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages;
use Illuminate\Database\Eloquent\Builder;

class ObdVehicleProfileResource extends Resource
{
    protected static ?string $model = ObdVehicleProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 11;
    protected static ?string $label = 'OBD Profile';
    protected static ?string $pluralLabel = 'OBD Profiles';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Vehicle Info')->schema([
                Grid::make(['default' => 2])->schema([
                    Select::make('model_vehicle_id')
                        ->label('Model Vehicle')
                        ->options(fn () => ModelVehicle::with('brandVehicle')
                            ->get()
                            ->mapWithKeys(fn ($m) => [
                                $m->id => ($m->brandVehicle?->name . ' ' . $m->name),
                            ]))
                        ->searchable()
                        ->nullable(),

                    Select::make('platform')
                        ->label('Platform')
                        ->options([
                            'E-GMP' => 'E-GMP',
                            'BEV' => 'BEV',
                            'PHEV' => 'PHEV',
                            'HEV' => 'HEV',
                            'ICE' => 'ICE',
                            'universal' => 'Universal',
                        ])
                        ->required(),

                    TextInput::make('vin_pattern')
                        ->label('VIN Pattern (regex)')
                        ->placeholder('^(LSG|KL8)'),

                    Grid::make(['default' => 2])->schema([
                        TextInput::make('year_min')
                            ->label('Year Min')
                            ->numeric()
                            ->minValue(2000)
                            ->maxValue(2099),

                        TextInput::make('year_max')
                            ->label('Year Max')
                            ->numeric()
                            ->minValue(2000)
                            ->maxValue(2099),
                    ]),
                ]),
            ]),

            Section::make('Classification')->schema([
                Grid::make(['default' => 3])->schema([
                    Select::make('grade')
                        ->label('Grade')
                        ->options([
                            'official' => 'Official',
                            'community' => 'Community',
                            'experimental' => 'Experimental',
                        ])
                        ->required(),

                    TextInput::make('version')
                        ->label('Version')
                        ->default('1.0'),

                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'active' => 'Active',
                            'deprecated' => 'Deprecated',
                            'draft' => 'Draft',
                        ])
                        ->required(),
                ]),
            ]),

            Section::make('PID Definitions')->schema([
                Repeater::make('pid_list')
                    ->label('Supported PIDs')
                    ->schema([
                        Grid::make(['default' => 4])->schema([
                            TextInput::make('mode')
                                ->label('Mode')
                                ->numeric()
                                ->required()
                                ->columnSpan(1),

                            TextInput::make('pid')
                                ->label('PID')
                                ->numeric()
                                ->required()
                                ->columnSpan(1),

                            TextInput::make('description')
                                ->label('Description')
                                ->required()
                                ->columnSpan(2),
                        ]),
                        Grid::make(['default' => 3])->schema([
                            TextInput::make('formula')
                                ->label('Formula')
                                ->default('A')
                                ->required(),

                            TextInput::make('unit')
                                ->label('Unit')
                                ->default('-'),

                            Select::make('category')
                                ->label('Category')
                                ->options([
                                    'general' => 'General',
                                    'ev_specific' => 'EV Specific',
                                    'engine' => 'Engine',
                                    'battery' => 'Battery',
                                ])
                                ->default('general'),
                        ]),
                    ])
                    ->columns(1)
                    ->defaultItems(0)
                    ->addActionLabel('Add PID'),
            ]),

            Section::make('Metadata')->schema([
                Forms\Components\Textarea::make('metadata')
                    ->label('Metadata (JSON)')
                    ->rows(4)
                    ->placeholder('{"notes": "..."}'),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with('modelVehicle'))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('modelVehicle.name')
                    ->label('Vehicle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform')
                    ->badge()
                    ->sortable(),
                TextColumn::make('grade')
                    ->badge(fn (string $state) => match ($state) {
                        'official' => 'success',
                        'community' => 'warning',
                        'experimental' => 'danger',
                    }),
                TextColumn::make('status')
                    ->badge(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'deprecated' => 'danger',
                        'draft' => 'gray',
                    }),
                TextColumn::make('version'),
                TextColumn::make('year_min'),
                TextColumn::make('year_max'),
            ])
            ->defaultSort('id', 'desc')
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
            'index' => Pages\ListObdProfiles::route('/'),
            'create' => Pages\CreateObdProfile::route('/create'),
            'edit' => Pages\EditObdProfile::route('/{record}/edit'),
        ];
    }
}
