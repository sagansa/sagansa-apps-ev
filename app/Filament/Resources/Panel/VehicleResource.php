<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Forms\BaseSelect;
use App\Filament\Forms\ImageFileUpload;
use App\Filament\Forms\NominalTextInput;
use App\Filament\Forms\PercentTextInput;
use App\Filament\Forms\TodayDatePicker;
use Filament\Tables;
use Filament\Actions;
use App\Models\Vehicle;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Panel\VehicleResource\Pages;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Tables\Columns\StatusActiveColumn;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Infolists\Components\TextEntry;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Facades\Auth;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';
    protected static string | \UnitEnum | null $navigationGroup = 'Aplikasi';
    protected static ?int $navigationSort = 1;




    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-truck';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Aplikasi';
    }

    public static function getModelLabel(): string
    {
        return __('crud.vehicles.itemTitle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('crud.vehicles.collectionTitle');
    }

    public static function getNavigationLabel(): string
    {
        return __('crud.vehicles.collectionTitle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Grid::make(['default' => 1])->schema([
                    ImageFileUpload::make('image')->directory('images/vehicle'),
                ]),

                Grid::make(['default' => 1])->schema([

                    Group::make()->schema([
                        BaseSelect::make('brand_vehicle_id')
                            ->label('Brand')
                            ->relationship('brandVehicle', 'name')
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('model_vehicle_id', null);
                                $set('type_vehicle_id', null);
                            }),

                        BaseSelect::make('model_vehicle_id')
                            ->label('Model')
                            ->options(function (callable $get) {
                                $brandVehicleId = $get('brand_vehicle_id');
                                return ModelVehicle::where('brand_vehicle_id', $brandVehicleId)->pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('type_vehicle_id', null);
                            }),

                        Select::make('type_vehicle_id')
                            ->label('Type')
                            ->options(function (callable $get) {
                                $modelVehicleId = $get('model_vehicle_id');
                                return TypeVehicle::where('model_vehicle_id', $modelVehicleId)->pluck('name', 'id')->toArray();
                            })
                            ->nullable()
                            ->inlineLabel()
                            ->searchable()
                            ->reactive(),
                    ]),

                    Group::make()->schema([
                        TextInput::make('license_plate')
                            ->label('License Plate/Name Your Vehicle')
                            ->inlineLabel()
                            ->string()
                            ->required(),

                        TodayDatePicker::make('ownership')
                            ->rules(['date'])
                            ->nullable()
                            ->native(false),

                        BaseSelect::make('status')
                            ->default('1')
                            ->searchable()
                            ->options([
                                '1' => 'active',
                                '2' => 'inactive',
                            ]),
                    ]),
                ])
            ]),

            Section::make()->schema([
                Repeater::make('charges')
                    ->relationship()
                    ->schema([
                        Grid::make()
                            ->schema([
                                TextInput::make('km_now')
                                    ->label('km')
                                    ->inlineLabel()
                                    ->suffix('km')
                                    ->numeric(),

                                TextInput::make('finish_charging_now')
                                    ->label('Battery now')
                                    ->inlineLabel()
                                    ->suffix('%')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->requiredWith('is_finish_charging'),
                            ]),
                    ])
                    ->defaultItems(1)
                    ->addable(false)
                    ->deletable(false)
                    ->minItems(1)
                    ->maxItems(1)
                    // ->hidden(fn ($state) => count($state) > 1) // <--- Update this line
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data, Vehicle $record): array {
                        $data['user_id'] = Auth::id(); // ok
                        $data['date'] = today()->format('Y-m-d'); // ok

                        return $data;
                    })
            ])
                ->hidden(fn(?Vehicle $record) => $record !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $is_super_admin = Auth::user()->hasRole('super_admin');

                if (!$is_super_admin) {
                    $query->where('user_id', Auth::user()->id);
                }
            })
            ->poll('60s')
            ->columns([
                ImageColumn::make('image')->visibility('public'),

                TextColumn::make('license_plate')
                    ->label('Name Your Vehicle'),

                TextColumn::make('brandVehicle.name')
                    ->label('brand'),

                TextColumn::make('modelVehicle.name')
                    ->label('Model'),

                TextColumn::make('typeVehicle.name')
                    ->label('Type'),

                TextColumn::make('max_km_now')
                    ->label('km')
                    ->numeric(
                        thousandsSeparator: '.'
                    ),

                TextColumn::make('ownership'),

                StatusActiveColumn::make('status'),

                TextColumn::make('user.name')
                    ->visible(fn($record) => auth()->user()->hasRole('super_admin')), // Kondisi visibilitas,
            ])
            ->filters([])
            ->recordActions([
                ActionGroup::make([
                    Actions\EditAction::make(),
                    Actions\ViewAction::make(),
                ])
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'view' => Pages\ViewVehicle::route('/{record}'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
