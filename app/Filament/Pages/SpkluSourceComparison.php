<?php

namespace App\Filament\Pages;

use App\Services\SpkluSourceComparisonService;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

/**
 * Halaman "Perbandingan Sumber SPKLU" — ESDM vs PLN Master vs OpenChargeMap.
 *
 * Klasifikasi per pasangan sumber: match_exact (≤50 m), match_near (≤150 m),
 * candidate (nama serupa, ≤2 km), conflict_far (nama serupa, >2 km),
 * unique (tanpa pasangan). Read-only; hasil dihitung service + cache 24 jam
 * (tombol "Hitung ulang" mem-bypass cache).
 */
class SpkluSourceComparison extends Page
{
    use WithPagination;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-m-scale';

    protected static string | \UnitEnum | null $navigationGroup = 'SPKLU';

    protected static ?string $navigationLabel = 'Perbandingan Sumber';

    protected static ?string $title = 'Perbandingan Sumber SPKLU (ESDM · PLN · OCM)';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.spklu-source-comparison';

    public string $pairFilter = 'all';

    public string $categoryFilter = 'all';

    public string $search = '';

    public int $perPage = 50;

    public array $summary = [];

    /** Label kategori untuk badge di view. */
    public const CATEGORY_LABELS = [
        'match_exact' => 'Match exact',
        'match_near' => 'Match near',
        'candidate' => 'Kandidat dekat',
        'conflict_far' => 'Konflik jauh',
        'unique' => 'Unik',
    ];

    public const PAIR_LABELS = [
        'esdm_pln' => 'ESDM ↔ PLN',
        'esdm_ocm' => 'ESDM ↔ OCM',
        'pln_ocm' => 'PLN ↔ OCM',
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPairFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Hitung ulang (bypass cache 24 jam). */
    public function refreshComparison(): void
    {
        $service = app(SpkluSourceComparisonService::class);
        $this->summary = $service->compare(refresh: true, perPage: 1)['summary'];
        $this->resetPage();
    }

    protected function rows(SpkluSourceComparisonService $service): LengthAwarePaginator
    {
        $result = $service->compare(
            pair: $this->pairFilter === 'all' ? null : $this->pairFilter,
            category: $this->categoryFilter === 'all' ? null : $this->categoryFilter,
            q: $this->search !== '' ? $this->search : null,
            page: LengthAwarePaginator::resolveCurrentPage(),
            perPage: max(10, min(500, $this->perPage)),
        );

        $this->summary = $result['summary'];

        return new LengthAwarePaginator(
            $result['items'],
            $result['total'],
            $result['per_page'],
            $result['page'],
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    protected function getViewData(): array
    {
        return array_merge(parent::getViewData(), [
            'rows' => $this->rows(app(SpkluSourceComparisonService::class)),
            'summary' => $this->summary,
            'categoryLabels' => self::CATEGORY_LABELS,
            'pairLabels' => self::PAIR_LABELS,
            'categories' => SpkluSourceComparisonService::CATEGORIES,
            'pairs' => SpkluSourceComparisonService::PAIRS,
        ]);
    }
}
