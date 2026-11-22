<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Forms\ImageFileUpload;
use App\Filament\Resources\Panel\ModelVehicleResource\Pages;
use App\Filament\Resources\Panel\ModelVehicleResource\RelationManagers;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use App\Support\VehicleCategories;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ModelVehicleResource extends Resource
{
    protected static ?string $model = ModelVehicle::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string | \UnitEnum | null $navigationGroup = 'Referensi Kendaraan';

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-squares-2x2';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Referensi Kendaraan';
    }

    public static function getModelLabel(): string
    {
        return __('crud.modelVehicles.itemTitle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('crud.modelVehicles.collectionTitle');
    }

    public static function getNavigationLabel(): string
    {
        return __('crud.modelVehicles.collectionTitle');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Informasi Model')
                ->schema([
                    TextEntry::make('brandVehicle.name')->label('Brand')->badge()->color('primary'),
                    TextEntry::make('name')->label('Nama Model')->weight('bold'),
                    // Powertrain kini milik TYPE — ditampilkan sebagai gabungan
                    // dari seluruh type di bawah keluarga ini.
                    TextEntry::make('powertrains')
                        ->label('Powertrain (dari type)')
                        ->badge()
                        ->state(fn ($record) => $record->typeVehicles()
                            ->whereNotNull('powertrain')
                            ->distinct()
                            ->pluck('powertrain')
                            ->all()),
                    TextEntry::make('category')
                        ->label('Kategori')
                        ->badge()
                        ->color('info')
                        ->placeholder('—'),
                    TextEntry::make('size_class')
                        ->label('Ukuran')
                        ->badge()
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Identitas Model Kendaraan')
                ->description('Informasi dasar merek dan model kendaraan.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('brand_vehicle_id')
                            ->required()
                            ->relationship('brandVehicle', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Brand / Merek')
                            ->autofocus(),

                        TextInput::make('name')
                            ->required()
                            ->string()
                            ->label('Nama Model')
                            ->placeholder('cth. Air EV, Seal, Ioniq 5, Binguo EV, Omoda E5'),

                        ImageFileUpload::make('image')
                            ->directory('images/model')
                            ->image()
                            ->label('Foto / Ilustrasi Model')
                            ->columnSpanFull(),
                    ]),
                ]),

            Section::make('Klasifikasi & Taksonomi Kendaraan')
                ->description('Karakteristik teknis yang digunakan untuk katalog, segmentasi pasar EV, dan integrasi GAIKINDO.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('category')
                            ->options(array_combine(VehicleCategories::CATEGORIES, VehicleCategories::CATEGORIES))
                            ->searchable()
                            ->label('Kategori Kendaraan')
                            ->placeholder('Pilih Kategori (cth. SUV, MPV, City Car)'),

                        Select::make('size_class')
                            ->options(array_combine(VehicleCategories::SIZES, VehicleCategories::SIZES))
                            ->label('Ukuran (Size Class)')
                            ->placeholder('Pilih Ukuran (Compact, Medium, Large)'),

                        TextInput::make('platform')
                            ->label('Platform Teknis')
                            ->placeholder('cth. E-GMP, BYD e-Platform 3.0')
                            ->helperText('Pengikat profil OBD level-platform: satu baris profil (tanpa model) dipakai bersama semua model yang platform-nya sama. Kosongkan bila belum diketahui.')
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                // Cakupan profil OBD per model: total baris ter-mapping +
                // berapa di antaranya seed resmi (grade official).
                ->withCount('obdVehicleProfiles')
                ->withCount(['obdVehicleProfiles as obd_official_count' => fn ($q) => $q->where('grade', 'official')]))
            ->columns([
                ImageColumn::make('image')
                    ->label('Foto')
                    ->visibility('public'),

                TextColumn::make('brandVehicle.name')
                    ->label('Brand')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nama Model')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('vehicles_count')
                    ->counts('vehicles')
                    ->label('Dipakai User')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn ($state) => $state ? 'gray' : 'warning')
                    ->formatStateUsing(fn ($state) => $state ?? '⚠️ Tanpa Kategori')
                    ->sortable(),

                TextColumn::make('size_class')
                    ->label('Ukuran')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('platform')
                    ->label('Platform')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('type_vehicles_count')
                    ->counts('typeVehicles')
                    ->label('Varian Type')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('obd_vehicle_profiles_count')
                    ->label('Profil OBD')
                    // ★ = ada seed resmi (manual, cth. Wuling Air EV); angka =
                    // jumlah profil ter-mapping (katalog CS-* + resmi);
                    // "belum ada" = kandidat dibuat manual / dicarikan profil
                    // katalog — layar kerja cakupan OBD.
                    ->badge()
                    ->color(fn ($state, $record) => match (true) {
                        ($record->obd_official_count ?? 0) > 0 => 'success',
                        $state > 0 => 'info',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn ($state, $record) => match (true) {
                        ($record->obd_official_count ?? 0) > 0 => '★ ' . $state,
                        $state > 0 => (string) $state,
                        default => 'belum ada',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand_vehicle_id')
                    ->relationship('brandVehicle', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Brand'),

                SelectFilter::make('category')
                    ->options(array_combine(VehicleCategories::CATEGORIES, VehicleCategories::CATEGORIES))
                    ->searchable()
                    ->label('Kategori'),

                TernaryFilter::make('has_category')
                    ->label('Status Kategori')
                    ->placeholder('Semua Status')
                    ->trueLabel('Kategori Lengkap')
                    ->falseLabel('⚠️ Tanpa Kategori (Perlu Diisi)')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('category'),
                        false: fn ($query) => $query->whereNull('category'),
                    ),

                TernaryFilter::make('has_obd_profile')
                    ->label('Status Profil OBD')
                    ->placeholder('Semua Status')
                    ->trueLabel('Sudah Ada Profil')
                    ->falseLabel('Belum Ada Profil (Perlu Dikerjakan)')
                    ->queries(
                        true: fn (Builder $query) => $query->has('obdVehicleProfiles'),
                        false: fn (Builder $query) => $query->doesntHave('obdVehicleProfiles'),
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Actions\EditAction::make(),
                Actions\ViewAction::make(),
                self::pilihProfilObdAction(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('brand_vehicle_id', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TypeVehiclesRelationManager::class,
        ];
    }

    /** Katalog hanya lahir dari CONNECTING — create manual ditutup. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Label opsi pemilih profil: [grade • metrik] Catalog Name → mapping saat ini. */
    public static function profileOptionLabel(?ObdVehicleProfile $p): ?string
    {
        if ($p === null) {
            return null;
        }

        $metrics = count($p->pid_list['metrics'] ?? []);
        $mapped = $p->modelVehicle !== null
            ? trim(($p->modelVehicle->brandVehicle?->name ?? '') . ' ' . $p->modelVehicle->name)
            : 'belum ter-map';

        return sprintf(
            '[%s • %d metrik] %s → %s',
            $p->grade,
            $metrics,
            $p->metadata['model_name'] ?? $p->platform,
            $mapped,
        );
    }

    /**
     * Arah mapping sebaliknya: dari model katalog memilih profil OBD
     * (katalog CS-* maupun resmi) — di baris daftar Model Vehicles dan di
     * header halaman Edit. Assign menimpa mapping lama profil terpilih
     * (label opsi menampilkan mapping saat ini agar admin sadar memindahkan).
     */
    public static function pilihProfilObdAction(): Actions\Action
    {
        return Actions\Action::make('pilihProfilObd')
            ->label('Pilih Profil OBD')
            ->icon('heroicon-o-cpu-chip')
            ->color('info')
            ->form([
                Select::make('profile_ids')
                    ->label('Profil OBD untuk model ini')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->helperText('Ketik nama model/brand/hash. Format: [grade • metrik] Catalog Name → mapping saat ini. Aturan: pilih profil dengan metrik > 13 untuk EV; satu model cukup satu profil utama.')
                    ->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => ObdVehicleProfile::query()
                        ->with('modelVehicle.brandVehicle')
                        ->where(function ($q) use ($search) {
                            $q->where('platform', 'like', "%{$search}%")
                                ->orWhere('metadata->model_name', 'like', "%{$search}%")
                                ->orWhere('metadata->brand', 'like', "%{$search}%");
                        })
                        ->orderBy('metadata->model_name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (ObdVehicleProfile $p) => [$p->id => self::profileOptionLabel($p)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => self::profileOptionLabel(
                        ObdVehicleProfile::query()->with('modelVehicle.brandVehicle')->find($value),
                    )),
            ])
            ->databaseTransaction()
            ->action(function (array $data, ModelVehicle $record) {
                $count = ObdVehicleProfile::query()
                    ->whereIn('id', $data['profile_ids'] ?? [])
                    ->update(['model_vehicle_id' => $record->id]);

                Notification::make()
                    ->title("{$count} profil OBD di-assign ke {$record->brandVehicle?->name} {$record->name}")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListModelVehicles::route('/'),
            'create' => Pages\CreateModelVehicle::route('/create'),
            'view' => Pages\ViewModelVehicle::route('/{record}'),
            'edit' => Pages\EditModelVehicle::route('/{record}/edit'),
        ];
    }
}
