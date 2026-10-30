<?php

namespace App\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use App\Support\VehicleCategories;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tautkan SATU kombinasi pending (stats NULL-link) ke katalog dalam satu
 * transaksi: resolve/create brand → model → type (kebijakan 2026-10-30:
 * boleh membuat katalog baru otomatis), upsert master CONNECTING, lalu
 * back-link seluruh baris stats yang raw-nya sama (pola query
 * vehicle-mapping relink). Dipakai halaman "Impor Langsung Penjualan".
 */
class VehicleSalesPendingLinker
{
    public function __construct(
        protected VehicleSalesMatcher $matcher,
        protected VehicleConnectingSyncService $sync,
    ) {}

    /**
     * @param  array{brand: string, model: string, type: string, powertrain: ?string, category: ?string, size: ?string}  $form
     * @return array{brand_created: bool, model_created: bool, type_created: bool, stats_linked: int}
     *
     * @throws RuntimeException bila brand/model/kategori kosong atau tidak valid
     */
    public function link(string $rawBrand, string $rawModel, array $form): array
    {
        $brandName = trim((string) ($form['brand'] ?? ''));
        $modelName = trim((string) ($form['model'] ?? ''));
        $typeName = trim((string) ($form['type'] ?? ''));
        $powertrain = strtoupper(trim((string) ($form['powertrain'] ?? '')));
        $powertrain = $powertrain !== '' ? $powertrain : null;

        if ($brandName === '' || $modelName === '') {
            throw new RuntimeException('Brand dan Model wajib terisi.');
        }

        $category = VehicleCategories::normalizeCategory($form['category'] ?? null);

        if ($category === null || ! in_array($category, VehicleCategories::CATEGORIES, true)) {
            throw new RuntimeException('Kategori wajib dipilih (nilai valid: '.implode(', ', VehicleCategories::CATEGORIES).').');
        }

        // SIZE hanya bermakna untuk kategori ber-ukuran — kombinasi lain
        // diabaikan diam-diam (konsisten dgn importer hierarki).
        $size = in_array($category, VehicleCategories::SIZABLE, true)
            ? VehicleCategories::normalizeSize($form['size'] ?? null)
            : null;

        $result = DB::transaction(function () use ($rawBrand, $rawModel, $brandName, $modelName, $typeName, $powertrain, $category, $size): array {
            // 1) Brand — nama kanonik (alias, mis. MORRIS GARAGE → MG),
            //    case-insensitive — supaya ejaan mentah tidak menduplikasi
            //    brand katalog (pola applyToCatalog).
            $canonKey = $this->matcher->normalize($this->matcher->canonicalBrandName($brandName));

            $brand = BrandVehicle::query()->get()
                ->first(fn (BrandVehicle $b) => $this->matcher->normalize($this->matcher->canonicalBrandName($b->name)) === $canonKey);

            $brandCreated = false;

            if ($brand === null) {
                $brand = BrandVehicle::query()->create(['name' => $brandName]);
                $brandCreated = true;
            }

            // 2) Model keluarga di bawah brand.
            $model = ModelVehicle::query()
                ->where('brand_vehicle_id', $brand->id)
                ->get()
                ->first(fn (ModelVehicle $m) => $this->matcher->normalize($m->name) === $this->matcher->normalize($modelName));

            $modelCreated = false;

            if ($model === null) {
                $model = ModelVehicle::query()->create([
                    'name' => $modelName,
                    'brand_vehicle_id' => $brand->id,
                    'category' => $category,
                    'size_class' => $size,
                ]);
                $modelCreated = true;
            } else {
                // Klasifikasi hanya diisi bila masih kosong — tidak menimpa
                // keputusan kurasi admin sebelumnya.
                $dirty = false;

                foreach (['category' => $category, 'size_class' => $size] as $field => $value) {
                    if ($value !== null && $model->{$field} === null) {
                        $model->{$field} = $value;
                        $dirty = true;
                    }
                }

                if ($dirty) {
                    $model->save();
                }
            }

            // 3) Type varian penuh (type_charger JSON NOT NULL → []).
            $type = null;
            $typeCreated = false;

            if ($typeName !== '') {
                $type = TypeVehicle::query()
                    ->where('model_vehicle_id', $model->id)
                    ->get()
                    ->first(fn (TypeVehicle $t) => mb_strtolower(trim((string) $t->name)) === mb_strtolower($typeName));

                if ($type === null) {
                    $type = TypeVehicle::query()->create([
                        'name' => $typeName,
                        'model_vehicle_id' => $model->id,
                        'type_charger' => [],
                        'powertrain' => $powertrain,
                    ]);
                    $typeCreated = true;
                } elseif ($type->powertrain === null && $powertrain !== null) {
                    $type->powertrain = $powertrain;
                    $type->save();
                }
            }

            // 4) Master CONNECTING — upsert by key; hook model menurunkan
            //    raw_gabungan_key dari raw_gabungan secara konsisten.
            $gabungan = trim((string) preg_replace('/\s+/', ' ', trim($rawBrand).' '.trim($rawModel)));

            VehicleConnecting::query()->updateOrCreate(
                [
                    'raw_gabungan_key' => (string) preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($gabungan)),
                ],
                [
                    'raw_gabungan' => $gabungan,
                    'brand_name' => $brandName,
                    'model_name' => $modelName,
                    'type_name' => $typeName !== '' ? $typeName : null,
                    'brand_vehicle_id' => $brand->id,
                    'model_vehicle_id' => $model->id,
                    'type_vehicle_id' => $type?->id,
                    'powertrain' => $powertrain,
                    'category' => $category,
                    'size_class' => $size,
                ],
            );

            // 5) Back-link seluruh stats pending yang raw-nya sama — exact
            //    atau compact tanpa spasi (pola vehicle-mapping relink).
            $compact = mb_strtoupper((string) preg_replace('/\s+/', '', trim($rawModel)));

            $statsLinked = VehicleSalesStat::query()
                ->whereNull('model_vehicle_id')
                ->whereRaw('UPPER(TRIM(raw_brand)) = ?', [mb_strtoupper(trim($rawBrand))])
                ->where(function ($q) use ($rawModel, $compact) {
                    $q->whereRaw('UPPER(TRIM(raw_model)) = ?', [mb_strtoupper(trim($rawModel))])
                        ->orWhereRaw('UPPER(REPLACE(TRIM(raw_model), \' \', \'\')) = ?', [$compact]);
                })
                ->update([
                    'brand_vehicle_id' => $brand->id,
                    'model_vehicle_id' => $model->id,
                    'type_vehicle_id' => $type?->id,
                    'powertrain' => $powertrain,
                ]);

            return [
                'brand_created' => $brandCreated,
                'model_created' => $modelCreated,
                'type_created' => $typeCreated,
                'stats_linked' => $statsLinked,
            ];
        });

        $this->sync->flushMarketCache();

        return $result;
    }
}
