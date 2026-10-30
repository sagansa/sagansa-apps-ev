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
        .vsp-grid-5 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
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
        .vsp-badge-danger { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
        .dark .vsp-badge-danger, [data-theme="dark"] .vsp-badge-danger { background: rgba(239, 68, 68, 0.25); color: #f87171; }
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
        .vsp-input-control:focus {
            border-color: #10b981;
        }
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
        .vsp-btn-primary:hover {
            opacity: 0.92;
        }
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
        .vsp-btn-dark:hover {
            background: rgba(156, 163, 175, 0.15);
        }
    </style>

    <div style="display: flex; flex-direction: column; gap: 24px;" x-data="{ downloading: false }">
        {{-- 1. UPLOAD FORM SECTION --}}
        <x-filament::section>
            <x-slot name="heading">
                Upload Laporan Penjualan (CSV / XLSX GAIKINDO)
            </x-slot>

            <p style="margin-top: 2px; margin-bottom: 16px; font-size: 13px; color: var(--vsp-text-muted); line-height: 1.5;">
                <strong>Mode Analisis Dry-Run (Read-Only)</strong>: File CSV/XLSX diperiksa terhadap katalog master (Brand → Model).
                Kombinasi BEV yang belum terdaftar di katalog akan diidentifikasi sebelum data diimpor secara permanen.
            </p>

            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px;">
                <div style="flex: 1; min-width: 260px;">
                    <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                        Pilih File GAIKINDO (.csv / .xlsx):
                        <input type="file" accept=".csv,.txt,.xlsx,.xls"
                               wire:model.live="csvFile"
                               class="vsp-input-control" style="padding: 6px 10px;" />
                    </label>
                </div>

                <div style="min-width: 200px;">
                    <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                        Periode Penjualan:
                        <select wire:model.live="month" class="vsp-input-control">
                            <option value="">📊 Tahunan (Kolom JAN..DEC)</option>
                            @foreach (['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $name)
                                <option value="{{ $i + 1 }}" @selected($month === $i + 1)>🗓️ {{ $name }} (Kolom Bulan / UNITS)</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div>
                    <button type="button" wire:click="analyze" wire:loading.attr="disabled" class="vsp-btn-primary">
                        <span wire:loading.remove wire:target="analyze">⚡ Mulai Analisis Laporan</span>
                        <span wire:loading wire:target="analyze">⏳ Menganalisis File…</span>
                    </button>
                </div>
            </div>

            @error('csvFile')
                <div style="margin-top: 12px; padding: 10px 14px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; font-size: 13px; font-weight: 600;">
                    ⚠️ {{ $message }}
                </div>
            @enderror

            @if ($error)
                <div style="margin-top: 12px; padding: 10px 14px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; font-size: 13px; font-weight: 600;">
                    ❌ {{ $error }}
                </div>
            @endif
        </x-filament::section>

        {{-- 2. HASIL ANALISIS DASHBOARD --}}
        @if ($result !== null)
            @php $s = $result['summary']; @endphp

            <div class="vsp-grid-5">
                {{-- Baris Dibaca --}}
                <div class="vsp-card">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--vsp-text-muted);">Baris Dibaca</div>
                    <div style="margin-top: 8px; font-size: 22px; font-weight: 800; font-family: monospace; color: var(--vsp-text-title);">
                        {{ number_format($s['rows']) }}
                    </div>
                    <div style="margin-top: 4px; font-size: 11px; color: var(--vsp-text-muted);">Total baris file</div>
                </div>

                {{-- Dilewati --}}
                <div class="vsp-card" style="cursor: pointer; {{ $s['skipped'] > 0 ? 'border-color: rgba(245, 158, 11, 0.4);' : '' }}" wire:click="toggleSkipped" @if($s['skipped'] > 0) title="Klik untuk lihat detail baris yang dilewati" @endif>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: {{ $s['skipped'] > 0 ? '#f59e0b' : 'var(--vsp-text-muted)' }};">
                        <span>Dilewati (Junk)</span>
                        @if ($s['skipped'] > 0)
                            <span style="font-size: 13px; text-transform: none; letter-spacing: 0;">{{ $showSkipped ? '▲' : '▼' }}</span>
                        @endif
                    </div>
                    <div style="margin-top: 8px; font-size: 22px; font-weight: 800; font-family: monospace; color: var(--vsp-text-title);">
                        {{ number_format($s['skipped']) }}
                    </div>
                    <div style="margin-top: 4px; font-size: 11px; color: {{ $s['skipped'] > 0 ? '#d97706' : 'var(--vsp-text-muted)' }}; font-weight: 600;">
                        {{ $s['skipped'] > 0 ? ($showSkipped ? 'klik untuk tutup' : 'klik untuk lihat detail') : 'Header / subtotal' }}
                    </div>
                </div>

                {{-- Ter-match Katalog --}}
                <div class="vsp-card" style="border-color: rgba(16, 185, 129, 0.4);">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #10b981;">Ter-match Katalog</div>
                    <div style="margin-top: 8px; font-size: 22px; font-weight: 800; font-family: monospace; color: #10b981;">
                        {{ number_format($s['matched']) }}
                    </div>
                    <div style="margin-top: 4px; font-size: 11px; color: #10b981; font-weight: 600;">✓ Siap diimpor</div>
                </div>

                {{-- BARU --}}
                <div class="vsp-card" style="border-color: {{ $s['new'] > 0 ? 'rgba(245, 158, 11, 0.5)' : 'rgba(16, 185, 129, 0.4)' }}; background: {{ $s['new'] > 0 ? 'rgba(245, 158, 11, 0.08)' : 'var(--vsp-bg-card)' }};">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: {{ $s['new'] > 0 ? '#f59e0b' : '#10b981' }};">BARU (perlu keputusan)</div>
                    <div style="margin-top: 8px; font-size: 22px; font-weight: 800; font-family: monospace; color: {{ $s['new'] > 0 ? '#f59e0b' : '#10b981' }};">
                        {{ number_format($s['new']) }}
                    </div>
                    <div style="margin-top: 4px; font-size: 11px; color: {{ $s['new'] > 0 ? '#d97706' : '#10b981' }}; font-weight: 600;">
                        {{ $s['new'] > 0 ? '⚠️ Butuh aksi' : '✓ 100% matched' }}
                    </div>
                </div>
            </div>

            {{-- 3A. DETAIL BARIS DILEWATI --}}
            @if ($showSkipped && $s['skipped'] > 0)
                @php $skippedRows = $result['skipped_rows'] ?? []; $shown = array_slice($skippedRows, 0, 100); @endphp
                <x-filament::section>
                    <x-slot name="heading">
                        🔎 Detail Baris Dilewati ({{ count($skippedRows) }})
                    </x-slot>

                    <div style="overflow-x: auto;">
                        <table style="width: 100%; font-size: 13px; text-align: left; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--vsp-border); color: var(--vsp-text-muted);">
                                    <th style="padding: 8px 12px; font-weight: 600;">Brand</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Type / Model (raw)</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Alasan Dilewati</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shown as $row)
                                    <tr style="border-bottom: 1px solid var(--vsp-border-sub);">
                                        <td style="padding: 8px 12px; font-family: monospace; font-weight: 700; color: var(--vsp-text-title);">{{ $row['brand'] ?: '—' }}</td>
                                        <td style="padding: 8px 12px; color: var(--vsp-text-title);">{{ $row['type_model'] ?: '—' }}</td>
                                        <td style="padding: 8px 12px;">
                                            <span class="vsp-badge vsp-badge-gray">{{ $row['reason'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (count($skippedRows) > 100)
                        <p style="margin-top: 10px; font-size: 12px; color: var(--vsp-text-muted);">Menampilkan 100 dari {{ count($skippedRows) }} baris.</p>
                    @endif
                </x-filament::section>
            @endif

            {{-- 3. TABEL KOMBINASI BARU --}}
            @if ($s['new'] > 0)
                <x-filament::section>
                    <x-slot name="heading">
                        <span style="color: #f59e0b;">⚠️ Kombinasi BARU Terdeteksi ({{ count($result['new']) }} Kombinasi)</span>
                    </x-slot>

                    <p style="margin-bottom: 12px; font-size: 12px; color: var(--vsp-text-muted);">
                        Kombinasi model di bawah ini terdeteksi pada file laporan tetapi belum terdaftar dalam master katalog.
                        Isi <strong>Kategori</strong> (+ Size bila MPV/Sedan/SUV/Hatchback) lalu klik <strong>＋ CONNECTING</strong> per baris —
                        tanpa perlu unduh CSV. Verifikasi dulu: baris bertanda <em>typo/varian 0 unit</em> boleh diabaikan.
                    </p>

                    @if ($newRowMessage)
                        <div style="margin-bottom: 12px; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; background: {{ str_starts_with($newRowMessage, '✓') ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)' }}; color: {{ str_starts_with($newRowMessage, '✓') ? '#10b981' : '#ef4444' }}; border: 1px solid {{ str_starts_with($newRowMessage, '✓') ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' }};">
                            {{ $newRowMessage }}
                        </div>
                    @endif

                    <div style="overflow-x: auto; margin-bottom: 16px;">
                        <table style="width: 100%; font-size: 13px; text-align: left; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--vsp-border); color: var(--vsp-text-muted);">
                                    <th style="padding: 8px 12px; font-weight: 600;">Nama di Laporan (utuh)</th>
                                    <th style="padding: 8px 12px; text-align: right;">Unit</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Status Katalog</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Powertrain</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Kategori *</th>
                                    <th style="padding: 8px 12px; font-weight: 600;">Size</th>
                                    <th style="padding: 8px 12px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result['new'] as $i => $row)
                                    @php
                                        $saved = isset($newRowSaved[$i]);
                                        $form = $newRowForms[$i] ?? [];
                                    @endphp
                                    <tr style="border-bottom: 1px solid var(--vsp-border-sub); {{ $saved ? 'opacity: 0.55;' : '' }} background: {{ str_starts_with($row['powertrain'], 'BEV') ? 'transparent' : 'rgba(156, 163, 175, 0.05)' }};"
                                        x-data="{ cat: @js($form['category'] ?? ''), sizable: @js(\App\Support\VehicleCategories::SIZABLE) }">
                                        <td style="padding: 8px 12px; font-family: monospace; font-weight: 700; color: var(--vsp-text-title);">
                                            {{ $row['brand'] }}
                                            @if ($row['fuel'])
                                                <span class="vsp-badge vsp-badge-gray" style="margin-left: 6px;" title="FUEL di file laporan">fuel: {{ $row['fuel'] }}</span>
                                            @endif
                                            <div style="font-size: 11px; font-weight: 500; color: var(--vsp-text-muted); margin-top: 3px; font-family: sans-serif;">
                                                → {{ $row['brand_name'] ?? $row['raw_brand'] }} / {{ $row['model'] }} / {{ $row['type'] }}
                                            </div>
                                        </td>
                                        <td style="padding: 8px 12px; text-align: right; font-family: monospace; font-weight: 700; color: var(--vsp-text-title);">
                                            {{ number_format($row['units']) }}
                                        </td>
                                        <td style="padding: 8px 12px;">
                                            @if ($row['brand_name'])
                                                <span class="vsp-badge vsp-badge-warn">model baru di {{ $row['brand_name'] }}</span>
                                            @else
                                                <span class="vsp-badge vsp-badge-warn">belum ada di CONNECTING</span>
                                            @endif
                                        </td>
                                        <td style="padding: 8px 12px;">
                                            <select wire:model="newRowForms.{{ $i }}.powertrain" class="vsp-input-control" style="padding: 4px 8px; min-width: 90px;">
                                                @if ($row['powertrain'] === '')
                                                    <option value="">— pilih —</option>
                                                @endif
                                                @foreach (['BEV', 'HEV', 'PHEV', 'ICE'] as $pt)
                                                    <option value="{{ $pt }}" @selected(($form['powertrain'] ?? $row['powertrain']) === $pt)>{{ $pt }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td style="padding: 8px 12px;">
                                            <select wire:model="newRowForms.{{ $i }}.category" x-on:change="cat = $event.target.value" class="vsp-input-control" style="padding: 4px 8px; min-width: 120px;">
                                                <option value="">— pilih —</option>
                                                @foreach (\App\Filament\Pages\VehicleSalesPreviewImport::categoryOptions() as $cat)
                                                    <option value="{{ $cat }}" @selected(($form['category'] ?? '') === $cat)>{{ $cat }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td style="padding: 8px 12px;">
                                            <select x-show="sizable.includes(cat)" wire:model="newRowForms.{{ $i }}.size" class="vsp-input-control" style="padding: 4px 8px; min-width: 95px;">
                                                <option value="">—</option>
                                                @foreach (\App\Support\VehicleCategories::SIZES as $sz)
                                                    <option value="{{ $sz }}" @selected(($form['size'] ?? '') === $sz)>{{ $sz }}</option>
                                                @endforeach
                                            </select>
                                            <span x-show="! sizable.includes(cat)" class="vsp-badge vsp-badge-gray" style="display: none;">tanpa ukuran</span>
                                        </td>
                                        <td style="padding: 8px 12px; white-space: nowrap;">
                                            @if ($saved)
                                                <span class="vsp-badge vsp-badge-success">✓ masuk CONNECTING</span>
                                            @else
                                                <button type="button" wire:click="addToConnecting({{ $i }})" wire:loading.attr="disabled" wire:target="addToConnecting({{ $i }})" class="vsp-btn-primary" style="padding: 5px 12px; font-size: 12px;">
                                                    ＋ CONNECTING
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p style="margin-bottom: 12px; font-size: 11px; color: var(--vsp-text-muted);">
                        * Kategori wajib; Size hanya berlaku untuk MPV/Sedan/SUV/Hatchback (otomatis diabaikan untuk kategori lain).
                        Powertrain diprefill dari FUEL file (G/D/CNG → ICE, Hybrid/HEV → HEV, PHEV → PHEV, BEV → BEV);
                        baris ke-2 di kolom nama adalah prefill brand/model/type hasil pemecah nama.
                        Tombol ＋ langsung membuat katalog + CONNECTING — setelah semua selesai klik <strong>Mulai Analisis Laporan</strong> ulang,
                        baris berpindah ke "Ter-match Katalog" dan impor terbuka.
                    </p>

                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        <button type="button" wire:click="bulkAddToConnecting" wire:loading.attr="disabled" wire:target="bulkAddToConnecting" class="vsp-btn-primary">
                            <span wire:loading.remove wire:target="bulkAddToConnecting">⚡ ＋ Semua yang Lengkap</span>
                            <span wire:loading wire:target="bulkAddToConnecting">⏳ Menautkan…</span>
                        </button>

                        <button type="button" wire:click="downloadNew" class="vsp-btn-dark">
                            📥 Unduh CSV → CONNECTING (alternatif isi manual)
                        </button>
                    </div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 6px 0; color: #10b981;">
                        <span style="font-size: 20px;">✅</span>
                        <div style="font-size: 13px; font-weight: 600;">
                            Semua kombinasi BEV sudah ter-match ke katalog — file aman diimpor (<code>vehicle-sales:import-csv --require-full-link</code>).
                        </div>
                    </div>
                </x-filament::section>
            @endif

            {{-- 3B. IMPOR PERMANEN (hanya bila analisis bersih) --}}
            <x-filament::section>
                <x-slot name="heading">
                    @if ($this->isClean())
                        <span style="color: #10b981;">✅ Siap Impor Permanen</span>
                    @else
                        Impor Permanen
                    @endif
                </x-slot>

                <p style="margin-bottom: 12px; font-size: 12px; color: var(--vsp-text-muted); line-height: 1.5;">
                    Import menulis angka penjualan ke database dan <strong>mengganti seluruh stats periode yang sama</strong> (re-upload aman, tidak menumpuk).
                    Syarat: analisis harus bersih — <strong>0 kombinasi baru</strong> dan <strong>0 baris dilewati</strong>.
                </p>

                @if ($result !== null && ! $this->isClean())
                    <div style="margin-bottom: 12px; padding: 10px 14px; border-radius: 8px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.35); color: #d97706; font-size: 13px; font-weight: 600;">
                        ⚠️ Import terkunci: masih ada <strong>{{ number_format($result['summary']['new']) }} kombinasi baru</strong>
                        dan <strong>{{ number_format($result['summary']['skipped']) }} baris dilewati</strong>.
                        Tambahkan kombinasi baru ke CONNECTING (＋ di tabel atas) → Sync CONNECTING → Terapkan ke Katalog → analisis ulang.
                    </div>
                @endif

                <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                    <div style="min-width: 160px;">
                        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                            Tahun Periode:
                            <input type="number" min="2015" max="2100" wire:model="importYear" class="vsp-input-control" placeholder="cth. 2022" />
                        </label>
                    </div>
                    <div>
                        <button type="button" wire:click="importSales" wire:loading.attr="disabled" wire:target="importSales"
                                @if ($result === null || ! $this->isClean()) disabled @endif
                                class="vsp-btn-primary" @if ($result === null || ! $this->isClean()) style="opacity: 0.5; cursor: not-allowed;" @endif>
                            <span wire:loading.remove wire:target="importSales">🚀 Impor Sekarang</span>
                            <span wire:loading wire:target="importSales">⏳ Mengimpor…</span>
                        </button>
                    </div>
                </div>

                @if ($importMessage)
                    <div style="margin-top: 12px; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; background: {{ str_starts_with($importMessage, '✓') ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)' }}; color: {{ str_starts_with($importMessage, '✓') ? '#10b981' : '#ef4444' }}; border: 1px solid {{ str_starts_with($importMessage, '✓') ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' }};">
                        {{ $importMessage }}
                    </div>
                @endif
            </x-filament::section>

            {{-- 4. SIMPAN MAPPING EKSPLISIT --}}
            <x-filament::section>
                <x-slot name="heading">
                    Simpan Mapping Eksplisit
                </x-slot>

                <p style="margin-bottom: 14px; font-size: 12px; color: var(--vsp-text-muted);">
                    Petakan nama mentah → katalog master sekali, tersimpan permanen: semua impor berikutnya otomatis ter-link tanpa tebakan.
                    Katalog tujuan harus sudah terdaftar di master brand/model.
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 140px; gap: 12px; align-items: flex-end;">
                    <div>
                        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                            Raw Brand (Laporan)
                            <input type="text" wire:model="mapRawBrand" placeholder="WULING-DBG" class="vsp-input-control" />
                        </label>
                    </div>
                    <div>
                        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                            Raw Model (Laporan)
                            <input type="text" wire:model="mapRawModel" placeholder="Air EV Baru" class="vsp-input-control" />
                        </label>
                    </div>
                    <div>
                        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                            → Brand Master
                            <input type="text" wire:model="mapBrandName" placeholder="Wuling" class="vsp-input-control" />
                        </label>
                    </div>
                    <div>
                        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                            → Model Master
                            <input type="text" wire:model="mapModelName" placeholder="Air EV" class="vsp-input-control" />
                        </label>
                    </div>
                    <div>
                        <button type="button" wire:click="saveMapping" class="vsp-btn-dark" style="width: 100%; height: 38px;">
                            💾 Simpan
                        </button>
                    </div>
                </div>

                <div style="margin-top: 12px;">
                    <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: var(--vsp-text-muted);">
                        Catatan Tambahan (Opsional)
                        <input type="text" wire:model="mapCatatan" placeholder="cth. Varian facelift atau penamaan promosi khusus dealer" class="vsp-input-control" />
                    </label>
                </div>

                @if ($mapMessage)
                    <div style="margin-top: 12px; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; background: {{ str_starts_with($mapMessage, '✓') ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)' }}; color: {{ str_starts_with($mapMessage, '✓') ? '#10b981' : '#ef4444' }}; border: 1px solid {{ str_starts_with($mapMessage, '✓') ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' }};">
                        {{ $mapMessage }}
                    </div>
                @endif
                @error('mapRawBrand')<p style="margin-top: 4px; font-size: 12px; color: #ef4444;">{{ $message }}</p>@enderror
                @error('mapRawModel')<p style="margin-top: 4px; font-size: 12px; color: #ef4444;">{{ $message }}</p>@enderror
                @error('mapBrandName')<p style="margin-top: 4px; font-size: 12px; color: #ef4444;">{{ $message }}</p>@enderror
                @error('mapModelName')<p style="margin-top: 4px; font-size: 12px; color: #ef4444;">{{ $message }}</p>@enderror
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
