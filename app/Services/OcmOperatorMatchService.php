<?php

namespace App\Services;

use App\Models\OcmOperatorMatch;
use App\Models\OpenchargemapPoi;
use App\Models\Provider;

/**
 * Entity-resolution operator OpenChargeMap → providers.
 *
 * Operator OCM adalah string bebas + ID stabil (OperatorInfo.ID) — tidak
 * mungkin dikelola manual via Filament utk cakupan worldwide. Strategi:
 *
 *  1. kumpulkan operator unik yang benar-benar muncul di openchargemap_pois
 *     (raw OperatorInfo.ID+Title; jalur import file lama hanya punya judul);
 *  2. auto-match via ScrapeDedupService::guessProvider (token match — mesin
 *     yang sama dgn ESDM/PLN) → match_status 'auto', match_method 'guess';
 *  3. tanpa padanan → auto-provision provider minimal (name + status aktif,
 *     tanpa logo — UI mobile nanti pakai fallback inisial) → match_method
 *     'auto_provision';
 *  4. noise ("(Unknown Operator)", dst) diabaikan.
 *
 * Baris bisa di-review manual di Filament (match_status 'manual'/'pending').
 */
class OcmOperatorMatchService
{
    /** Judul operator yang tidak bermakna di OCM — jangan di-provision. */
    private const NOISE_TITLE_PATTERNS = ['unknown', 'unspecified', 'n/a', 'imported'];

    /**
     * Impor operator dari POI yang tersimpan + resolve provider.
     *
     * @return array{scanned: int, matched: int, provisioned: int, skipped_noise: int, skipped_existing: int, backfilled_operator: int, reference_error: string|null}
     */
    public function importFromPois(bool $refreshReferenceData = false): array
    {
        $guesser = new ScrapeDedupService;
        $reference = app(OcmReferenceDataService::class);

        // Respons compact hanya punya OperatorID int — judulnya dari katalog
        // referencedata. Snapshot di-refresh bila basi/hilang/dipaksa.
        $referenceResult = $reference->ensureFresh($refreshReferenceData);
        $operatorTitles = $reference->map(OcmReferenceDataService::SCOPE_OPERATORS);

        $stats = [
            'scanned' => 0,
            'matched' => 0,
            'provisioned' => 0,
            'skipped_noise' => 0,
            'skipped_existing' => 0,
            'backfilled_operator' => 0,
            'reference_error' => $referenceResult['error'],
        ];

        ['operators' => $operators, 'noise' => $noise] = $this->collectOperators($operatorTitles);
        $stats['skipped_noise'] = $noise;

        foreach ($operators as $operator) {
            $stats['scanned']++;

            // Sudah pernah di-resolve → idempoten (re-run tidak duplikat).
            $exists = OcmOperatorMatch::query()
                ->when($operator['id'] > 0, fn ($q) => $q->where('ocm_operator_id', $operator['id']))
                ->when($operator['id'] === 0, fn ($q) => $q->whereNull('ocm_operator_id')
                    ->whereRaw('LOWER(operator_title) = ?', [strtolower($operator['title'])]))
                ->whereNotNull('provider_id')
                ->exists();
            if ($exists) {
                $stats['skipped_existing']++;

                continue;
            }

            $provider = $guesser->guessProvider($operator['title']);
            $method = 'guess';

            if ($provider === null) {
                $provider = Provider::create([
                    'name' => $operator['title'],
                    'status' => 1,
                ]);
                $method = 'auto_provision';
                $stats['provisioned']++;
            } else {
                $stats['matched']++;
            }

            OcmOperatorMatch::query()->updateOrCreate(
                $operator['id'] > 0
                    ? ['ocm_operator_id' => $operator['id']]
                    : ['ocm_operator_id' => null, 'operator_title' => $operator['title']],
                [
                    'operator_title' => $operator['title'],
                    'provider_id' => $provider->id,
                    'match_status' => 'auto',
                    'match_method' => $method,
                ]
            );
        }

        $stats['backfilled_operator'] = $this->backfillPoiOperators($operatorTitles);

        return $stats;
    }

    /**
     * Isi kolom openchargemap_pois.operator yang masih NULL dari katalog —
     * POI jalur compact sync tidak membawa judul operator sama sekali,
     * sedangkan browse Filament & fallback hydrate membacanya.
     */
    private function backfillPoiOperators(array $operatorTitles): int
    {
        if ($operatorTitles === []) {
            return 0;
        }

        $backfilled = 0;
        OpenchargemapPoi::query()
            ->whereNull('operator')
            ->chunkById(500, function ($pois) use ($operatorTitles, &$backfilled) {
                foreach ($pois as $poi) {
                    $raw = $poi->raw_payload ?? [];
                    $operatorId = (int) (data_get($raw, 'OperatorInfo.ID')
                        ?? data_get($raw, 'OperatorID')
                        ?? 0);
                    $title = $operatorTitles[$operatorId] ?? null;
                    if ($title === null || $this->isNoiseTitle($title)) {
                        continue;
                    }

                    $poi->operator = $title;
                    $poi->save();
                    $backfilled++;
                }
            });

        return $backfilled;
    }

    /**
     * Kumpulkan operator unik dari openchargemap_pois. Menerima tiga bentuk:
     * raw API verbose (OperatorInfo), compact sync (OperatorID int — judul
     * dari katalog referencedata), dan import file lama (string operator).
     *
     * @param  array<int, string>  $operatorTitles  katalog id → judul (referencedata)
     * @return array{operators: list<array{id: int, title: string}>, noise: int}
     */
    private function collectOperators(array $operatorTitles): array
    {
        $operators = [];
        $noise = 0;

        OpenchargemapPoi::query()
            ->chunkById(500, function ($pois) use ($operatorTitles, &$operators, &$noise) {
                foreach ($pois as $poi) {
                    $raw = $poi->raw_payload ?? [];

                    $id = (int) (data_get($raw, 'OperatorInfo.ID')
                        ?? data_get($raw, 'OperatorID')
                        ?? 0);
                    $title = trim((string) (data_get($raw, 'OperatorInfo.Title')
                        ?? ($id > 0 ? ($operatorTitles[$id] ?? null) : null)
                        ?? data_get($raw, 'operator')
                        ?? $poi->operator
                        ?? ''));

                    if ($title === '') {
                        continue;
                    }
                    if ($this->isNoiseTitle($title)) {
                        $noise++;

                        continue;
                    }

                    $key = $id > 0 ? "id:{$id}" : 'title:'.strtolower($title);
                    $operators[$key] = ['id' => $id, 'title' => $title];
                }
            });

        return ['operators' => array_values($operators), 'noise' => $noise];
    }

    private function isNoiseTitle(string $title): bool
    {
        $lower = strtolower($title);

        foreach (self::NOISE_TITLE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
