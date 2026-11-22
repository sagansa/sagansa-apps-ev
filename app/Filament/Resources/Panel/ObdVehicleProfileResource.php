<?php

namespace App\Filament\Resources\Panel;

use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages;
use App\Models\BrandVehicle;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;

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
                    Select::make('brand_vehicle_id')
                        ->label('Brand Vehicle')
                        // Field pembantu (form-only) — hanya model_vehicle_id
                        // yang tersimpan di tabel.
                        ->dehydrated(false)
                        ->options(fn () => BrandVehicle::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('model_vehicle_id', null))
                        ->afterStateHydrated(function (Select $component, ?ObdVehicleProfile $record) {
                            if ($record?->modelVehicle?->brand_vehicle_id) {
                                $component->state($record->modelVehicle->brand_vehicle_id);
                            }
                        }),

                    Select::make('model_vehicle_id')
                        ->label('Model Vehicle')
                        ->options(fn ($get) => ModelVehicle::query()
                            ->when($get('brand_vehicle_id'), fn ($q, $brandId) => $q->where('brand_vehicle_id', $brandId))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->nullable(),

                    Select::make('platform')
                        ->label('Platform')
                        // Platform adalah kunci upsert seeder: profil CS-* hasil
                        // seed katalog Car Scanner tidak boleh berubah dari form
                        // edit. Karena Select men-generate rule validasi `in`
                        // dari opsi, nilai CS-* yang tidak ada di daftar statis
                        // akan ditolak ("The selected Platform is invalid") dan
                        // baris katalog tak bisa di-save. Untuk itu opsi ditutup
                        // dengan platform record saat ini, field dinonaktifkan,
                        // dan tidak didehydrasi untuk profil CS-*
                        // (model_vehicle_id / years tetap bisa diedit — itu
                        // tugas admin pemetaan).
                        ->options(function (?ObdVehicleProfile $record): array {
                            $options = [
                                'E-GMP' => 'E-GMP',
                                'BEV' => 'BEV',
                                'PHEV' => 'PHEV',
                                'HEV' => 'HEV',
                                'ICE' => 'ICE',
                                'universal' => 'Universal',
                            ];

                            if ($record !== null && str_starts_with((string) $record->platform, 'CS-')) {
                                $options[$record->platform] = $record->platform;
                            }

                            return $options;
                        })
                        ->disabled(fn (?ObdVehicleProfile $record) => $record !== null && str_starts_with((string) $record->platform, 'CS-'))
                        // Jaminan ganda: meski state platform dimanipulasi dari
                        // client, nilai CS-* tidak pernah ditulis balik ke DB —
                        // baris katalog bisa hilang dari seeder bila platform
                        // berubah.
                        ->dehydrated(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
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
                // Informasi read-only pengganti repeater untuk profil CS-*:
                // admin tetap melihat jumlah metrik tanpa bisa merusak kontrak
                // JSON pid_list.
                Placeholder::make('catalog_pid_info')
                    ->label('PID Katalog Car Scanner')
                    ->content(function (?ObdVehicleProfile $record): string {
                        $metricCount = count($record->pid_list['metrics'] ?? []);

                        return "{$metricCount} metrik dari katalog Car Scanner (read-only — diubah via generator)";
                    })
                    ->visible(fn (?ObdVehicleProfile $record) => $record !== null && str_starts_with((string) $record->platform, 'CS-')),

                // pid_list untuk profil CS-* adalah kontrak JSON utuh mobile
                // (metrics/platform/modelName/...), bukan array item repeater —
                // dan seeder meng-upsert berdasarkan platform CS-*. Repeater
                // akan meng-hydrate JSON tersebut sebagai item sampah; bila
                // admin menghapusnya lalu save, pid_list tertimpa dan metrik
                // hilang, dan re-seed berikutnya membuat baris CS-* duplikat.
                // Untuk profil CS-* repeater disembunyikan total (bukan sekadar
                // disabled): hydrate kontrak menghasilkan item kosong yang
                // memenuhi form dan mengesankan data hilang (laporan admin
                // 2026-11-22); editing PID hanya untuk profil manual.
                Repeater::make('pid_list')
                    ->label('Supported PIDs')
                    ->visible(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
                    ->disabled(fn (?ObdVehicleProfile $record) => $record !== null && str_starts_with((string) $record->platform, 'CS-'))
                    ->dehydrated(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
                    ->schema([
                        Grid::make(['default' => 4])->schema([
                            // Child field wajib isi hanya untuk profil manual —
                            // hydrate JSON katalog CS-* menghasilkan item yang
                            // tidak punya kolom-kolom ini; required statis akan
                            // menggagalkan save profil CS-*
                            // (required di-nonaktifkan, repeater read-only).
                            TextInput::make('mode')
                                ->label('Mode')
                                ->numeric()
                                ->required(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
                                ->columnSpan(1),

                            TextInput::make('pid')
                                ->label('PID')
                                ->numeric()
                                ->required(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
                                ->columnSpan(1),

                            TextInput::make('description')
                                ->label('Description')
                                ->required(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-'))
                                ->columnSpan(2),
                        ]),
                        Grid::make(['default' => 3])->schema([
                            TextInput::make('formula')
                                ->label('Formula')
                                ->default('A')
                                ->required(fn (?ObdVehicleProfile $record) => $record === null || ! str_starts_with((string) $record->platform, 'CS-')),

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
                // Kolom di-cast array di model; tanpa transform ini textarea
                // menampilkan "[object Object]" dan bisa menghapus isi saat save
                // (laporan admin 2026-11-22). Form bekerja dengan string JSON:
                // hydrate → pretty-print, dehydrate → decode balik ke array.
                Forms\Components\Textarea::make('metadata')
                    ->label('Metadata (JSON)')
                    ->rows(4)
                    ->placeholder('{"notes": "..."}')
                    ->rules(['json'])
                    ->afterStateHydrated(function (Forms\Components\Textarea $component, $state) {
                        if (is_array($state)) {
                            $component->state(json_encode(
                                $state,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ));
                        }
                    })
                    ->dehydrateStateUsing(fn ($state) => is_array($state)
                        ? $state
                        : (trim((string) $state) === '' ? null : json_decode($state, true))),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('modelVehicle.brandVehicle'))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('modelVehicle.brandVehicle.name')
                    ->label('Brand')
                    // Kolom ini HANYA katalog kendaraan existing (FK
                    // model_vehicle_id) — bukan string brand dari metadata
                    // Car Scanner. Identitas katalog Car Scanner (brand +
                    // nama) tampil di kolom Catalog Name. Mapping = keputusan
                    // admin; mobile hanya menyambung via model_vehicle_id.
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('modelVehicle.name')
                    ->label('Model')
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('catalog_name')
                    ->label('Catalog Name')
                    // Nama dari JSON katalog ("Katalog CS xxx" bila generik) —
                    // identitas profil sebelum di-map admin. Brand katalog
                    // Car Scanner ditampilkan sebagai description (teks kecil
                    // di bawah nama) — INDIKASI saja, bukan linkage ke
                    // BrandVehicle existing.
                    ->state(fn (ObdVehicleProfile $record) => $record->metadata['model_name'] ?? null)
                    ->description(fn (ObdVehicleProfile $record) => $record->metadata['brand'] ?? null)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('metric_count')
                    ->label('Metrics')
                    // pid_list = JSON profil utuh (cast array) — tanpa query tambahan.
                    ->state(fn (ObdVehicleProfile $record) => count($record->pid_list['metrics'] ?? [])),
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
            ->filters([
                TernaryFilter::make('mapped')
                    ->label('Ter-Map')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('model_vehicle_id'),
                        false: fn (Builder $query) => $query->whereNull('model_vehicle_id'),
                    ),
                // Pemisah seed resmi (IONIQ 5, Wuling Air EV — grade official)
                // dari ±1.600 profil katalog Car Scanner (grade community).
                SelectFilter::make('grade')
                    ->label('Grade')
                    ->options([
                        'official' => 'Official',
                        'community' => 'Community',
                        'experimental' => 'Experimental',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    // Kasus nyata: satu mobil punya beberapa hash profil katalog
                    // (beda sensor/ECU) → assign sekaligus ke satu model katalog.
                    BulkAction::make('assignToModel')
                        ->label('Assign to Model Vehicle')
                        ->icon('heroicon-o-link')
                        ->color('success')
                        // Assign massal menyentuh banyak baris sekaligus —
                        // bungkus dalam transaksi agar kegagalan di tengah
                        // jalan tidak meninggalkan mapping setengah jadi.
                        ->databaseTransaction()
                        // Spesifikasi: form bulk menampilkan jumlah record
                        // terpilih sebelum admin mengonfirmasi.
                        ->modalDescription(fn (Collection $records) => "Assign {$records->count()} profil ke satu model katalog.")
                        ->form([
                            Select::make('brand_vehicle_id')
                                ->label('Brand Vehicle')
                                ->options(fn () => BrandVehicle::orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($set) => $set('model_vehicle_id', null)),
                            Select::make('model_vehicle_id')
                                ->label('Model Vehicle')
                                ->options(fn ($get) => ModelVehicle::query()
                                    ->when($get('brand_vehicle_id'), fn ($q, $brandId) => $q->where('brand_vehicle_id', $brandId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each(
                            fn (ObdVehicleProfile $record) => $record->update([
                                'model_vehicle_id' => $data['model_vehicle_id'],
                            ]),
                        ))
                        ->deselectRecordsAfterCompletion(),
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
            'index' => Pages\ListObdProfiles::route('/'),
            'create' => Pages\CreateObdProfile::route('/create'),
            'edit' => Pages\EditObdProfile::route('/{record}/edit'),
        ];
    }
}
