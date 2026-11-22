<?php

namespace App\Filament\Resources\Panel;

use Filament\Forms;
use Filament\Tables;
use Filament\Actions;
use Livewire\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use App\Models\MerkCharger;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Panel\MerkChargerResource\Pages;
use App\Filament\Resources\Panel\MerkChargerResource\RelationManagers;
use Filament\Actions\ActionGroup;

class MerkChargerResource extends Resource
{
    protected static ?string $model = MerkCharger::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';
    protected static string | \UnitEnum | null $navigationGroup = 'Master Charger';
    protected static ?int $navigationSort = 4;




    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-identification';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Master Charger';
    }

    public static function getModelLabel(): string
    {
        return __('crud.merkChargers.itemTitle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('crud.merkChargers.collectionTitle');
    }

    public static function getNavigationLabel(): string
    {
        return __('crud.merkChargers.collectionTitle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Grid::make(['default' => 1])->schema([
                    TextInput::make('name')
                        ->required()
                        ->string()
                        ->autofocus(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->columns([TextColumn::make('name')])
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
            'index' => Pages\ListMerkChargers::route('/'),
            'create' => Pages\CreateMerkCharger::route('/create'),
            'view' => Pages\ViewMerkCharger::route('/{record}'),
            'edit' => Pages\EditMerkCharger::route('/{record}/edit'),
        ];
    }
}
