<?php

namespace App\Console\Commands;

use App\Http\Resources\SpkluLocationResource;
use App\Services\VehicleMarketService;
use App\Support\SpkluServing;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ExportAppSeed extends Command
{
    protected $signature = 'app:export-seed {--out=storage/app/seed}';

    protected $description = 'Generate SPKLU + Pasar EV seed JSON files untuk bundling di mobile app.';

    public function handle(VehicleMarketService $market): int
    {
        $outDir = $this->option('out');

        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $this->info('Generating SPKLU seed...');

        try {
            $spkluResult = $this->exportSpklu($outDir);
        } catch (\Throwable $e) {
            $this->error('SPKLU export failed: '.$e->getMessage());

            return 1;
        }

        $this->info('Generating Market seed...');

        try {
            $marketResult = $this->exportMarket($outDir, $market);
        } catch (\Throwable $e) {
            $this->error('Market export failed: '.$e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('Seed generation complete.');
        $this->table(
            ['File', 'Count', 'Size'],
            array_merge($spkluResult, $marketResult)
        );

        return 0;
    }

    /**
     * Export SPKLU seed — query identik API tanpa filter user, transform via
     * SpkluLocationResource dengan header X-Coord-Codec: v1 agar loc terenkode.
     */
    private function exportSpklu(string $outDir): array
    {
        $perPage = 500;
        $page = 1;
        $allLocations = [];

        // Hitung marker SEBELUM paginasi (persis seperti controller).
        $markers = SpkluServing::computeSyncMarkers();
        $dataVersion = SpkluServing::computeDataVersion();

        // Loop paginasi untuk akumulasi seluruh data.
        do {
            $paginator = SpkluServing::baseQuery()
                ->paginate($perPage, ['*'], 'page', $page);

            // Transform via SpkluLocationResource — header palsu diperlukan
            // agar shouldEncode() return true → loc terenkode identik payload API.
            $fakeRequest = Request::create(
                '/api/v1/spklu',
                'GET',
                [],
                [],
                [],
                ['HTTP_X_COORD_CODEC' => 'v1']
            );

            $transformed = SpkluLocationResource::collection($paginator)
                ->additional([
                    'status' => 'success',
                    'meta' => array_merge($markers, ['data_version' => $dataVersion]),
                ])
                ->toResponse($fakeRequest)
                ->getData(true);

            $allLocations = array_merge($allLocations, $transformed['data'] ?? []);

            $page++;
        } while ($paginator->hasMorePages());

        $generatedAt = now('UTC')->toIso8601String();

        // Validasi keras: marker count (dihitung dengan filter koordinat yang
        // sama dengan baseQuery) HARUS == jumlah baris yang diekspor. Selisih
        // berarti pipeline tidak konsisten — seed yang basi/terpotong akan
        // memicu full fetch permanen di klien (delta-below-count).
        if (count($allLocations) !== $markers['count']) {
            throw new \RuntimeException(sprintf(
                'Seed tidak konsisten: marker count=%d tapi baris terekspor=%d. Periksa filter koordinat serving.',
                $markers['count'],
                count($allLocations)
            ));
        }

        $envelope = [
            'meta' => [
                'schema_version' => 2,
                'count' => $markers['count'],
                'max_created_at' => $markers['max_created_at'],
                'max_updated_at' => $markers['max_updated_at'],
                'generated_at' => $generatedAt,
            ],
            'locations' => $allLocations,
        ];

        // json_encode default sudah minified (tanpa spasi); kedua flag tetap
        // dipakai agar teks Indonesia tidak jadi escape \uXXXX yang membengkak.
        $minified = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = rtrim($outDir, '/').'/spklu_seed.json';

        file_put_contents($path, $minified);

        $count = count($allLocations);
        $size = number_format(strlen($minified));
        $this->info("  SPKLU: {$count} stations, {$size} bytes → {$path}");

        return [['spklu_seed.json', $count, $size.' bytes']];
    }

    /**
     * Export Pasar EV seed — lima seksi agregat + meta via VehicleMarketService.
     * Service dipanggil langsung tanpa auth/request dependency.
     */
    private function exportMarket(string $outDir, VehicleMarketService $market): array
    {
        $meta = $market->meta();

        // Each section is independently wrapped — a failure in one section
        // (e.g. empty DB) does not prevent the rest from being exported.
        $summary = $this->safeCall(fn () => $market->summary(), 'summary');
        $trend = $this->safeCall(fn () => $market->trend(null), 'trend');
        $top = $this->safeCall(fn () => $market->top(null, 'BEV', null, 10), 'top');
        $catalog = $this->safeCall(fn () => $market->catalog(null), 'catalog');
        $composition = $this->safeCall(fn () => $market->categoryComposition(null, 'ALL'), 'composition');

        $generatedAt = now('UTC')->toIso8601String();

        $envelope = [
            'meta' => [
                'last_import_at' => $meta['last_import_at'] ?? null,
                'latest_import_id' => $meta['latest_import_id'] ?? null,
                'data_version' => $meta['data_version'] ?? null,
                'latest_year' => $meta['latest_year'] ?? null,
                'latest_month' => $meta['latest_month'] ?? null,
                'generated_at' => $generatedAt,
            ],
            'sections' => [
                'summary' => $summary,
                'trend' => $trend,
                'top' => $top,
                'catalog' => $catalog,
                'composition' => $composition,
            ],
        ];

        $minified = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = rtrim($outDir, '/').'/market_seed.json';

        file_put_contents($path, $minified);

        $size = number_format(strlen($minified));
        $year = $meta['latest_year'] ?? '?';
        $this->info("  Market: year={$year}, {$size} bytes → {$path}");

        return [['market_seed.json', "year={$year}", $size.' bytes']];
    }

    /**
     * Run a market service call, returning null on failure.
     */
    private function safeCall(callable $fn, string $section): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->warn("  ⚠ {$section}: {$e->getMessage()}");

            return null;
        }
    }
}
