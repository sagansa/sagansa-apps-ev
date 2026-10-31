<?php

namespace App\Services;

use App\Models\OcmSyncState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Sinkronisasi POI OpenChargeMap per negara langsung dari API v3 — port
 * tools/fetch_openchargemap.py ke Laravel agar bisa di-schedule & multi-negara.
 *
 * Perilaku penting yang dipertahankan dari tool Python:
 *  - paginasi offset+maxresults sampai batch terakhir < maxresults;
 *  - backoff eksponensial saat HTTP 429, offset yang sama diulang (bukan skip);
 *  - deteksi halaman duplikat: API diam-diam mengabaikan `offset` utk query
 *    tertentu (ES deep-window) sehingga halaman sama diulang terus — berhenti
 *    begitu batch tidak membawa ID baru.
 *
 * Incremental via `modifiedsince` dibaca/ditulis ke tabel ocm_sync_state;
 * state hanya dicatat saat sync sukses (gagal → tetap pakai timestamp lama,
 * sync berikutnya mengulang window yang sama — aman krn upsert idempoten).
 */
class OcmSyncService
{
    public function __construct(
        private readonly float $pageDelaySec = 1.5,
        private readonly int $backoffInitialSec = 30,
        private readonly int $backoffMaxSec = 300,
    ) {}

    /**
     * Sync satu negara (ISO-2, case-insensitive).
     *
     * @param  callable|null  $onPage  fn(array{page:int, offset:int, batch:int, total:int}): void
     * @return array{fetched:int, inserted:int, updated:int, pages:int, mode:string, stopped_reason:string|null, error:string|null}
     */
    public function syncCountry(string $country, bool $full = false, int $maxResults = 1000, ?callable $onPage = null): array
    {
        $key = config('services.openchargemap.key');
        if (empty($key)) {
            return $this->blank(error: 'OCM_API_KEY belum diisi (config services.openchargemap.key)');
        }

        $country = strtoupper(trim($country));
        $baseUrl = rtrim((string) config('services.openchargemap.base_url'), '/');

        // Incremental: modifiedsince dari state sync sukses terakhir.
        $state = OcmSyncState::query()->where('country', $country)->first();
        $modifiedSince = null;
        if (! $full && $state?->last_sync_utc !== null) {
            $modifiedSince = $state->last_sync_utc->copy()
                ->setTimezone('UTC')
                ->format('Y-m-d\TH:i:s').'Z';
        }

        $stats = $this->blank(mode: $modifiedSince !== null ? 'incremental' : 'penuh');
        $harvester = app(OcmHarvestService::class);

        $seenIds = [];
        $offset = 0;
        $page = 0;
        $backoff = $this->backoffInitialSec;
        $syncStartUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');

        while (true) {
            $params = [
                'key' => $key,
                'output' => 'json',
                'countrycode' => $country,
                'maxresults' => $maxResults,
                'offset' => $offset,
                'compact' => 'true',
                'verbose' => 'false',
                'includecomments' => 'false',
            ];
            if ($modifiedSince !== null) {
                $params['modifiedsince'] = $modifiedSince;
            }

            $response = Http::timeout(90)
                ->withHeaders(['User-Agent' => 'ev-app-data-sync/1.0'])
                ->get($baseUrl, $params);

            if ($response->status() === 429) {
                $this->sleep($backoff);
                // Naikkan backoff utk 429 berikutnya (0 = mode test tanpa sleep).
                $backoff = $this->backoffMaxSec > 0
                    ? min($backoff * 2, $this->backoffMaxSec)
                    : 0;
                continue; // offset sama diulang setelah backoff
            }

            if (! $response->successful()) {
                $stats['error'] = 'API request failed (HTTP '.$response->status().')';

                return $stats;
            }

            $batch = $response->json();
            if (! is_array($batch)) {
                $stats['error'] = 'Invalid response (bukan array JSON)';

                return $stats;
            }

            $stats['pages']++;
            $stats['fetched'] += count($batch);

            foreach ($batch as $point) {
                if ($harvester->upsertPoi($point, $country)) {
                    $stats['inserted']++;
                } else {
                    $stats['updated']++;
                }
            }

            if ($onPage !== null) {
                $onPage([
                    'page' => $page,
                    'offset' => $offset,
                    'batch' => count($batch),
                    'total' => $stats['fetched'],
                ]);
            }

            // Pengaman bug offset OCM: batch tanpa satu pun ID baru → API
            // mengulang halaman yang sama; berhenti daripada loop selamanya.
            $batchIds = array_map(fn ($p) => (int) ($p['ID'] ?? $p['id'] ?? $p['external_id'] ?? 0), $batch);
            $newIds = array_values(array_diff($batchIds, $seenIds));
            if ($page > 0 && $batch !== [] && $newIds === []) {
                $stats['stopped_reason'] = 'duplicate_page';

                break;
            }
            $seenIds = array_values(array_unique(array_merge($seenIds, $newIds)));

            if (count($batch) < $maxResults) {
                break; // halaman terakhir
            }

            $offset += $maxResults;
            $page++;
            $this->sleep($this->pageDelaySec);
        }

        OcmSyncState::query()->updateOrCreate(
            ['country' => $country],
            [
                'last_sync_utc' => $syncStartUtc,
                'poi_count' => $stats['fetched'],
                'mode' => $stats['mode'],
            ]
        );

        return $stats;
    }

    /** Tidur tanpa memblokir bila durasi 0 (mode test). */
    private function sleep(int|float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @return array{fetched:int, inserted:int, updated:int, pages:int, mode:string, stopped_reason:string|null, error:string|null}
     */
    private function blank(string $mode = 'penuh', ?string $error = null): array
    {
        return [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'pages' => 0,
            'mode' => $mode,
            'stopped_reason' => null,
            'error' => $error,
        ];
    }
}
