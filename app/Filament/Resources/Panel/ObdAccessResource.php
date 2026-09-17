<?php

namespace App\Filament\Resources\Panel;

use App\Models\ObdAccess;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use App\Filament\Resources\Panel\ObdAccessResource\Pages;
use Illuminate\Database\Eloquent\Builder;

class ObdAccessResource extends Resource
{
    protected static ?string $model = ObdAccess::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';
    protected static string|\UnitEnum|null $navigationGroup = 'OBD2';
    protected static ?int $navigationSort = 10;
    protected static ?string $label = 'OBD Access';
    protected static ?string $pluralLabel = 'OBD Access';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Section::make('OBD Access Control')->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn () => User::pluck('email', 'id'))
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'granted' => 'Granted',
                        'revoked' => 'Revoked',
                    ])
                    ->required(),

                TextInput::make('granted_by')
                    ->label('Granted By')
                    ->placeholder('admin@example.com'),

                Forms\Components\DateTimePicker::make('granted_at')
                    ->label('Granted At'),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('user.email')
                    ->label('User Email')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(fn (string $state) => match ($state) {
                        'granted' => 'success',
                        'revoked' => 'danger',
                    }),
                TextColumn::make('granted_by'),
                TextColumn::make('granted_at')
                    ->dateTime(),
                TextColumn::make('updated_at')
                    ->dateTime(),
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
            'index' => Pages\ListObdAccess::route('/'),
            'create' => Pages\CreateObdAccess::route('/create'),
            'edit' => Pages\EditObdAccess::route('/{record}/edit'),
        ];
    }
}
