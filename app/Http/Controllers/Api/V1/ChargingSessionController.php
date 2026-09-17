<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ChargingSessionResource;
use App\Models\Battery;
use App\Models\Charge;
use App\Models\ChargingStation;
use App\Models\FuelPrice;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD sesi charging (Charge) untuk mobile SPKLU — pengganti logbook yang
 * dihapus. Mendukung dua sumber lokasi: charging_stations (mobile) atau
 * charger_locations (legacy Filament). Vehicle opsional; field pengukuran
 * opsional untuk input cepat di lapangan.
 */
class ChargingSessionController extends Controller
{
    /**
     * List sesi milik user terautentikasi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Charge::where('charges.user_id', Auth::id())
            ->with(['vehicle.typeVehicle', 'vehicle.modelVehicle', 'battery', 'chargingStation.provider', 'chargerLocation', 'chargerLocation.provider', 'charger.typeCharger']);

        if ($request->filled('charging_station_id')) {
            $query->where('charging_station_id', $request->charging_station_id);
        }
        if ($request->filled('charger_location_id')) {
            $query->where('charger_location_id', $request->charger_location_id);
        }
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('type_vehicle_id')) {
            $typeId = $request->type_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($typeId) {
                $sub->where('type_vehicle_id', $typeId);
            });
        }
        if ($request->filled('model_vehicle_id')) {
            $modelId = $request->model_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($modelId) {
                $sub->where('model_vehicle_id', $modelId)
                    ->orWhereHas('typeVehicle', function ($typeSub) use ($modelId) {
                        $typeSub->where('model_vehicle_id', $modelId);
                    });
            });
        }
        if ($request->filled('charging_type')) {
            $this->applyChargingTypeScope($query, strtoupper($request->charging_type));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        // paginate() utk performa (hindari load all ratusan sesi sekaligus),
        // lalu reshape ke kontrak mobile: `data:[...]` array murni + info
        // pagination di `meta`. Paginator bawaan Laravel membungkus data jadi
        // `{data, links, meta}` yang gagal deserialize di mobile.
        $perPage = (int) ($request->per_page ?? 20);
        $perPage = max(1, min(100, $perPage));
        $paginator = $query->orderByDesc('date')->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Charging sessions retrieved successfully',
            'data' => ChargingSessionResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Buat sesi charging baru. Vehicle & field pengukuran opsional — input
     * cepat mobile hanya butuh minimal: date, salah satu lokasi, dan (biasanya)
     * kWh + total_cost.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateSession($request, true);

        // Auto-fill data "sebelum" dari sesi terakhir kendaraan yg sama —
        // supaya perhitungan turunan (km trip, delta battery → losses,
        // Rp/km, kWh/km) konsisten tanpa user mengingat/mengisi field tsb.
        // `km_before` ← sesi terakhir `km_now`; `finish_charging_before` ←
        // sesi terakhir `finish_charging_now`. Override input mobile (field
        // tsb sudah tidak ada di form).
        if (empty($validated['vehicle_id'])) {
            $firstVehicle = Auth::user()?->vehicles()->first();
            if ($firstVehicle) {
                $validated['vehicle_id'] = $firstVehicle->id;
            }
        }

        if (! empty($validated['vehicle_id'])) {
            $previous = $this->latestForVehicle($validated['vehicle_id']);
            $vehicle = Vehicle::find($validated['vehicle_id']);
            $validated['km_before'] = $previous?->km_now ?? $vehicle?->initial_odometer;
            $validated['finish_charging_before'] = $previous?->finish_charging_now;
        }

        // Enforce: kendaraan yang punya sesi berjalan (is_finish_charging = false)
        // tidak bisa mendapat sesi baru apa pun (cepat/lengkap) sampai sesi itu
        // diselesaikan.
        if (! empty($validated['vehicle_id'])) {
            $existingUnfinished = Charge::where('user_id', Auth::id())
                ->where('vehicle_id', $validated['vehicle_id'])
                ->where('is_finish_charging', false)
                ->exists();
            if ($existingUnfinished) {
                return response()->json([
                    'success' => false,
                    'message' => 'Masih ada sesi berjalan untuk kendaraan ini. Selesaikan sesi tersebut dulu.',
                ], 409);
            }
        }

        // Auto-assign baterai aktif kendaraan bila user tidak memilih baterai —
        // sesi charging menempel ke baterai yg sedang terpasang. Baterai baru
        // hasil swap otomatis dipakai utk sesi berikutnya tanpa interaksi user.
        if (! empty($validated['vehicle_id']) && empty($validated['battery_id'])) {
            $validated['battery_id'] = $this->activeBatteryForVehicle($validated['vehicle_id'])?->id;
        }

        // Lokasi custom/home TIDAK di-create inline lagi. Alur 2-langkah:
        // mobile POST /my/charging-locations dulu → dapat charger_location_id →
        // kirim di sini. Session tanpa lokasi tetap valid (riwayat vehicle-only).
        $charge = Charge::create(array_merge(
            ['user_id' => Auth::id()],
            $validated,
            $this->stationSnapshot($request),
        ));

        $charge->load(['vehicle', 'battery', 'chargingStation', 'chargerLocation']);

        return response()->json([
            'success' => true,
            'message' => 'Charging session created successfully',
            'data' => new ChargingSessionResource($charge),
        ], 201);
    }

    public function show(Request $request, Charge $chargingSession): JsonResponse
    {
        if (! $this->owns($chargingSession)) {
            return $this->forbidden();
        }
        $chargingSession->load(['vehicle', 'battery', 'chargingStation', 'chargerLocation']);

        return response()->json([
            'success' => true,
            'message' => 'Charging session retrieved successfully',
            'data' => new ChargingSessionResource($chargingSession),
        ]);
    }

    public function update(Request $request, Charge $chargingSession): JsonResponse
    {
        if (! $this->owns($chargingSession)) {
            return $this->forbidden();
        }
        $validated = $this->validateSession($request, requireOwnership: true, partial: true);

        $data = $validated;
        // Re-snapshot bila charging_station_id berubah (termasuk dihapus).
        if (array_key_exists('charging_station_id', $validated) || array_key_exists('station_name', $validated)) {
            $data = array_merge($data, $this->stationSnapshot($request));
        }

        // Enforce: bila update mengubah sesi menjadi berjalan, pastikan tidak ada
        // sesi berjalan lain utk kendaraan yg sama (mengedit sesi berjalan itu
        // sendiri tetap boleh — perubahan is_finish_charging bisa apa adanya).
        $wouldBeUnfinished = array_key_exists('is_finish_charging', $data)
            ? $data['is_finish_charging'] === false
            : $chargingSession->is_finish_charging === false;
        if ($wouldBeUnfinished && $chargingSession->vehicle_id) {
            $otherUnfinished = Charge::where('user_id', Auth::id())
                ->where('vehicle_id', $chargingSession->vehicle_id)
                ->where('is_finish_charging', false)
                ->where('id', '!=', $chargingSession->id)
                ->exists();
            if ($otherUnfinished) {
                return response()->json([
                    'success' => false,
                    'message' => 'Masih ada sesi berjalan lain untuk kendaraan ini. Selesaikan sesi tersebut dulu.',
                ], 409);
            }
        }

        $chargingSession->update($data);
        $chargingSession->load(['vehicle', 'battery', 'chargingStation', 'chargerLocation']);

        return response()->json([
            'success' => true,
            'message' => 'Charging session updated successfully',
            'data' => new ChargingSessionResource($chargingSession),
        ]);
    }

    public function destroy(Request $request, Charge $chargingSession): JsonResponse
    {
        if (! $this->owns($chargingSession)) {
            return $this->forbidden();
        }
        $chargingSession->delete();

        return response()->json([
            'success' => true,
            'message' => 'Charging session deleted successfully',
        ]);
    }

    /**
     * Sesi charging terakhir milik user untuk kendaraan tertentu. Kini dipakai
     * internal utk auto-fill `km_before`/`finish_charging_before` di store();
     * tetap diekspos sebagai endpoint utk kompatibilitas (mis. debug/admin).
     */
    public function latest(Request $request): JsonResponse
    {
        $request->validate(['vehicle_id' => 'required']);

        $session = $this->latestForVehicle($request->vehicle_id)?->load(['vehicle', 'battery', 'chargingStation', 'chargerLocation']);

        return response()->json([
            'success' => true,
            'message' => 'Latest charging session retrieved successfully',
            'data' => $session ? new ChargingSessionResource($session) : null,
        ]);
    }

    /**
     * Query sesi terakhir milik user utk kendaraan tertentu (shared oleh
     * endpoint latest() dan store() auto-fill). Ordered by date lalu
     * created_at agar sesi hari sama urut konsisten.
     */
    private function latestForVehicle(string $vehicleId): ?Charge
    {
        return Charge::where('charges.user_id', Auth::id())
            ->where('vehicle_id', $vehicleId)
            ->latest('date')
            ->latest('created_at')
            ->first();
    }

    /**
     * Baterai aktif terbaru milik kendaraan user (auto-assign battery_id).
     * Caller wajib sudah memastikan vehicle milik user.
     */
    private function activeBatteryForVehicle(string $vehicleId): ?Battery
    {
        return Battery::activeForVehicle($vehicleId);
    }

    /**
     * Peta perjalanan charging user: seluruh lokasi tempat pernah charging
     * (diagregasi per stasiun/lokasi) + seluruh sesi urut kronologis utk
     * digambar sebagai polyline perjalanan di mobile. Filter sama dgn index().
     *
     * Response:
     *  - locations: agregasi per lokasi (nama, koordinat, provider, jumlah
     *    sesi, total kWh, total biaya, kunjungan pertama/terakhir).
     *  - sessions:  semua sesi urut tanggal (id, date, location_key, koordinat,
     *    kWh, biaya) agar client bisa menggambar rute perjalanan + interaksi.
     */
    public function journey(Request $request): JsonResponse
    {
        $query = Charge::where('charges.user_id', Auth::id())
            ->with(['chargerLocation', 'chargerLocation.provider', 'vehicle']);

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('type_vehicle_id')) {
            $typeId = $request->type_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($typeId) {
                $sub->where('type_vehicle_id', $typeId);
            });
        }
        if ($request->filled('model_vehicle_id')) {
            $modelId = $request->model_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($modelId) {
                $sub->where('model_vehicle_id', $modelId)
                    ->orWhereHas('typeVehicle', function ($typeSub) use ($modelId) {
                        $typeSub->where('model_vehicle_id', $modelId);
                    });
            });
        }
        if ($request->filled('charging_type')) {
            $this->applyChargingTypeScope($query, strtoupper($request->charging_type));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        $sessions = $query->orderBy('date')->orderBy('created_at')->get();

        // Resolve nama/koordinat/provider via resource supaya persis dgn list —
        // satu sumber kebenaran utk nilai yang sama di dua visualisasi.
        $dtos = ChargingSessionResource::collection($sessions)->resolve();

        $locations = [];
        $journeySessions = [];

        foreach ($dtos as $dto) {
            if ($dto['charging_station_id'] !== null) {
                $key = 's'.$dto['charging_station_id'];
            } elseif ($dto['charger_location_id'] !== null) {
                $key = 'l'.$dto['charger_location_id'];
            } else {
                $key = 'm'.md5((string) ($dto['station_name'] ?? $dto['id']));
            }

            $journeySessions[] = [
                'id' => $dto['id'],
                'date' => $dto['date'],
                'location_key' => $key,
                'latitude' => $dto['station_latitude'],
                'longitude' => $dto['station_longitude'],
                'kwh' => $dto['kwh'],
                'total_cost' => $dto['total_cost'],
            ];

            $loc = $locations[$key] ?? [
                'key' => $key,
                // Raw ID utk dedup/dedup-ulang di picker create-session mobile:
                // charging_station_id (link live ke PLN) atau charger_location_id
                // (lokasi custom/home milik user). is_home_charging utk badge
                // sumber lokasi (Custom vs Home).
                'charging_station_id' => $dto['charging_station_id'],
                'charger_location_id' => $dto['charger_location_id'],
                'is_home_charging' => (bool) $dto['is_home_charging'],
                'name' => $dto['station_name'],
                'address' => $dto['station_address'],
                'latitude' => $dto['station_latitude'],
                'longitude' => $dto['station_longitude'],
                'provider' => $dto['station_provider'],
                'total_sessions' => 0,
                'total_kwh' => 0.0,
                'total_cost' => 0.0,
                'first_visit' => $dto['date'],
                'last_visit' => $dto['date'],
            ];
            $loc['total_sessions']++;
            $loc['total_kwh'] += (float) ($dto['kwh'] ?? 0);
            $loc['total_cost'] += (float) ($dto['total_cost'] ?? 0);
            // Jaga first/last visit saat urut naik (sudah dijamin order by date,
            // tapi defensif kalau date null).
            if ($dto['date'] !== null) {
                if ($loc['first_visit'] === null || $dto['date'] < $loc['first_visit']) {
                    $loc['first_visit'] = $dto['date'];
                }
                if ($loc['last_visit'] === null || $dto['date'] > $loc['last_visit']) {
                    $loc['last_visit'] = $dto['date'];
                }
            }
            $loc['total_kwh'] = round($loc['total_kwh'], 2);
            $loc['total_cost'] = round($loc['total_cost'], 0);
            $locations[$key] = $loc;
        }

        $locations = array_values($locations);
        usort($locations, fn ($a, $b) => $b['total_sessions'] <=> $a['total_sessions']);

        return response()->json([
            'success' => true,
            'message' => 'Charging journey retrieved successfully',
            'data' => [
                'total_locations' => count($locations),
                'total_sessions' => count($journeySessions),
                'locations' => $locations,
                'sessions' => $journeySessions,
            ],
        ]);
    }

    /**
     * Analytics summary for the mobile dashboard — total sessions, kWh, cost,
     * distance, efficiency (kWh/100km, cost/km), and savings vs BBM.
     *
     * The BBM estimate uses the historical `fuel_prices` table per session
     * date (not a constant): per-session km × fuel price effective at that
     * time. Consumption is calibrated via `bbm_km_per_liter` (default 12) so
     * the mobile app can send the user's interactive value.
     */
    public function analytics(Request $request): JsonResponse
    {
        $query = Charge::where('charges.user_id', Auth::id());
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('type_vehicle_id')) {
            $typeId = $request->type_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($typeId) {
                $sub->where('type_vehicle_id', $typeId);
            });
        }
        if ($request->filled('model_vehicle_id')) {
            $modelId = $request->model_vehicle_id;
            $query->whereHas('vehicle', function ($sub) use ($modelId) {
                $sub->where('model_vehicle_id', $modelId)
                    ->orWhereHas('typeVehicle', function ($typeSub) use ($modelId) {
                        $typeSub->where('model_vehicle_id', $modelId);
                    });
            });
        }
        if ($request->filled('charging_type')) {
            $this->applyChargingTypeScope($query, strtoupper($request->charging_type));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        $sessions = (clone $query)->get([
            'id', 'date', 'kWh', 'total_cost', 'km_before', 'km_now',
            'start_charging_now', 'finish_charging_now', 'finish_charging_before',
        ]);

        $totalSessions = $sessions->count();

        // Total kWh/cost tetap mentah (ditampilkan sbg nilai absolut), sementara
        // metrik efisiensi pakai energi/biaya yang diatribusikan ke jarak sesi.
        $totalKwh = 0.0;
        $totalCost = 0.0;
        $attributedKwh = 0.0;
        $attributedCost = 0.0;
        $kmNowSum = 0.0;
        $kmBeforeSum = 0.0;
        foreach ($sessions as $session) {
            $totalKwh += (float) ($session->kWh ?? 0);
            $totalCost += (float) ($session->total_cost ?? 0);
            $attr = $this->attributedEnergy($session);
            $attributedKwh += $attr['kwh'];
            $attributedCost += $attr['cost'];
            $kmNowSum += (float) ($session->km_now ?? 0);
            $kmBeforeSum += (float) ($session->km_before ?? 0);
        }
        $totalKm = max($kmNowSum - $kmBeforeSum, 0);

        // Per-session fallback when km_before is 0 or not filled
        if ($totalKm <= 0) {
            $kmSessions = $sessions->filter(fn ($s) => ($s->km_now ?? 0) > 0);
            if ($kmSessions->count() >= 2) {
                $minKm = $kmSessions->min('km_now');
                $maxKm = $kmSessions->max('km_now');
                $totalKm = (float) max($maxKm - $minKm, 0);
            } elseif ($kmSessions->count() == 1) {
                $first = $kmSessions->first();
                $totalKm = (float) max(($first->km_now - ($first->km_before ?? 0)), 0);
            }
        }

        // Efficiency & cost — metrik pakai energi/biaya yang diatribusikan ke
        // jarak sesi (atribusi), bukan kWh mentah, agar akurat saat sesi
        // finish != batas pengisian sesi sebelumnya (rasio = 1 saat sama).
        $kwhPer100km = $totalKm > 0 ? ($attributedKwh / $totalKm) * 100 : 0;
        $kmPerKwh = $attributedKwh > 0 ? $totalKm / $attributedKwh : 0;
        $costPerKm = $totalKm > 0 ? $attributedCost / $totalKm : 0;
        $costPerKwh = $attributedKwh > 0 ? $attributedCost / $attributedKwh : 0;

        // BBM estimate based on historical prices per session date.
        $kmPerLiter = max((float) ($request->input('bbm_km_per_liter', 12)), 0.1);
        $bbm = $this->estimateBbmCost($query, $kmPerLiter);
        $estimatedBbmCost = $bbm['cost'];
        $totalSavings = max($estimatedBbmCost - $totalCost, 0);

        return response()->json([
            'success' => true,
            'message' => 'Analytics retrieved successfully',
            'data' => [
                'total_sessions' => $totalSessions,
                'total_energy_kwh' => round($totalKwh, 2),
                'total_cost' => round($totalCost, 0),
                'total_distance_km' => round($totalKm, 1),
                'cost_per_kwh' => round($costPerKwh, 0),
                'cost_per_km' => round($costPerKm, 0),
                'kwh_per_100km' => round($kwhPer100km, 2),
                'km_per_kwh' => round($kmPerKwh, 2),
                'estimated_bbm_cost' => round($estimatedBbmCost, 0),
                'total_savings' => round($totalSavings, 0),
                'bbm_km_per_liter' => $kmPerLiter,
                'bbm_km_used' => round($bbm['km'], 1),
            ],
        ]);
    }

    /**
     * Energi & biaya yang diatribusikan ke jarak sesi ini (port helper KMP
     * `ChargingSessionFilterHelper.attributedEnergy`).
     *
     * Sesi mengisi baterai dari `start_charging_now` → `finish_charging_now` (%).
     * Perjalanan sesi ini menghabiskan baterai dari `finish_charging_before`
     * (batas akhir sesi sebelumnya) → `start_charging_now`. Maka energi yang
     * dipakai utk jarak = kWh × (finish_charging_before − start_charging_now)
     * ÷ (finish_charging_now − start_charging_now). Saat finish ==
     * finish_charging_before → rasio 1 (perilaku lama). Fallback ke kWh mentah /
     * biaya penuh bila battery% kosong atau denominator ≤ 0.
     */
    private function attributedEnergy($session): array
    {
        $kwh = (float) ($session->kWh ?? 0);
        $cost = (float) ($session->total_cost ?? 0);
        $start = $session->start_charging_now;
        $finish = $session->finish_charging_now;
        $finishBefore = $session->finish_charging_before;
        if ($start === null || $finish === null || $finishBefore === null) {
            return ['kwh' => $kwh, 'cost' => $cost];
        }
        $denominator = (float) $finish - (float) $start;
        if ($denominator <= 0) {
            return ['kwh' => $kwh, 'cost' => $cost];
        }
        $ratio = max(0.0, ((float) $finishBefore - (float) $start) / $denominator);
        return ['kwh' => $kwh * $ratio, 'cost' => $cost * $ratio];
    }

    /**
     * BBM cost estimate for the same session query as `analytics`. Reads each
     * session (date, km_before, km_now), computes the per-session trip km
     * (max(km_now - km_before, 0)), then multiplies by the fuel price
     * effective on the session date (fuel_prices). Returns the total cost and
     * the km used for the estimate.
     */
    private function estimateBbmCost($query, float $kmPerLiter): array
    {
        $sessions = (clone $query)
            ->whereNotNull('km_now')
            ->where('km_now', '>', 0)
            ->get(['id', 'date', 'km_before', 'km_now']);

        $schedule = FuelPrice::orderBy('effective_date')
            ->get(['effective_date', 'price_per_liter'])
            ->map(fn ($p) => [
                'effective_date' => $p->effective_date?->toDateString(),
                'price_per_liter' => (float) $p->price_per_liter,
            ])
            ->all();

        $cost = 0.0;
        $usedKm = 0.0;

        foreach ($sessions as $session) {
            $km = max((float) $session->km_now - (float) ($session->km_before ?? 0), 0);
            if ($km <= 0) {
                continue;
            }
            $usedKm += $km;
            $cost += ($km / $kmPerLiter) * $this->fuelPriceAt($session->date, $schedule);
        }

        return ['cost' => $cost, 'km' => $usedKm];
    }

    /**
     * Fuel price effective on the session date: latest price with
     * effective_date <= date. Falls back to the oldest price; with no data at
     * all uses 13.700 (the last known Pertamax price).
     */
    private function fuelPriceAt(?string $date, array $schedule): float
    {
        if ($date === null) {
            return 13700.0;
        }
        $price = null;
        foreach ($schedule as $entry) {
            if ($entry['effective_date'] <= $date) {
                $price = $entry['price_per_liter'];
            } else {
                break;
            }
        }
        if ($price !== null) {
            return $price;
        }
        if ($schedule !== []) {
            return $schedule[0]['price_per_liter'];
        }

        return 13700.0;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function owns(Charge $charge): bool
    {
        return (string) $charge->user_id === (string) Auth::id();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access to charging session',
        ], 403);
    }

    /**
     * Filter AC/DC. Cascade sumber kebenaran dgn PRIORITAS EKSKLUSIF: setiap
     * tingkat fallback hanya berlaku bila tingkat yang lebih definitif TIDAK
     * tersedia untuk sesi itu — mencegah sumber lemah (snapshot nama) menarik
     * sesi yang sudah punya sumber definitif (mis. charger DC dgn nama AC).
     *
     *  0. station_chargerbox_type_snapshot — PRIMARY utk sesi mobile. User
     *     memilih charger box spesifik (mobile picker); type_charge charger
     *     box tsb lebih akurat daripada tipe stasiun campuran.
     *  1. charger.typeCharger.name (enum 'AC'/'DC') — PRIMARY utk input
     *     Filament admin (charger_id spesifik); Charger ber-relasi ke
     *     TypeCharger dgn name enum.
     *  2. chargingStation.type_charge / .chargers.type_charge canonical —
     *     fallback utk sesi mobile SPKLU (charging_station_id, tanpa charger_id).
     *     medium/standard = AC, fast/ultra_fast = DC.
     *  3. Snapshot nama — fallback terakhir utk sesi tanpa relasi apa pun.
     *
     * Keyakinan "sumber definitif ada" dipakai sebagai penutup fallback: bila
     * chargerbox snapshot / charger_id terisi, snapshot tidak dipakai lagi
     * (tidak ambigu).
     */
    private function applyChargingTypeScope($query, string $cType): void
    {
        $dcTokens = ['DC', 'FAST', 'ULTRA', 'CCS', 'CHADEMO', 'SUPERCHARGER',
            '50KW', '60KW', '100KW', '120KW', '150KW', '200KW'];
        $dcStationTypes = ['fast', 'ultra_fast', 'ultrafast', 'fastcharging', 'ultrafastcharging'];
        $acStationTypes = ['medium', 'standard', 'mediumcharging', 'slowcharging', 'slow', 'ac'];

        // TypeCharger.name di produksi = NAMA KONEKTOR (bukan enum AC/DC):
        //   DC: "CCS2", "Chademo", "DC GBT", dst. (prefix "DC" atau nama DC dikenal)
        //   AC: "Type 2", "AC GBT", dst. (prefix "AC" atau nama AC dikenal)
        $dcConnectors = function ($tc) {
            $tc->where(function ($w) {
                $w->where('name', 'LIKE', 'DC%')
                    ->orWhereIn('name', ['CCS2', 'CCS', 'Chademo', 'CHAdeMO', 'Supercharger']);
            });
        };
        $acConnectors = function ($tc) {
            $tc->where(function ($w) {
                $w->where('name', 'LIKE', 'AC%')
                    ->orWhereIn('name', ['Type 2', 'Type2', 'J1772', 'GB/T AC']);
            });
        };

        if ($cType === 'DC') {
            $query->where(function ($q) use ($dcConnectors, $dcTokens, $dcStationTypes) {
                // (0) Charger box spesifik terpilih user — paling akurat.
                $q->whereNotNull('station_chargerbox_type_snapshot')
                    ->whereIn('station_chargerbox_type_snapshot', $dcStationTypes)
                // (1)-(3) Fallback cascade — HANYA bila tanpa chargerbox snapshot
                //         (sumber (0) lebih definitif, tidak boleh di-override).
                    ->orWhere(function ($w) use ($dcConnectors, $dcTokens, $dcStationTypes) {
                        $w->whereNull('station_chargerbox_type_snapshot')
                            ->where(function ($q) use ($dcConnectors, $dcTokens, $dcStationTypes) {
                                // (1) Charger → TypeCharger (connector name) DC.
                                $q->whereHas('charger.typeCharger', $dcConnectors)
                                // (2) Tanpa charger_id, tapi dgn station canonical DC.
                                    ->orWhere(function ($s) use ($dcStationTypes) {
                                        $s->whereNull('charger_id')
                                            ->where(function ($ss) use ($dcStationTypes) {
                                                $ss->whereHas('chargingStation', fn ($st) => $st->whereIn('type_charge', $dcStationTypes))
                                                    ->orWhereHas('chargingStation.chargers', fn ($cq) => $cq->whereIn('type_charge', $dcStationTypes));
                                            });
                                    })
                                // (3) Tanpa charger_id & charging_station_id → snapshot mengandung token DC.
                                    ->orWhere(function ($s) use ($dcTokens) {
                                        $s->whereNull('charger_id')
                                            ->whereNull('charging_station_id')
                                            ->where(function ($snap) use ($dcTokens) {
                                                foreach ($dcTokens as $token) {
                                                    $snap->orWhere('station_name_snapshot', 'LIKE', "%{$token}%")
                                                        ->orWhere('station_provider_snapshot', 'LIKE', "%{$token}%");
                                                }
                                            });
                                    });
                            });
                    });
            });
        } elseif ($cType === 'AC') {
            $query->where(function ($q) use ($acConnectors, $dcTokens, $acStationTypes) {
                // (0) Charger box spesifik terpilih user — paling akurat.
                $q->whereNotNull('station_chargerbox_type_snapshot')
                    ->whereIn('station_chargerbox_type_snapshot', $acStationTypes)
                // (1)-(3) Fallback cascade — HANYA bila tanpa chargerbox snapshot.
                    ->orWhere(function ($w) use ($acConnectors, $dcTokens, $acStationTypes) {
                        $w->whereNull('station_chargerbox_type_snapshot')
                            ->where(function ($q) use ($acConnectors, $dcTokens, $acStationTypes) {
                                // (1) Charger → TypeCharger (connector name) AC.
                                $q->whereHas('charger.typeCharger', $acConnectors)
                                // (2) Tanpa charger_id, tapi dgn station canonical AC.
                                    ->orWhere(function ($s) use ($acStationTypes) {
                                        $s->whereNull('charger_id')
                                            ->where(function ($ss) use ($acStationTypes) {
                                                $ss->whereHas('chargingStation', fn ($st) => $st->whereIn('type_charge', $acStationTypes))
                                                    ->orWhereHas('chargingStation.chargers', fn ($cq) => $cq->whereIn('type_charge', $acStationTypes));
                                            });
                                    })
                                // (3) Tanpa charger_id & charging_station_id → snapshot bersih token DC.
                                //     NULL-safe: (col IS NULL OR col NOT LIKE token) per kolom/token.
                                    ->orWhere(function ($s) use ($dcTokens) {
                                        $s->whereNull('charger_id')
                                            ->whereNull('charging_station_id')
                                            ->where(function ($c) use ($dcTokens) {
                                                foreach ($dcTokens as $token) {
                                                    $c->where(function ($cc) use ($token) {
                                                        $cc->whereNull('station_name_snapshot')
                                                            ->orWhere('station_name_snapshot', 'NOT LIKE', "%{$token}%");
                                                    })->where(function ($cc) use ($token) {
                                                        $cc->whereNull('station_provider_snapshot')
                                                            ->orWhere('station_provider_snapshot', 'NOT LIKE', "%{$token}%");
                                                    });
                                                }
                                            });
                                    });
                            });
                    });
            });
        }
    }

    /**
     * Validasi sesi. `partial=true` (update) pakai aturan `sometimes`.
     * Vehicle opsional: jika diisi, cek ownership. Field pengukuran opsional
     * (diisi default 0/null oleh model saat tidak dikirim).
     */
    private function validateSession(Request $request, bool $requireOwnership, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes|' : '';
        $rules = [
            'vehicle_id' => [$partial ? 'sometimes' : null, 'nullable', function ($attr, $value, $fail) use ($requireOwnership) {
                if ($requireOwnership && filled($value)) {
                    $owns = Auth::user()->vehicles()->where('vehicles.id', $value)->exists();
                    if (! $owns) {
                        $fail('Unauthorized access to vehicle.');
                    }
                }
            }],
            'battery_id' => [$partial ? 'sometimes' : null, 'nullable', function ($attr, $value, $fail) use ($requireOwnership) {
                if ($requireOwnership && filled($value)) {
                    $battery = Battery::where('user_id', Auth::id())->find($value);
                    if (! $battery) {
                        $fail('Unauthorized access to battery.');
                    }
                }
            }],
            'charging_station_id' => $sometimes.'nullable|integer',
            'charger_location_id' => $sometimes.'nullable|uuid',
            'charger_id' => $sometimes.'nullable|uuid',
            'station_name' => $sometimes.'nullable|string|max:255',
            'station_address' => $sometimes.'nullable|string|max:500',
            'station_provider' => $sometimes.'nullable|string|max:255',
            'station_chargerbox_id' => $sometimes.'nullable|string|max:255',
            'station_chargerbox_name' => $sometimes.'nullable|string|max:255',
            'station_chargerbox_type' => $sometimes.'nullable|string|max:100',
            'date' => $sometimes.'nullable|date',
            'km_before' => $sometimes.'nullable|numeric|min:0',
            'km_now' => $sometimes.'nullable|numeric|min:0',
            'start_charging_now' => $sometimes.'nullable|numeric|min:0|max:100',
            'finish_charging_now' => $sometimes.'nullable|numeric|min:0|max:100',
            'finish_charging_before' => $sometimes.'nullable|numeric|min:0|max:100',
            'is_finish_charging' => $sometimes.'boolean',
            'kwh' => $sometimes.'nullable|numeric|min:0',
            'is_kwh_measured' => $sometimes.'boolean',
            'meter_before' => $sometimes.'nullable|numeric|min:0',
            'tariff_id' => $sometimes.'nullable|string|max:100',
            'parking' => $sometimes.'nullable|numeric|min:0',
            'street_lighting_tax' => $sometimes.'nullable|numeric|min:0',
            'value_added_tax' => $sometimes.'nullable|numeric|min:0',
            'admin_cost' => $sometimes.'nullable|numeric|min:0',
            'total_cost' => $sometimes.'nullable|numeric|min:0',
        ];

        $validated = $request->validate($rules);

        // Map mobile field `kwh` → kolom legacy `kWh`.
        if (array_key_exists('kwh', $validated)) {
            $validated['kWh'] = $validated['kwh'];
            unset($validated['kwh']);
        }

        // Map custom station fields → snapshot columns jika diinput manual
        if (! empty($validated['station_name'])) {
            $validated['station_name_snapshot'] = $validated['station_name'];
            unset($validated['station_name']);
        }
        if (! empty($validated['station_address'])) {
            $validated['station_address_snapshot'] = $validated['station_address'];
            unset($validated['station_address']);
        }
        if (! empty($validated['station_provider'])) {
            $validated['station_provider_snapshot'] = $validated['station_provider'];
            unset($validated['station_provider']);
        }

        // Map charger box terpilih user → snapshot columns. Prioritas utama
        // utk filter AC/DC per-sesi (mobile picker — mengalahkan tipe station).
        foreach ([
            'station_chargerbox_id' => 'station_chargerbox_id_snapshot',
            'station_chargerbox_name' => 'station_chargerbox_name_snapshot',
            'station_chargerbox_type' => 'station_chargerbox_type_snapshot',
        ] as $input => $col) {
            if (! empty($validated[$input])) {
                $validated[$col] = $validated[$input];
                unset($validated[$input]);
            }
        }

        return $validated;
    }

    /**
     * Snapshot denormalized dari charging_stations saat sesi dibuat/diupdate.
     * Bila charging_station_id diberikan & station ditemukan, snapshot mengisi
     * station_*; jika tidak, gunakan snapshot manual jika ada.
     */
    private function stationSnapshot(Request $request): array
    {
        $stationId = $request->input('charging_station_id');
        if ($stationId) {
            $station = ChargingStation::find($stationId);
            if ($station) {
                return [
                    'station_name_snapshot' => $station->nama_lokasi,
                    'station_address_snapshot' => $station->alamat,
                    'station_lat_snapshot' => $station->latitude,
                    'station_lng_snapshot' => $station->longitude,
                    'station_provider_snapshot' => $station->provider_name,
                ];
            }
        }

        return [
            'station_name_snapshot' => $request->input('station_name'),
            'station_address_snapshot' => $request->input('station_address'),
            'station_lat_snapshot' => null,
            'station_lng_snapshot' => null,
            'station_provider_snapshot' => $request->input('station_provider'),
        ];
    }
}
