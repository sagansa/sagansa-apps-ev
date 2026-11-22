<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Columns\CurrencyTextColumn;
use App\Filament\Columns\DecimalTextColumn;
use App\Filament\Forms\BaseSelect;
use App\Filament\Forms\CurrencyTextInput;
use App\Filament\Forms\DecimalTextInput;
use App\Filament\Forms\TodayDatePicker;
use Filament\Forms;
use Filament\Tables;
use Filament\Actions;
use Livewire\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use App\Models\DiscountHomeCharging;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;
use App\Filament\Resources\Panel\DiscountHomeChargingResource\Pages;
use App\Filament\Resources\Panel\DiscountHomeChargingResource\RelationManagers;
use App\Models\ChargerLocation;
use Filament\Actions\ActionGroup;
use Illuminate\Support\Facades\Auth;
use Ramsey\Uuid\Type\Decimal;

class DiscountHomeChargingResource extends Resource
{
    protected static ?string $model = DiscountHomeCharging::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-percent-badge';
    protected static string | \UnitEnum | null $navigationGroup = 'Aplikasi';
    protected static ?int $navigationSort = 6;




    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-percent-badge';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Aplikasi';
    }

    public static function getModelLabel(): string
    {
        return __('crud.discountHomeChargings.itemTitle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('crud.discountHomeChargings.collectionTitle');
    }

    public static function getNavigationLabel(): string
    {
        return __('crud.discountHomeChargings.collectionTitle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Grid::make(['default' => 2])->schema([
                    BaseSelect::make('charger_location_id')
                        ->label('Charger Location')
                        ->searchable()
                        ->options(function () {
                            return ChargerLocation::where('user_id', Auth::id())
                                ->where('provider_id', 'd89fa3a2-00c6-4d13-b4db-5066b33ebd17')
                                ->pluck('name', 'id');
                        }),

                    TodayDatePicker::make('month'),

                    DecimalTextInput::make('total_kwh')
                        ->suffix('kWh'),

                    DecimalTextInput::make('discount_kwh')
                        ->suffix('kWh'),

                    CurrencyTextInput::make('discount_total')
                        ->prefix('Rp'),

                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $query = DiscountHomeCharging::query();

        if (!Auth::user()->hasRole('super-admin')) {
            $query->where('user_id', Auth::id());
        }

        return $table
            ->query($query)
            ->poll('60s')
            ->columns([
                TextColumn::make('chargerLocation.name'),

                TextColumn::make('month')->since(),

                DecimalTextColumn::make('total_kwh'),

                DecimalTextColumn::make('discount_kwh'),

                CurrencyTextColumn::make('discount_total'),

                TextColumn::make('user.name')->hidden(fn () => Auth::user()->hasRole('user'))
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
            'index' => Pages\ListDiscountHomeChargings::route('/'),
            'create' => Pages\CreateDiscountHomeCharging::route('/create'),
            'view' => Pages\ViewDiscountHomeCharging::route('/{record}'),
            'edit' => Pages\EditDiscountHomeCharging::route('/{record}/edit'),
        ];
    }
}
