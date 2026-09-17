<x-filament-panels::page>
    <style>
        .ssc-card { border: 1px solid rgba(156,163,175,.25); border-radius: 12px; padding: 14px; background: rgba(255,255,255,.03); }
        .ssc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .ssc-badge { display: inline-flex; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .ssc-cat-match_exact { background: rgba(16,185,129,.15); color: #059669; }
        .ssc-cat-match_near  { background: rgba(14,165,233,.15); color: #0284c7; }
        .ssc-cat-candidate   { background: rgba(245,158,11,.18); color: #b45309; }
        .ssc-cat-conflict_far{ background: rgba(239,68,68,.15); color: #dc2626; }
        .ssc-cat-unique      { background: rgba(107,114,128,.15); color: #6b7280; }
        .dark .ssc-cat-match_exact { color: #34d399; } .dark .ssc-cat-match_near { color: #38bdf8; }
        .dark .ssc-cat-candidate { color: #fbbf24; } .dark .ssc-cat-conflict_far { color: #f87171; }
        .dark .ssc-cat-unique { color: #9ca3af; }
        .ssc-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .ssc-table th { text-align: left; padding: 8px 10px; background: rgba(156,163,175,.08); font-weight: 600; }
        .ssc-table td { padding: 8px 10px; border-top: 1px solid rgba(156,163,175,.16); vertical-align: top; }
        .ssc-table tr:hover td { background: rgba(156,163,175,.06); }
        .ssc-muted { color: #6b7280; font-size: 11px; }
        .ssc-select, .ssc-input { border: 1px solid rgba(156,163,175,.35); border-radius: 8px; padding: 6px 10px; background: transparent; }
        .ssc-chip { border: 1px solid rgba(156,163,175,.35); border-radius: 8px; padding: 5px 10px; cursor: pointer; }
    </style>

    <h2 class="text-lg font-semibold mb-1">{{ $title }}</h2>
    <p class="ssc-muted mb-4">
        Perbandingan titik SPKLU antar sumber. Match = koordinat berdekatan;
        kandidat = nama serupa tapi jarak menengah; konflik = nama serupa tapi
        jarak jauh (indikasi koordinat salah di salah satu sumber).
        Sumber: ESDM Singgat · PLN Master · OpenChargeMap (CC BY 4.0).
    </p>

    {{-- Ringkasan per pasangan --}}
    <div class="ssc-grid mb-4">
        @foreach (['esdm_pln', 'esdm_ocm', 'pln_ocm'] as $pair)
            <div class="ssc-card">
                <div class="font-semibold mb-2">{{ $pairLabels[$pair] ?? $pair }}</div>
                <table class="ssc-table">
                    @foreach ($categories as $cat)
                        <tr>
                            <td><span class="ssc-badge ssc-cat-{{ $cat }}">{{ $categoryLabels[$cat] }}</span></td>
                            <td class="text-right font-semibold">{{ number_format($summary[$pair][$cat] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>

    {{-- Filter --}}
    <div class="ssc-card mb-4 flex flex-wrap items-center gap-3">
        <select class="ssc-select" wire:model.live="pairFilter">
            <option value="all">Semua pasangan</option>
            @foreach ($pairs as $p)
                <option value="{{ $p }}">{{ $pairLabels[$p] ?? $p }}</option>
            @endforeach
        </select>

        <select class="ssc-select" wire:model.live="categoryFilter">
            <option value="all">Semua kategori</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat }}">{{ $categoryLabels[$cat] }}</option>
            @endforeach
        </select>

        <input type="search" class="ssc-input flex-1 min-w-[200px]" placeholder="Cari nama lokasi…"
               wire:model.live.debounce.400ms="search">

        <select class="ssc-select" wire:model.live="perPage">
            @foreach ([25, 50, 100, 200] as $n)
                <option value="{{ $n }}">{{ $n }}/hal</option>
            @endforeach
        </select>

        <button type="button" class="ssc-chip" wire:click="refreshComparison">↻ Hitung ulang</button>
    </div>

    {{-- Tabel --}}
    <div class="ssc-card overflow-x-auto">
        <table class="ssc-table">
            <thead>
                <tr>
                    <th>Pasangan</th>
                    <th>Kategori</th>
                    <th>A</th>
                    <th>B</th>
                    <th class="text-right">Jarak</th>
                    <th class="text-right">Nama %</th>
                    <th>Koordinat A → B</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td class="ssc-muted whitespace-nowrap">{{ $pairLabels[$r['pair']] ?? $r['pair'] }}</td>
                        <td><span class="ssc-badge ssc-cat-{{ $r['category'] }}">{{ $categoryLabels[$r['category']] }}</span></td>
                        <td>
                            @if ($r['a'])
                                <div>{{ $r['a']['name'] ?: '(tanpa nama)' }}</div>
                                <div class="ssc-muted">{{ $r['a']['operator'] ?: '—' }}</div>
                            @else
                                <span class="ssc-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $r['b']['name'] ?: '(tanpa nama)' }}</div>
                            <div class="ssc-muted">{{ $r['b']['operator'] ?: '—' }}</div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            {{ $r['distance_m'] !== null ? number_format($r['distance_m']).' m' : '—' }}
                        </td>
                        <td class="text-right">{{ $r['similarity_pct'] }}%</td>
                        <td class="ssc-muted whitespace-nowrap">
                            @if ($r['a'])
                                {{ number_format($r['a']['lat'], 5) }}, {{ number_format($r['a']['lng'], 5) }}<br>
                            @endif
                            {{ number_format($r['b']['lat'], 5) }}, {{ number_format($r['b']['lng'], 5) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center ssc-muted">Tidak ada data untuk filter ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Paginasi --}}
    <div class="flex items-center justify-between mt-3 ssc-muted">
        <div>
            Menampilkan {{ number_format($rows->firstItem() ?? 0) }}–{{ number_format($rows->lastItem() ?? 0) }}
            dari <strong>{{ number_format($rows->total()) }}</strong> baris
        </div>
        <div class="flex gap-2">
            @if (! $rows->onFirstPage())
                <button type="button" class="ssc-chip" wire:click="gotoPage({{ $rows->currentPage() - 1 }})">‹ Prev</button>
            @endif
            @if ($rows->hasMorePages())
                <button type="button" class="ssc-chip" wire:click="gotoPage({{ $rows->currentPage() + 1 }})">Next ›</button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
