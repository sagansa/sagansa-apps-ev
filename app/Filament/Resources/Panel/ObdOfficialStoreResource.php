<?php

namespace App\Filament\Resources\Panel;

use App\Models\ObdOfficialStore;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use App\Filament\Resources\Panel\ObdOfficialStoreResource\Pages;

class ObdOfficialStoreResource extends Resource
{
    protected static ?string $model = ObdOfficialStore::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 13;
    protected static ?string $label = 'Official Store';
    protected static ?string $pluralLabel = 'Official Stores';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            // Official Store (2026-11-25): link ke toko resmi kami per
            // marketplace — baris toko di kartu gerbang app, bukan produk.
            Section::make('Store')->schema([
                Grid::make(['default' => 2])->schema([
                    Select::make('marketplace')
                        ->label('Marketplace')
                        ->options([
                            'shopee' => 'Shopee',
                            'tokopedia' => 'Tokopedia',
                            'lazada' => 'Lazada',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->unique(ignoreRecord: true),

                    TextInput::make('label')
                        ->label('Display Label')
                        ->maxLength(64)
                        ->default('Official Store'),

                    TextInput::make('store_url')
                        ->label('Store URL')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                ]),
            ]),

            Section::make('Visibility')->schema([
                Grid::make(['default' => 2])->schema([
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),

                    TextInput::make('sort_order')
                        ->label('Sort Order')
                        ->numeric()
                        ->default(0),
                ]),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('marketplace')
                    ->badge(fn (string $state) => match ($state) {
                        'shopee' => 'warning',
                        'tokopedia' => 'success',
                        'lazada' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('label')
                    ->searchable(),

                TextColumn::make('store_url')
                    ->limit(40),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('sort_order')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                    Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListObdOfficialStores::route('/'),
            'create' => Pages\CreateObdOfficialStore::route('/create'),
            'edit' => Pages\EditObdOfficialStore::route('/{record}/edit'),
        ];
    }
}
