<?php

namespace App\Filament\Resources\Panel;

use App\Filament\Forms\BaseSelect;
use App\Filament\Forms\ImageFileUpload;
use App\Models\ChargingStation;
use App\Support\SpkluServing;
use Filament\Tables;
use Filament\Actions;
use Filament\Schemas\Schema;
use App\Models\Provider;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\Panel\ProviderResource\Pages;
use App\Filament\Resources\Panel\ProviderResource\RelationManagers;
use App\Tables\Columns\StatusActiveColumn;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Toggle;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 1;

    // protected static ?string $cluster = Providers::class;
    protected static string | \UnitEnum | null $navigationGroup = 'Provider & SPKLU';




    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-building-office';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Provider & SPKLU';
    }

    public static function getModelLabel(): string
    {
        return __('crud.providers.itemTitle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('crud.providers.collectionTitle');
    }

    public static function getNavigationLabel(): string
    {
        return __('crud.providers.collectionTitle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Group::make()->schema([
                Section::make()
                    ->schema(static::getContactFormSchema()),

                Section::make()
                    ->schema(static::getCostFormSchema()),

            ])->columnSpan(['lg' => 1]),

            Group::make()->schema([
                Section::make()
                    ->schema(static::getAppsFormSchema()),

                Section::make()
                    ->schema(static::getAddressFormSchema()),

            ])->columnSpan(['lg' => 1]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(function (Builder $query) {
                // Kolom "Lokasi" menghitung dari tabel KANONIK charging_stations
                // dengan komposisi serving yang sama dengan app (ProviderController)
                // — bukan jumlah referensi lintas tabel. SPKLU yang sama memang
                // ada di master (pln_charger_locations), kanonik, dan legacy
                // (spklu_locations); dijumlahkan langsung, titik yang sama
                // terhitung 2–3 kali (mis. PLN Mobile tampak 7.719).
                $hitung = SpkluServing::applyComposition(ChargingStation::query())
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->selectRaw('provider_id, COUNT(*) as total')
                    ->groupBy('provider_id');

                return $query
                    ->selectRaw('providers.*, COALESCE(lokasi_hitung.total, 0) as lokasi_total')
                    ->leftJoinSub($hitung, 'lokasi_hitung', 'lokasi_hitung.provider_id', '=', 'providers.id');
            })
            ->columns([
                // Read the raw DB path instead of the model accessor, which
                // returns a web URL ("/storage/..."). The disk existence check
                // in ImageColumn only matches a relative disk path, so it would
                // otherwise always fail and every row would fall back to the
                // default placeholder image.
                ImageColumn::make('image')
                    ->getStateUsing(fn ($record) => $record->getRawOriginal('image'))
                    ->disk('public')
                    ->defaultImageUrl(fn () => asset('/images/ev-charging.png')),

                TextColumn::make('name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn ($record) => $record->contact ?: $record->email),

                TextColumn::make('lokasi_total')
                    ->label('Lokasi')
                    ->badge()
                    ->color(fn ($state) => ((int) $state) === 0 ? 'danger' : 'success')
                    ->sortable(),

                StatusActiveColumn::make('status')
                    ->visible(fn() => auth()->user() instanceof \App\Models\User && auth()->user()->hasRole('super_admin')),

                Tables\Columns\IconColumn::make('public')
                    ->label('Publik')
                    ->boolean(),

                TextColumn::make('price')
                    ->label('Tarif')
                    ->formatStateUsing(function ($record) {
                        $parts = [];
                        foreach (['price' => '', 'admin_fee' => 'admin ', 'tax' => 'pajak '] as $field => $label) {
                            $val = trim((string) ($record->{$field} ?? ''));
                            if ($val === '') {
                                continue;
                            }
                            // Nilai di DB sering sudah berformat ("Rp 2.467 /kWh",
                            // "free", "2% - 8%") — hanya angka murni yang diberi awalan Rp.
                            if (is_numeric($val)) {
                                $val = 'Rp '.number_format((float) $val, 0, ',', '.');
                            }
                            $parts[] = $label.$val;
                        }

                        return $parts === [] ? '—' : implode(' · ', $parts);
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        '1' => 'Aktif',
                        '2' => 'Nonaktif',
                    ])
                    ->query(function (Builder $query, array $data) {
                        // Kunci state form SelectFilter adalah 'value', bukan
                        // nama filter — salah kunci membuat filter tak pernah
                        // jalan. "Nonaktif" memakai != 1 agar menangkap juga
                        // nilai legacy 0 di produksi (command versi lama).
                        return match ($data['value'] ?? null) {
                            '1' => $query->where('providers.status', 1),
                            '2' => $query->where('providers.status', '!=', 1),
                            default => $query,
                        };
                    }),

                // Tanpa ->query(): Filament otomatis menerapkan
                // where(providers.public, value) — lihat SelectFilter::apply().
                SelectFilter::make('public')
                    ->label('Publik')
                    ->options([
                        '1' => 'Publik',
                        '0' => 'Non-publik',
                    ]),

                Filter::make('tanpa_lokasi')
                    ->label('Tanpa lokasi (0 terhubung)')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->havingRaw('lokasi_total = 0')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Actions\EditAction::make(),
                    Actions\ViewAction::make(),
                ])
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('nonaktifkan')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->modalDescription('Set status provider terpilih menjadi nonaktif (status=2)?')
                        ->action(fn ($records) => DB::table('providers')->whereIn('id', $records->pluck('id'))->update(['status' => 2])),

                    Actions\DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalDescription('Lokasi yang masih terhubung akan dilepas (provider_id dikosongkan). Provider yang masih dipakai lokasi milik pengguna dilewati, bukan dihapus.')
                        ->using(function (Actions\DeleteBulkAction $action, $records) {
                            $diblokir = [];
                            $dihapus = 0;

                            foreach ($records as $record) {
                                // charger_locations (data pengguna) punya ON DELETE
                                // CASCADE — jangan biarkan hapus provider menghapus
                                // data pengguna; lewati provider ini saja.
                                $dipakai = DB::table('charger_locations')->where('provider_id', $record->id)->count();
                                if ($dipakai > 0) {
                                    $diblokir[] = "{$record->name} ({$dipakai} lokasi milik pengguna)";

                                    continue;
                                }

                                // Lepas referensi master/kanonik — kolomnya nullable.
                                DB::table('spklu_locations')->where('provider_id', $record->id)->update(['provider_id' => null]);
                                DB::table('charging_stations')->where('provider_id', $record->id)->update(['provider_id' => null]);
                                DB::table('pln_charger_locations')->where('provider_id', $record->id)->update(['provider_id' => null]);
                                DB::table('spklu_scrape_raw')->where('guessed_provider_id', $record->id)->update(['guessed_provider_id' => null]);

                                $record->delete();
                                $dihapus++;
                            }

                            if ($diblokir !== []) {
                                Notification::make()
                                    ->title("{$dihapus} provider dihapus, ".count($diblokir).' dilewati')
                                    ->body('Masih dipakai lokasi milik pengguna: '.implode(', ', $diblokir))
                                    ->warning()
                                    ->send();
                            } elseif ($dihapus > 0) {
                                Notification::make()
                                    ->title("{$dihapus} provider dihapus")
                                    ->success()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->defaultSort('lokasi_total', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'create' => Pages\CreateProvider::route('/create'),
            'view' => Pages\ViewProvider::route('/{record}'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }

    public static function getAddressFormSchema(): array
    {
        return [
            Grid::make(['default' => 1])->schema([
                TextInput::make('address')
                    ->nullable()
                    ->string(),

                BaseSelect::make('province_id')
                    ->required()
                    ->relationship('province', 'name')
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('city_id', null);
                        $set('district_id', null);
                        $set('subdistrict_id', null);
                        $set('postal_code_id', null);
                    }),

                Select::make('city_id')
                    ->required()
                    ->relationship('city', 'name')
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $provinceId = $get('province_id');
                        return \App\Models\City::where('province_id', $provinceId)->pluck('name', 'id');
                    })
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('district_id', null);
                        $set('subdistrict_id', null);
                        $set('postal_code_id', null);
                    }),

                Select::make('district_id')
                    ->nullable()
                    ->relationship('district', 'name')
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $cityId = $get('city_id');
                        return \App\Models\District::where('city_id', $cityId)->pluck('name', 'id');
                    })
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('subdistrict_id', null);
                        $set('postal_code_id', null);
                    }),

                Select::make('subdistrict_id')
                    ->nullable()
                    ->relationship('subdistrict', 'name')
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $districtId = $get('district_id');
                        return \App\Models\Subdistrict::where('district_id', $districtId)->pluck('name', 'id');
                    })
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('postal_code_id', null);
                    }),

                Select::make('postal_code_id')
                    ->nullable()
                    ->relationship('postalCode', 'name')
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $provinceId = $get('province_id');
                        $cityId = $get('city_id');
                        $districtId = $get('district_id');
                        $subdistrictId = $get('subdistrict_id');
                        return \App\Models\PostalCode::where('province_id', $provinceId)
                            ->where('city_id', $cityId)
                            ->where('district_id', $districtId)
                            ->where('subdistrict_id', $subdistrictId)
                            ->pluck('name', 'id');
                    }),
            ])
        ];
    }

    public static function getCostFormSchema(): array
    {
        return [
            Grid::make(['default' => 1])->schema([
                TextInput::make('price'),

                TextInput::make('admin_fee'),

                TextInput::make('tax'),
            ])
        ];
    }

    public static function getContactFormSchema(): array
    {
        return [
            Grid::make(['default' => 1])->schema([
                ImageFileUpload::make('image')
                    ->directory('images/provider'),

                TextInput::make('name')
                    ->required()
                    ->string(),

                TextInput::make('contact')
                    ->nullable()
                    ->string(),

                TextInput::make('email')
                    ->nullable()
                    ->email(),

                Select::make('status')
                    ->required()
                    ->searchable()
                    ->options([
                        '1' => 'active',
                        '2' => 'inactive',
                    ]),

                Toggle::make('public')
                    ->default(true)
                    ->inlineLabel(),
            ])
        ];
    }

    public static function getAppsFormSchema(): array
    {
        return [
            Grid::make(['default' => 1])->schema([
                TextInput::make('web')
                    ->nullable()
                    ->url(),

                TextInput::make('google')
                    ->nullable()
                    ->url(),

                TextInput::make('ios')
                    ->nullable()
                    ->url(),
            ])
        ];
    }
}
