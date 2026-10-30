<x-filament-panels::page>
    <style>
        :root {
            --vsp-bg-card: #ffffff;
            --vsp-bg-header: rgba(0, 0, 0, 0.015);
            --vsp-bg-sub: rgba(0, 0, 0, 0.02);
            --vsp-border: rgba(156, 163, 175, 0.25);
            --vsp-border-sub: rgba(156, 163, 175, 0.15);
            --vsp-text-title: #111827;
            --vsp-text-body: #374151;
            --vsp-text-muted: #6b7280;
            --vsp-input-bg: #ffffff;
            --vsp-input-border: rgba(156, 163, 175, 0.35);
        }

        .dark, [data-theme="dark"] {
            --vsp-bg-card: rgba(255, 255, 255, 0.04);
            --vsp-bg-header: rgba(255, 255, 255, 0.02);
            --vsp-bg-sub: rgba(255, 255, 255, 0.02);
            --vsp-border: rgba(255, 255, 255, 0.1);
            --vsp-border-sub: rgba(255, 255, 255, 0.07);
            --vsp-text-title: #f9fafb;
            --vsp-text-body: #e5e7eb;
            --vsp-text-muted: #9ca3af;
            --vsp-input-bg: rgba(255, 255, 255, 0.05);
            --vsp-input-border: rgba(255, 255, 255, 0.15);
        }

        .vsp-card {
            border: 1px solid var(--vsp-border);
            border-radius: 12px;
            padding: 16px;
            background: var(--vsp-bg-card);
            color: var(--vsp-text-body);
            transition: all 0.15s ease;
        }
        .vsp-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.3;
        }
        .vsp-badge-success { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .dark .vsp-badge-success, [data-theme="dark"] .vsp-badge-success { background: rgba(16, 185, 129, 0.25); color: #34d399; }
        .vsp-badge-warn { background: rgba(245, 158, 11, 0.18); color: #b45309; }
        .dark .vsp-badge-warn, [data-theme="dark"] .vsp-badge-warn { background: rgba(245, 158, 11, 0.25); color: #fbbf24; }
        .vsp-badge-gray { background: rgba(156, 163, 175, 0.15); color: #4b5563; }
        .dark .vsp-badge-gray, [data-theme="dark"] .vsp-badge-gray { background: rgba(255, 255, 255, 0.08); color: #d1d5db; }
        .vsp-input-control {
            background: var(--vsp-input-bg);
            color: var(--vsp-text-title);
            border: 1px solid var(--vsp-input-border);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            outline: none;
            width: 100%;
        }
        .vsp-input-control:focus { border-color: #10b981; }
        .vsp-input-control:disabled { opacity: 0.45; cursor: not-allowed; }
        .vsp-btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .vsp-btn-primary:hover { opacity: 0.92; }
        .vsp-btn-dark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid var(--vsp-input-border);
            background: var(--vsp-input-bg);
            color: var(--vsp-text-title);
            transition: all 0.15s ease;
        }
        .vsp-btn-dark:hover { background: rgba(156, 163, 175, 0.15); }
        .vsp-form-grid {
            display: grid;
            grid-template-columns: auto 1.5fr 1.5fr 1fr 0.9fr auto;
            gap: 10px;
            align-items: end;
        }
        @media (max-width: 1100px) {
            .vsp-form-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .vsp-field-label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: var(--vsp-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
    </style>

    <div style="display: flex; flex-direction: column; gap: 24px;">

        {{-- 1. UPLOAD LANGSUNG --}}
        <x-filament::section>
            <x-slot name="heading">
                Upload Laporan (CSV / XLSX GAIKINDO) — Impor Langsung
            </x-slot>

            <p style="margin-top: 2px; margin-bottom: 16px; font-size: 13px; color: var(--vsp-text-muted); line-height: 1.5;">
                <strong>Tanpa pra-analisis</strong>: stats langsung masuk DB (raw utuh). Stats periode yang sama
                (tahunan/bulanan) <strong>diganti penuh</strong> — idempoten untuk re-upload. Kombinasi yang belum
                ter-link ke katalog muncul di <em>Kombinasi Pending</em> di bawah dan angka tetap terhitung di total
                Pasar EV selama menunggu kelengkapan. Jalur ketat (analisis dulu) tetap tersedia di halaman
                <em>Preview Impor Penjualan</em>.
            </p>

            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px;">
                <div style="flex: 1; min-width: 260px;">
                    <label class="vsp-field-label" style="font-size: 12px;">
                        Pilih File GAIKINDO (.csv / .xlsx):
                        <input type="file" accept=".csv,.txt,.xlsx,.xls"
                               wire:model.live="file"
                               class="vsp-input-control" style="padding: 6px 10px;" />
                    </label>
                </div>

                <div style="min-width: 200px;">
                    <label class="vsp-field-label" style="font-size: 12px;">
                        Periode Penjualan:
                        <select wire:model.live="month" class="vsp-input-control">
                            <option value="">📊 Tahunan (Kolom JAN..DEC)</option>
                            @foreach (['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $name)
                                <option value="{{ $i + 1 }}" @selected($month === $i + 1)>🗓️ {{ $name }} (Kolom Bulan / UNITS)</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div style="min-width: 110px;">
                    <label class="vsp-field-label" style="font-size: 12px;">
                        Tahun:
                        <input type="number" wire:model.lazy="importYear" min="2015" max="2100"
                               class="vsp-input-control" placeholder="auto" />
                    </label>
                </div>

                <div>
                    <button type="button" wire:click="importDirect" wire:loading.attr="disabled" class="vsp-btn-primary">
                        <span wire:loading.remove wire:target="importDirect">⬆️ Impor Sekarang</span>
                        <span wire:loading wire:target="importDirect">⏳ Mengimpor…</span>
                    </button>
                </div>
            </div>

            @error('file')
                <div style="margin-top: 12px; padding: 10px 14px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; font-size: 13px; font-weight: 600;">
                    ⚠️ {{ $message }}
                </div>
            @enderror

            @if ($importMessage)
                <div style="margin-top: 14px; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
                            {{ $importSuccess
                                ? 'background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669;'
                                : 'background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444;' }}">
                    {{ $importMessage }}
                </div>
            @endif

            @if ($importSummary !== [])
                <div class="vsp-card" style="margin-top: 14px;">
                    <div style="font-size: 12px; font-weight: 700; color: var(--vsp-text-muted); margin-bottom: 10px;">RINGKASAN IMPOR</div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        @foreach ($importSummary as $cells)
                            <tr>
                                <td style="padding: 5px 12px 5px 0; color: var(--vsp-text-muted); white-space: nowrap;">{{ $cells[0] }}</td>
                                <td style="padding: 5px 0; font-weight: 600;">{{ $cells[1] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 2. WORKLIST KOMBINASI PENDING --}}
        <x-filament::section>
            <x-slot name="heading">
                Kombinasi Pending{{ count($pendingRows) > 0 ? ' ('.count($pendingRows).')' : '' }}
            </x-slot>

            <p style="margin-top: 2px; margin-bottom: 16px; font-size: 13px; color: var(--vsp-text-muted); line-height: 1.5;">
                Baris stats yang belum ter-link, <strong>dikelompokkan per keluarga (Brand + Model)</strong> —
                kategori &amp; size melekat pada model (semua type pasti sama), jadi cukup <strong>satu keputusan per
                keluarga</strong>; powertrain tiap type mengikuti <strong>FUEL file</strong> (G/D/CNG → ICE,
                Hybrid/HEV → HEV, PHEV → PHEV, BEV → BEV). Klik <strong>Simpan &amp; Tautkan</strong>: model dibuat
                bila belum ada, semua type di bawahnya dibuat sekaligus, dan seluruh baris stats serupa (lintas
                tahun) di-back-link. Size otomatis nonaktif untuk kategori tanpa ukuran (hanya
                MPV/Sedan/SUV/Hatchback). Keluarga yang modelnya sudah ada di katalog menampilkan kategorinya
                sebagai prefill (keputusan katalog lama menang).
            </p>

            @if (count($pendingRows) === 0)
                <div class="vsp-card" style="display: flex; align-items: center; gap: 10px; border-color: rgba(16, 185, 129, 0.35);">
                    <span style="font-size: 20px;">✅</span>
                    <div>
                        <div style="font-weight: 700; color: var(--vsp-text-title);">Semua kombinasi tertaut</div>
                        <div style="font-size: 12px; color: var(--vsp-text-muted);">Tidak ada pekerjaan pending — seluruh baris stats sudah ter-link ke katalog.</div>
                    </div>
                </div>
            @else
                <datalist id="vsp-brand-options">
                    @foreach ($brandNames as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>

                {{-- BAR AKSI MASSAL --}}
                @php($selectedCount = count(array_filter($selected)))
                <div class="vsp-card" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; border-color: rgba(245, 158, 11, 0.4);">
                    <div style="font-size: 13px; font-weight: 700; color: var(--vsp-text-title); min-width: 130px;">
                        ⚡ Bulk
                        <div style="font-size: 11px; font-weight: 500; color: var(--vsp-text-muted);">
                            {{ $selectedCount }} dari {{ count($pendingRows) }} baris terpilih
                        </div>
                    </div>

                    <label class="vsp-field-label" style="min-width: 150px;">
                        Kategori Massal
                        <select wire:model.live="bulkCategory" class="vsp-input-control">
                            <option value="">— pilih —</option>
                            @foreach (\App\Filament\Pages\VehicleSalesDirectImport::categoryOptions() as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="vsp-field-label" style="min-width: 110px;">
                        Size Massal
                        <select wire:model.live="bulkSize" class="vsp-input-control">
                            <option value="">—</option>
                            @foreach (\App\Filament\Pages\VehicleSalesDirectImport::sizeOptions() as $sz)
                                <option value="{{ $sz }}">{{ $sz }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="button" wire:click="applyBulkCategory" wire:loading.attr="disabled" wire:target="applyBulkCategory" class="vsp-btn-dark">
                        🏷️ Terapkan ke Terpilih
                    </button>

                    <button type="button" wire:click="bulkLink" wire:loading.attr="disabled" wire:target="bulkLink" class="vsp-btn-primary">
                        <span wire:loading.remove wire:target="bulkLink">⚡ Tautkan Semua Terpilih ({{ $selectedCount }})</span>
                        <span wire:loading wire:target="bulkLink">⏳ Menautkan…</span>
                    </button>
                </div>

                @if ($bulkMessage)
                    <div style="margin: 10px 0 0; padding: 10px 14px; border-radius: 8px; font-size: 12px; font-weight: 600;
                                background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #b45309; word-break: break-word;">
                        {{ $bulkMessage }}
                    </div>
                @endif

                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 12px;" wire:loading.delay wire:target="linkCombo">
                    @foreach ($pendingRows as $row)
                        @php($form = $forms[$row['key']] ?? [])
                        <div class="vsp-card"
                             x-data="{ cat: @js($form['category'] ?? ''), sizable: @js(\App\Support\VehicleCategories::SIZABLE) }">
                            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px;">
                                <span style="font-size: 14px; font-weight: 700; color: var(--vsp-text-title);">{{ $row['brand'] }} {{ $row['model'] }}</span>
                                <span class="vsp-badge vsp-badge-warn">{{ number_format($row['units']) }} unit</span>
                                <span class="vsp-badge vsp-badge-gray">Tahun {{ $row['years'] }}</span>
                                <span class="vsp-badge vsp-badge-gray">{{ count($row['types']) }} type</span>
                                @foreach (array_slice($row['fuels'], 0, 4) as $fuel)
                                    <span class="vsp-badge vsp-badge-gray" title="FUEL di file laporan">fuel: {{ $fuel }}</span>
                                @endforeach
                                @if ($row['catalog_model_id'])
                                    <span class="vsp-badge vsp-badge-success" title="Model sudah ada di katalog — kategori dari katalog dipakai">✓ katalog{{ $row['catalog_category'] ? ': '.$row['catalog_category'].($row['catalog_size'] ? ' / '.$row['catalog_size'] : '') : '' }}</span>
                                @endif
                            </div>

                            <div class="vsp-form-grid">
                                <label style="display: flex; align-items: center; gap: 6px; padding-bottom: 8px; font-size: 11px; font-weight: 600; color: var(--vsp-text-muted); cursor: pointer;" title="Pilih untuk aksi massal">
                                    <input type="checkbox" wire:model.live="selected.{{ $row['key'] }}" style="width: 16px; height: 16px; accent-color: #10b981;" />
                                    pilih
                                </label>

                                <label class="vsp-field-label">
                                    Brand
                                    <input type="text" list="vsp-brand-options"
                                           wire:model.lazy="forms.{{ $row['key'] }}.brand"
                                           class="vsp-input-control"
                                           value="{{ $form['brand'] ?? $row['brand'] }}" />
                                </label>

                                <label class="vsp-field-label">
                                    Model
                                    <input type="text" wire:model.lazy="forms.{{ $row['key'] }}.model"
                                           class="vsp-input-control"
                                           value="{{ $form['model'] ?? $row['model'] }}" />
                                </label>

                                <label class="vsp-field-label">
                                    Category *
                                    <select wire:model="forms.{{ $row['key'] }}.category" x-on:change="cat = $event.target.value" class="vsp-input-control">
                                        <option value="">— pilih —</option>
                                        @foreach (\App\Filament\Pages\VehicleSalesDirectImport::categoryOptions() as $cat)
                                            <option value="{{ $cat }}" @selected(($form['category'] ?? '') === $cat)>{{ $cat }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="vsp-field-label">
                                    <span x-show="sizable.includes(cat)">Size</span>
                                    <span x-show="! sizable.includes(cat)" style="text-transform: none; font-weight: 500;">Size —</span>
                                    <select x-show="sizable.includes(cat)" wire:model="forms.{{ $row['key'] }}.size" class="vsp-input-control"
                                            :title="sizable.includes(cat) ? '' : 'Kategori ini tidak ber-ukuran'">
                                        <option value="">—</option>
                                        @foreach (\App\Filament\Pages\VehicleSalesDirectImport::sizeOptions() as $size)
                                            <option value="{{ $size }}" @selected(($form['size'] ?? '') === $size)>{{ $size }}</option>
                                        @endforeach
                                    </select>
                                    <span x-show="! sizable.includes(cat)" class="vsp-input-control"
                                          style="display: none; opacity: 0.6; cursor: default; text-align: center;">—</span>
                                </label>

                                <div>
                                    <button type="button" wire:click="linkCombo('{{ $row['key'] }}')"
                                            wire:target="linkCombo('{{ $row['key'] }}')" wire:loading.attr="disabled"
                                            class="vsp-btn-primary" style="white-space: nowrap;">
                                        <span wire:loading.remove wire:target="linkCombo('{{ $row['key'] }}')">🔗 Tautkan ({{ count($row['types']) }} type)</span>
                                        <span wire:loading wire:target="linkCombo('{{ $row['key'] }}')">⏳…</span>
                                    </button>
                                </div>
                            </div>

                            {{-- Daftar type: powertrain PER VARIAN dari FUEL — bisa dikoreksi per baris --}}
                            <details style="margin-top: 10px;" open>
                                <summary style="font-size: 12px; color: var(--vsp-text-muted); cursor: pointer;">
                                    {{ count($row['types']) }} varian type — powertrain per varian (dari FUEL, koreksi bila perlu)
                                </summary>
                                <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 6px;">
                                    @foreach ($row['types'] as $type)
                                        <tr style="border-bottom: 1px solid var(--vsp-border-sub);">
                                            <td style="padding: 4px 10px 4px 0; font-family: monospace; color: var(--vsp-text-body);">{{ $type['raw'] }}</td>
                                            <td style="padding: 4px 10px; color: var(--vsp-text-muted); white-space: nowrap;">
                                                @if ($type['fuel'])fuel: {{ $type['fuel'] }}@else—@endif
                                            </td>
                                            <td style="padding: 4px 10px; white-space: nowrap;">
                                                <select wire:model="forms.{{ $row['key'] }}.pt.{{ $type['key'] }}" class="vsp-input-control" style="padding: 3px 6px; min-width: 80px;">
                                                    @if (($form['pt'][$type['key']] ?? $type['powertrain']) === '')
                                                        <option value="">— pilih —</option>
                                                    @endif
                                                    @foreach (\App\Filament\Pages\VehicleSalesDirectImport::powertrainOptions() as $pt)
                                                        <option value="{{ $pt }}" @selected(($form['pt'][$type['key']] ?? $type['powertrain']) === $pt)>{{ $pt }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td style="padding: 4px 0; text-align: right; font-weight: 600; white-space: nowrap;">{{ number_format($type['units']) }} unit</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </details>
                        </div>
                    @endforeach
                </div>

                @if ($linkMessage)
                    <div style="margin-top: 14px; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
                                background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; word-break: break-word;">
                        {{ $linkMessage }}
                    </div>
                @endif
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
