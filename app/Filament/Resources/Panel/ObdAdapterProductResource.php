<?php

namespace App\Filament\Resources\Panel;

use App\Models\ObdAdapterProduct;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use App\Filament\Resources\Panel\ObdAdapterProductResource\Pages;

class ObdAdapterProductResource extends Resource
{
    protected static ?string $model = ObdAdapterProduct::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 12;
    protected static ?string $label = 'OBD Adapter';
    protected static ?string $pluralLabel = 'OBD Adapters';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Product Info')->schema([
                Grid::make(['default' => 2])->schema([
                    TextInput::make('name')
                        ->label('Product Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('brand')
                        ->label('Brand')
                        ->required()
                        ->maxLength(64),

                    Select::make('type')
                        ->label('Connection Type')
                        ->options([
                            'wifi' => 'WiFi',
                            'ble' => 'Bluetooth LE',
                            'spp' => 'Bluetooth SPP',
                            'wifi_ble' => 'WiFi + BLE',
                        ])
                        ->required(),

                    TextInput::make('price_idr')
                        ->label('Price (IDR)')
                        ->numeric()
                        ->prefix('Rp'),
                ]),
            ]),

            Section::make('Media & Links')->schema([
                Grid::make(['default' => 2])->schema([
                    TextInput::make('image_url')
                        ->label('Image URL')
                        ->url(),

                    TextInput::make('affiliate_url')
                        ->label('Affiliate URL')
                        ->url(),
                ]),
            ]),

            Section::make('Description')->schema([
                Textarea::make('description')
                    ->label('Description')
                    ->rows(4),
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
                ImageColumn::make('image_url')
                    ->label('Image')
                    ->circular()
                    ->defaultImageUrl('https://via.placeholder.com/40'),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('brand')
                    ->searchable(),

                TextColumn::make('type')
                    ->badge(fn (string $state) => match ($state) {
                        'wifi' => 'info',
                        'ble' => 'success',
                        'spp' => 'warning',
                        'wifi_ble' => 'primary',
                    }),

                TextColumn::make('price_idr')
                    ->money('IDR')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('sort_order')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
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
            'index' => Pages\ListObdAdapters::route('/'),
            'create' => Pages\CreateObdAdapter::route('/create'),
            'edit' => Pages\EditObdAdapter::route('/{record}/edit'),
        ];
    }
}
