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
use Filament\Infolists\Components\RepeatableEntry;
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
use Illuminate\Support\HtmlString;

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

            // Feedback owner 2026-11-24: detail model dulu tanpa info profil OBD
            // — admin tak bisa melihat profil mana yang tertaut, apalagi apakah
            // model ini model utama atau hanya pivot dari profil multi-model.
            Section::make('Profil OBD')
                ->description('Profil yang tertaut ke model ini. App versi lama hanya mengenali "model utama"; model lain dalam satu profil multi-model dikenali lewat daftar pivot.')
                ->schema([
                    RepeatableEntry::make('obdVehicleProfiles')
                        ->hiddenLabel()
                        ->state(fn (ModelVehicle $record) => $record->obdVehicleProfiles()->orderBy('platform')->get())
                        ->schema([
                            TextEntry::make('platform')
                                ->label('Platform')
                                ->badge()
                                ->color('info')
                                ->url(fn (ObdVehicleProfile $record) => ObdVehicleProfileResource::getUrl('edit', ['record' => $record]))
                                ->openUrlInNewTab(),
                            TextEntry::make('metadata.model_name')
                                ->label('Nama sumber katalog')
                                ->placeholder('—'),
                            TextEntry::make('grade')->label('Grade')->badge(),
                            TextEntry::make('version')->label('Versi'),
                            TextEntry::make('jumlah_metrik')
                                ->label('Jumlah metrik')
                                ->state(fn (ObdVehicleProfile $record) => count($record->pid_list['metrics'] ?? [])),
                            TextEntry::make('peran')
                                ->label('Peran untuk model ini')
                                ->badge()
                                ->color(fn (string $state) => $state === 'model utama' ? 'success' : 'gray')
                                ->state(fn (ObdVehicleProfile $record, $livewire) => self::obdRole($record, $livewire->getRecord())),
                        ])
                        ->columns(3),
                    TextEntry::make('obd_tanpa_profil')
                        ->hiddenLabel()
                        ->placeholder('Belum ada profil OBD tertaut — gunakan aksi "Pilih Profil OBD".')
                        ->visible(fn (ModelVehicle $record) => $record->obdVehicleProfiles()->doesntExist()),
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
                // berapa di antaranya seed resmi (grade official). Profilnya
                // di-eager-load untuk deskripsi kolom "Profil OBD".
                ->withCount('obdVehicleProfiles')
                ->with('obdVehicleProfiles')
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
                    // Di bawah hitungan: profil mana yang tertaut (feedback
                    // owner 2026-11-24 — "1" saja tidak memberi informasi).
                    ->description(fn (ModelVehicle $record) => self::obdProfileLinksDescription($record))
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

    /**
     * Peran sebuah model dalam satu profil: `model_vehicle_id` profil = model
     * UTAMA (satu-satunya yang dikenali app versi lama); link pivot lain =
     * model PIVOT (dikenali app lewat `model_vehicle_ids`).
     */
    public static function obdRole(ObdVehicleProfile $profile, ModelVehicle $model): string
    {
        return (int) $profile->model_vehicle_id === (int) $model->id ? 'model utama' : 'model pivot';
    }

    /**
     * Satu baris per profil OBD yang tertaut ke model — bahan deskripsi kolom
     * "Profil OBD" di daftar (nama sumber dipotong agar baris tetap ringkas).
     *
     * @return list<string>
     */
    public static function obdProfileLinkLines(ModelVehicle $model): array
    {
        return $model->obdVehicleProfiles
            ->sortBy('platform')
            ->map(fn (ObdVehicleProfile $profile) => sprintf(
                '%s · %s [%s, %d metrik, %s]',
                (string) $profile->platform,
                mb_strimwidth((string) ($profile->metadata['model_name'] ?? '—'), 0, 60, '…'),
                (string) $profile->grade,
                count($profile->pid_list['metrics'] ?? []),
                self::obdRole($profile, $model),
            ))
            ->all();
    }

    /** Deskripsi kolom daftar: baris profil tertaut (null bila tidak ada). */
    public static function obdProfileLinksDescription(ModelVehicle $model): ?HtmlString
    {
        $lines = self::obdProfileLinkLines($model);

        return $lines === []
            ? null
            : new HtmlString(implode('<br>', array_map(fn (string $line) => e($line), $lines)));
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
                    ->getSearchResultsUsing(function (string $search) {
                        // Token-based AND: setiap kata harus ada (urutan bebas)
                        // — "mini dolphin" menemukan "Dolphin Mini", "cs 3172"
                        // menemukan platform CS-3172d0cd. Token 1 huruf
                        // diabaikan (LIKE '%1%' mencocokkan hampir semua baris).
                        // WAJIB LOWER() di kedua sisi: kolom json di MySQL 8
                        // memakai collation utf8mb4_bin (case-sensitive) —
                        // LIKE '%seagull%' tidak pernah cocok dgn "Seagull".
                        $tokens = array_values(array_filter(
                            preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                            fn (string $t) => mb_strlen($t) >= 2,
                        ));
                        if ($tokens === []) {
                            return [];
                        }

                        return ObdVehicleProfile::query()
                            ->with('modelVehicle.brandVehicle')
                            ->where(function ($q) use ($tokens) {
                                $q->where(function ($q1) use ($tokens) {
                                    foreach ($tokens as $t) {
                                        $q1->whereRaw('LOWER(platform) LIKE ?', ["%{$t}%"]);
                                    }
                                })->orWhere(function ($q2) use ($tokens) {
                                    foreach ($tokens as $t) {
                                        $q2->whereRaw('LOWER(CAST(pid_list AS CHAR)) LIKE ?', ["%{$t}%"]);
                                    }
                                });
                            })
                            ->orderBy('platform')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (ObdVehicleProfile $p) => [$p->id => self::profileOptionLabel($p)])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => self::profileOptionLabel(
                        ObdVehicleProfile::query()->with('modelVehicle.brandVehicle')->find($value),
                    ))
                    // WAJIB (bentuk jamak) untuk Select multiple lazy-search:
                    // validasi opsi terpilih saat submit — tanpa ini Filament
                    // melempar LogicException "did not have an [options()] or
                    // [getOptionLabelsUsing()] configuration".
                    ->getOptionLabelsUsing(fn (array $values): array => ObdVehicleProfile::query()
                        ->with('modelVehicle.brandVehicle')
                        ->whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(fn (ObdVehicleProfile $p) => [$p->id => self::profileOptionLabel($p)])
                        ->all()),
            ])
            ->databaseTransaction()
            ->action(function (array $data, ModelVehicle $record) {
                // Many-to-many: model ini ditautkan ke profil terpilih (link
                // pivot; profil tetap menaut model lain yang sudah ada).
                $profiles = ObdVehicleProfile::query()
                    ->whereIn('id', $data['profile_ids'] ?? [])
                    ->get();
                $profiles->each(function (ObdVehicleProfile $profile) use ($record) {
                    $profile->modelVehicles()->syncWithoutDetaching([$record->id]);
                    $profile->refreshPrimaryModel();
                });

                Notification::make()
                    ->title("{$profiles->count()} profil OBD di-assign ke {$record->brandVehicle?->name} {$record->name}")
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
