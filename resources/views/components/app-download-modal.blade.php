@php
    $setting = $appDownloadSetting ?? \App\Models\AppDownloadSetting::current();
    $preRelease = $setting && $setting->android_pre_release_flow;
    $supportEmail = $setting ? ($setting->support_email ?: config('admin_notify.email')) : '';

    $rawWa = $setting?->whatsapp_number ?? '08111923572';
    $digitsWa = preg_replace('/[^0-9]/', '', $rawWa);
    $cleanWa = str_starts_with($digitsWa, '0') ? ('62' . substr($digitsWa, 1)) : $digitsWa;
    $waText = urlencode($setting?->whatsapp_text ?? 'Halo Admin EV Charge ID, saya ingin bertanya mengenai kerjasama / bantuan aplikasi.');
    $waUrl = "https://wa.me/{$cleanWa}?text={$waText}";
    $waTestingUrl = 'https://wa.me/' . $cleanWa . '?text=' . urlencode('Halo Admin EV Charge ID, saya ingin dimasukkan ke program closed testing aplikasi Android. Email/Gmail saya: ');
@endphp

@if($setting && $setting->is_active)
<!-- Scoped Styles for EV App Download Modal -->
<style>
    #evAppDownloadModal {
        position: fixed !important;
        inset: 0 !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        height: 100dvh !important;
        z-index: 2147483647 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: rgba(15, 23, 42, 0.45) !important;
        backdrop-filter: blur(2px) !important;
        -webkit-backdrop-filter: blur(2px) !important;
        padding: 16px !important;
        box-sizing: border-box !important;
        overflow-y: auto !important;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.25s ease-in-out !important;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif !important;
    }

    #evAppDownloadModal.ev-modal-visible {
        opacity: 1 !important;
        pointer-events: auto !important;
    }

    #evAppModalBox {
        position: relative !important;
        width: 100% !important;
        max-width: 560px !important;
        background: linear-gradient(180deg, #0f172a 0%, #0b1120 100%) !important;
        border: 1px solid rgba(52, 211, 153, 0.35) !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 35px rgba(16, 185, 129, 0.15) !important;
        border-radius: 24px !important;
        padding: 28px 24px !important;
        color: #ffffff !important;
        box-sizing: border-box !important;
        transform: scale(0.95);
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        max-height: 90vh !important;
        overflow-y: auto !important;
    }

    #evAppDownloadModal.ev-modal-visible #evAppModalBox {
        transform: scale(1) !important;
    }

    .ev-badge-pill {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 6px 14px !important;
        background: rgba(16, 185, 129, 0.15) !important;
        border: 1px solid rgba(52, 211, 153, 0.4) !important;
        border-radius: 9999px !important;
        color: #6ee7b7 !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        letter-spacing: 0.05em !important;
        text-transform: uppercase !important;
        margin-bottom: 16px !important;
    }

    .ev-badge-pulse-dot {
        width: 8px !important;
        height: 8px !important;
        border-radius: 50% !important;
        background-color: #10b981 !important;
        box-shadow: 0 0 8px #10b981 !important;
    }

    /* Direct 2 Store Buttons Side-by-Side */
    .ev-buttons-grid {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 12px !important;
        margin-top: 24px !important;
        margin-bottom: 16px !important;
    }

    @media (min-width: 480px) {
        .ev-buttons-grid {
            grid-template-columns: 1fr 1fr !important;
        }
    }

    .ev-store-btn {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        width: 100% !important;
        padding: 12px 16px !important;
        background: #000000 !important;
        border: 1px solid #334155 !important;
        color: #ffffff !important;
        border-radius: 14px !important;
        text-decoration: none !important;
        box-sizing: border-box !important;
        transition: all 0.2s ease !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5) !important;
    }

    .ev-store-btn:hover {
        border-color: #34d399 !important;
        background: #090d16 !important;
        box-shadow: 0 8px 22px rgba(16, 185, 129, 0.25) !important;
        transform: translateY(-1px) !important;
    }

    .ev-store-btn-disabled {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        width: 100% !important;
        padding: 12px 16px !important;
        background: #1e293b !important;
        border: 1px solid #334155 !important;
        color: #94a3b8 !important;
        border-radius: 14px !important;
        box-sizing: border-box !important;
        cursor: not-allowed !important;
    }

    .ev-store-btn-icon {
        flex-shrink: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .ev-store-btn-text {
        display: flex !important;
        flex-direction: column !important;
        text-align: left !important;
        line-height: 1.15 !important;
    }

    .ev-store-btn-sub {
        font-size: 9px !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.06em !important;
        color: #94a3b8 !important;
    }

    .ev-store-btn-main {
        font-size: 15px !important;
        font-weight: 800 !important;
        color: #ffffff !important;
        letter-spacing: -0.01em !important;
    }

    .ev-qr-box {
        display: none !important;
        align-items: center !important;
        gap: 16px !important;
        background: rgba(15, 23, 42, 0.8) !important;
        border: 1px solid rgba(51, 65, 85, 0.6) !important;
        border-radius: 16px !important;
        padding: 12px 16px !important;
        margin-top: 16px !important;
    }

    @media (min-width: 520px) {
        .ev-qr-box {
            display: flex !important;
        }
    }

    /* Step 1: hint pre-release di bawah tombol store */
    .ev-pre-release-hint {
        margin: 0 0 4px 0 !important;
        font-size: 11px !important;
        color: #fbbf24 !important;
        text-align: center !important;
        line-height: 1.45 !important;
    }

    /* Step 2: alur 2 opsi pre-production Android */
    #evAppStepAndroid {
        display: none;
    }

    .ev-back-btn {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        padding: 7px 14px !important;
        background: #1e293b !important;
        border: 1px solid #334155 !important;
        color: #cbd5e1 !important;
        font-size: 12px !important;
        font-weight: 700 !important;
        border-radius: 10px !important;
        cursor: pointer !important;
        margin-bottom: 14px !important;
    }

    .ev-flow-note {
        padding: 12px 14px !important;
        background: rgba(251, 191, 36, 0.08) !important;
        border: 1px solid rgba(251, 191, 36, 0.35) !important;
        border-radius: 14px !important;
        margin-bottom: 12px !important;
    }
    .ev-flow-note strong {
        display: block !important;
        font-size: 13px !important;
        color: #fbbf24 !important;
    }
    .ev-flow-note p {
        margin: 4px 0 0 0 !important;
        font-size: 12px !important;
        color: #cbd5e1 !important;
        line-height: 1.5 !important;
    }

    .ev-option-card {
        padding: 14px !important;
        background: rgba(15, 23, 42, 0.8) !important;
        border: 1px solid rgba(52, 211, 153, 0.3) !important;
        border-radius: 16px !important;
        margin-bottom: 12px !important;
        box-sizing: border-box !important;
    }
    .ev-option-card-email {
        border-color: rgba(96, 165, 250, 0.35) !important;
    }

    .ev-option-head {
        display: flex !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    .ev-option-badge {
        flex-shrink: 0 !important;
        padding: 3px 10px !important;
        border-radius: 9999px !important;
        font-size: 10px !important;
        font-weight: 800 !important;
        letter-spacing: 0.04em !important;
        text-transform: uppercase !important;
    }
    .ev-option-badge-ias {
        background: rgba(16, 185, 129, 0.15) !important;
        border: 1px solid rgba(52, 211, 153, 0.4) !important;
        color: #6ee7b7 !important;
    }
    .ev-option-badge-email {
        background: rgba(96, 165, 250, 0.15) !important;
        border: 1px solid rgba(96, 165, 250, 0.4) !important;
        color: #93c5fd !important;
    }
    .ev-option-title {
        display: block !important;
        font-size: 14px !important;
        color: #ffffff !important;
        line-height: 1.25 !important;
    }
    .ev-option-sub {
        display: block !important;
        margin-top: 2px !important;
        font-size: 11px !important;
        color: #94a3b8 !important;
    }

    .ev-option-steps {
        margin: 12px 0 0 0 !important;
        padding-left: 18px !important;
        text-align: left !important;
    }
    .ev-option-steps li {
        font-size: 12px !important;
        color: #cbd5e1 !important;
        line-height: 1.55 !important;
        margin-bottom: 6px !important;
    }
    .ev-option-steps li:last-child {
        margin-bottom: 0 !important;
    }
    .ev-option-steps strong {
        color: #ffffff !important;
    }

    .ev-option-desc {
        margin: 10px 0 0 0 !important;
        font-size: 12px !important;
        color: #cbd5e1 !important;
        line-height: 1.55 !important;
        text-align: left !important;
    }

    .ev-flow-btn {
        margin-top: 12px !important;
        padding: 10px 16px !important;
        justify-content: center !important;
    }
</style>

<!-- Modal Overlay -->
<div id="evAppDownloadModal"
     data-closable="{{ $setting->is_closable ? 'true' : 'false' }}">

    <!-- Modal Card Box -->
    <div id="evAppModalBox">

        @if($setting->is_closable)
        <!-- Close Button (Only active if is_closable=true) -->
        <button type="button"
                onclick="window.closeEvAppModal()"
                style="position:absolute; top:16px; right:16px; background:#1e293b; border:none; color:#94a3b8; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:18px;">
            &times;
        </button>
        @endif

        <!-- Single Clean Header -->
        <div style="text-align: center;">
            <div class="ev-badge-pill">
                <span class="ev-badge-pulse-dot"></span>
                <span>{{ $setting->badge_text ?? 'Official Mobile App' }}</span>
            </div>

            <div style="display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:8px;">
                <img src="{{ asset('images/logo-files/logo.png') }}"
                     alt="EV Charge Logo"
                     style="width:42px; height:42px; border-radius:12px; background:#1e293b; padding:4px; border:1px solid rgba(52,211,153,0.4); object-fit:contain;"
                     onerror="this.style.display='none';">
                <h2 style="margin:0; font-size:22px; font-weight:800; color:#ffffff; line-height:1.2;">
                    {{ $setting->title }}
                </h2>
            </div>

            <p style="margin:8px 0 0 0; font-size:14px; color:#cbd5e1; line-height:1.5;">
                {{ $setting->subtitle }}
            </p>

            @if($setting->description)
            <p style="margin:8px 0 0 0; font-size:12px; color:#94a3b8; line-height:1.4;">
                {{ $setting->description }}
            </p>
            @endif
        </div>

        <!-- Step 1: pemilih store -->
        <div id="evAppStepStores">

        <!-- Direct 2 Store Buttons Side-by-Side: Left = Google Play, Right = Apple Store -->
        <div class="ev-buttons-grid">

            <!-- Left: Google Play Store -->
            <a id="evPlayStoreBtn"
               href="{{ $setting->android_url ?? 'https://play.google.com/store/apps/details?id=id.sagansa.ev' }}"
               target="_blank"
               rel="noopener noreferrer"
               class="ev-store-btn"
               data-pre-release="{{ $preRelease ? 'true' : 'false' }}">
                <div class="ev-store-btn-icon">
                    <svg viewBox="0 0 512 512" style="width:28px; height:28px;" xmlns="http://www.w3.org/2000/svg">
                        <path fill="#00D3FF" d="M32.5 17.5c-4.2 4.4-6.5 11.2-6.5 20.3v436.4c0 9.1 2.3 15.9 6.5 20.3l1.2 1.1 244.7-244.7v-5.8L33.7 16.4l-1.2 1.1z"/>
                        <path fill="#00F076" d="M359.8 322.2l-81.4-81.4v-5.8l81.4-81.4 1.8 1 96.5 54.8c27.5 15.6 27.5 41.2 0 56.9l-96.5 54.9-1.8 1z"/>
                        <path fill="#FF3A44" d="M361.6 321.2L278.4 238 32.5 483.9c9.1 9.6 24 10.7 41.1 1.1l288-163.8z"/>
                        <path fill="#FFC400" d="M361.6 190.8L73.6 27c-17.1-9.6-32-8.5-41.1 1.1L278.4 274l83.2-83.2z"/>
                    </svg>
                </div>
                <div class="ev-store-btn-text">
                    <span class="ev-store-btn-sub">Temukan di</span>
                    <strong class="ev-store-btn-main">Google Play</strong>
                </div>
            </a>

            <!-- Right: Apple App Store — aktif selama link terisi, terlepas dari dropdown Status iOS -->
            @if(!empty($setting->ios_url))
            <a href="{{ $setting->ios_url }}"
               target="_blank"
               rel="noopener noreferrer"
               class="ev-store-btn">
                <div class="ev-store-btn-icon">
                    <svg viewBox="0 0 170 170" style="width:26px; height:26px;" fill="#ffffff" xmlns="http://www.w3.org/2000/svg">
                        <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.7-3.04-7.7-7.83-12-14.37-6.08-9.13-10.74-19.46-13.98-30.98-3.24-11.53-4.86-22.37-4.86-32.53 0-14.89 3.8-27.18 11.41-36.87 7.6-9.69 17.27-14.65 28.99-14.89 5.33 0 11.09 1.41 17.27 4.23 6.18 2.83 10.12 4.3 11.83 4.41 1.71-.11 5.86-1.63 12.44-4.58 6.58-2.94 12.18-4.23 16.79-3.88 13.04.88 23.47 5.92 31.29 15.13-11.19 6.8-16.66 16.14-16.42 28.02.24 9.47 3.84 17.48 10.8 24.03 6.96 6.56 15.35 10.28 25.17 11.15-2.07 6.31-4.73 12.48-7.98 18.51zM119.22 33.74c0-7.39 2.67-14.35 8.01-20.88 5.34-6.53 11.96-10.72 19.86-12.57.87 7.61-1.46 14.77-6.99 21.48-5.54 6.7-12.5 10.78-20.88 12.23v-.26z"/>
                    </svg>
                </div>
                <div class="ev-store-btn-text">
                    <span class="ev-store-btn-sub">Download on the</span>
                    <strong class="ev-store-btn-main">App Store</strong>
                </div>
            </a>
            @else
            <div class="ev-store-btn-disabled">
                <div class="ev-store-btn-icon">
                    <svg viewBox="0 0 170 170" style="width:26px; height:26px;" fill="#64748b" xmlns="http://www.w3.org/2000/svg">
                        <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.7-3.04-7.7-7.83-12-14.37-6.08-9.13-10.74-19.46-13.98-30.98-3.24-11.53-4.86-22.37-4.86-32.53 0-14.89 3.8-27.18 11.41-36.87 7.6-9.69 17.27-14.65 28.99-14.89 5.33 0 11.09 1.41 17.27 4.23 6.18 2.83 10.12 4.3 11.83 4.41 1.71-.11 5.86-1.63 12.44-4.58 6.58-2.94 12.18-4.23 16.79-3.88 13.04.88 23.47 5.92 31.29 15.13-11.19 6.8-16.66 16.14-16.42 28.02.24 9.47 3.84 17.48 10.8 24.03 6.96 6.56 15.35 10.28 25.17 11.15-2.07 6.31-4.73 12.48-7.98 18.51zM119.22 33.74c0-7.39 2.67-14.35 8.01-20.88 5.34-6.53 11.96-10.72 19.86-12.57.87 7.61-1.46 14.77-6.99 21.48-5.54 6.7-12.5 10.78-20.88 12.23v-.26z"/>
                    </svg>
                </div>
                <div class="ev-store-btn-text">
                    <span class="ev-store-btn-sub" style="color:#fbbf24;">Segera Hadir di</span>
                    <strong class="ev-store-btn-main" style="color:#94a3b8;">App Store</strong>
                </div>
            </div>
            @endif

        </div>

        @if($preRelease)
        <p class="ev-pre-release-hint">
            &#9888;&#65039; Android masih tahap pengujian — ketuk tombol <strong>Google Play</strong> untuk melihat 2 cara memasang: <strong>Internal App Sharing</strong> atau <strong>email closed testing</strong>.
        </p>
        @endif

        <!-- Desktop QR Code — meng-encode halaman pintar /download (deteksi OS ponsel
             pemindai: Android → Google Play, iOS → App Store/Segera Hadir), bukan
             link store mentah, agar satu QR benar untuk semua ponsel. -->
        @if($setting->qr_code_enabled && $setting->android_url)
        <div class="ev-qr-box">
            <div style="background:#ffffff; padding:6px; border-radius:10px; display:flex; flex-shrink:0;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=2&data={{ urlencode(route('app.download')) }}"
                     alt="QR Code Download Aplikasi"
                     style="width:48px; height:48px; display:block;">
            </div>
            <div>
                <strong style="font-size:12px; color:#ffffff; display:block;">
                    ⚡ Scan QR Code dari Ponsel Anda
                </strong>
                <p style="margin:2px 0 0 0; font-size:11px; color:#94a3b8; line-height:1.3;">
                    @if($preRelease)
                        Scan dari HP Android, lalu aktifkan <strong>Internal App Sharing</strong> dulu (lihat Opsi 1 lewat tombol Google Play) bila aplikasi belum terpasang.
                    @else
                        Buka kamera smartphone — link otomatis menyesuaikan ponsel Anda: Android ke Google Play, iOS ke App Store.
                    @endif
                </p>
            </div>
        </div>
        @endif

        </div><!-- /#evAppStepStores -->

        @if($preRelease)
        <!-- Step 2: alur pemasangan pre-production Android — 2 opsi -->
        <div id="evAppStepAndroid">
            <button type="button" class="ev-back-btn" onclick="window.evAppBackToStores()">
                <svg style="width:12px; height:12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </button>

            <div class="ev-flow-note">
                <strong>&#129514; Aplikasi belum rilis publik</strong>
                <p>
                    EV Charge ID untuk Android masih dalam tahap pengujian (closed testing), sehingga belum bisa dicari langsung di Play Store. Pilih salah satu dari 2 cara berikut untuk memasang.
                </p>
            </div>

            <!-- Opsi 1: Internal App Sharing -->
            <div class="ev-option-card">
                <div class="ev-option-head">
                    <span class="ev-option-badge ev-option-badge-ias">Opsi 1</span>
                    <div>
                        <strong class="ev-option-title">Internal App Sharing</strong>
                        <span class="ev-option-sub">Pasang sekarang &mdash; setup sekali saja (&plusmn;2 menit)</span>
                    </div>
                </div>
                <ol class="ev-option-steps">
                    <li>Buka aplikasi <strong>Google Play Store</strong> di HP Anda.</li>
                    <li>Ketuk foto profil di pojok kanan atas, lalu pilih <strong>Setelan</strong>.</li>
                    <li>Buka bagian <strong>Tentang</strong>, lalu ketuk <strong>Versi Play Store</strong> sebanyak <strong>7 kali</strong> secara berurutan hingga muncul notifikasi &ldquo;Anda kini adalah seorang developer&rdquo;.</li>
                    <li>Masuk ke <strong>Setelan &rarr; Umum &rarr; Opsi Developer</strong>.</li>
                    <li>Aktifkan <strong>Berbagi aplikasi internal (Internal app sharing)</strong>, lalu ketuk <strong>Aktifkan</strong> saat jendela konfirmasi muncul.</li>
                </ol>
                <a href="{{ $setting->android_url ?? 'https://play.google.com/store/apps/details?id=id.sagansa.ev' }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="ev-store-btn ev-flow-btn">
                    <div class="ev-store-btn-text" style="align-items:center;">
                        <span class="ev-store-btn-sub">Sudah aktif? Buka link aplikasinya di sini</span>
                        <strong class="ev-store-btn-main">Buka Link Aplikasi</strong>
                    </div>
                </a>
            </div>

            <!-- Opsi 2: Closed Testing via email -->
            <div class="ev-option-card ev-option-card-email">
                <div class="ev-option-head">
                    <span class="ev-option-badge ev-option-badge-email">Opsi 2</span>
                    <div>
                        <strong class="ev-option-title">Ikut Closed Testing via Email</strong>
                        <span class="ev-option-sub">Tanpa setup apa pun &mdash; cukup tunggu undangan</span>
                    </div>
                </div>
                <p class="ev-option-desc">
                    Keberatan mengaktifkan setelan developer di atas? Kirim email berisi alamat Gmail Anda &mdash; kami akan memasukkan Anda ke program closed testing Google Play. Setelah disetujui (kurang lebih 1&times;24 jam), aplikasi bisa diunduh seperti biasa dari Play Store.
                </p>
                @if($supportEmail)
                <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Permintaan Akses Closed Testing — EV Charge ID (Android)') }}&body={{ rawurlencode("Halo Admin EV Charge ID,\n\nSaya ingin dimasukkan ke program closed testing aplikasi EV Charge ID untuk Android.\n\nAlamat Gmail saya: \n\nTerima kasih.") }}"
                   class="ev-store-btn ev-flow-btn">
                    <div class="ev-store-btn-text" style="align-items:center;">
                        <span class="ev-store-btn-sub">Kirim ke {{ $supportEmail }}</span>
                        <strong class="ev-store-btn-main">Kirim Email Permintaan Akses</strong>
                    </div>
                </a>
                @else
                <a href="{{ $waTestingUrl }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="ev-store-btn ev-flow-btn">
                    <div class="ev-store-btn-text" style="align-items:center;">
                        <span class="ev-store-btn-sub">Email kontak belum dikonfigurasi &mdash; chat WhatsApp</span>
                        <strong class="ev-store-btn-main">Minta Akses via WhatsApp</strong>
                    </div>
                </a>
                @endif
            </div>
        </div>
        @endif

        <!-- WhatsApp Partnership & Help Contact -->
        <div style="margin-top:14px; padding:10px 14px; background:rgba(30,41,59,0.7); border:1px solid rgba(52,211,153,0.3); border-radius:14px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-sizing:border-box;">
            <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                <div style="width:32px; height:32px; border-radius:10px; background:#25D366; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:#ffffff; box-shadow:0 2px 8px rgba(37,211,102,0.35);">
                    <svg viewBox="0 0 448 512" style="width:18px; height:18px;" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                    </svg>
                </div>
                <div style="line-height:1.25; overflow:hidden;">
                    <span style="font-size:10px; font-weight:700; color:#6ee7b7; text-transform:uppercase; letter-spacing:0.04em; display:block;">Info Kerjasama & Bantuan</span>
                    <span style="font-size:12px; font-weight:600; color:#e2e8f0; white-space:nowrap; text-overflow:ellipsis; overflow:hidden; display:block;">WhatsApp: 08111923572</span>
                </div>
            </div>
            <a href="{{ $waUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; background:#25D366; color:#022c22; font-size:12px; font-weight:800; border-radius:10px; text-decoration:none; flex-shrink:0; box-shadow:0 3px 10px rgba(37,211,102,0.3); transition:transform 0.15s ease;">
                <span>Chat WA</span>
                <svg style="width:12px; height:12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                </svg>
            </a>
        </div>

        @if(! $setting->is_closable)
        <div style="text-align:center; margin-top:12px;">
            <span style="font-size:11px; color:#64748b; letter-spacing:0.02em;">
                🛡️ Akses peta interaktif & informasi lokasi SPKLU tersedia melalui aplikasi mobile.
            </span>
        </div>
        @endif

    </div>
</div>

<script>
    (function () {
        function activateEvAppModal() {
            var modal = document.getElementById('evAppDownloadModal');
            if (!modal) return;

            var isClosable = modal.getAttribute('data-closable') === 'true';

            window.openEvAppModal = function() {
                modal.classList.add('ev-modal-visible');
                document.body.style.overflow = 'hidden';
            };

            window.closeEvAppModal = function() {
                if (!isClosable) return;
                modal.classList.remove('ev-modal-visible');
                document.body.style.overflow = '';
            };

            // Alur pre-release Android: klik tombol Play Store membuka step 2 (2 opsi),
            // bukan langsung navigasi ke link IAS/store.
            var modalBox = document.getElementById('evAppModalBox');
            var playBtn = document.getElementById('evPlayStoreBtn');
            var stepStores = document.getElementById('evAppStepStores');
            var stepAndroid = document.getElementById('evAppStepAndroid');

            window.evAppBackToStores = function() {
                if (!stepStores || !stepAndroid) return;
                stepAndroid.style.display = 'none';
                stepStores.style.display = 'block';
                if (modalBox) modalBox.scrollTop = 0;
            };

            if (playBtn && stepStores && stepAndroid && playBtn.getAttribute('data-pre-release') === 'true') {
                playBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    stepStores.style.display = 'none';
                    stepAndroid.style.display = 'block';
                    if (modalBox) modalBox.scrollTop = 0;
                });
            }

            // Immediately show modal
            window.openEvAppModal();

            if (isClosable) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        window.closeEvAppModal();
                    }
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        window.closeEvAppModal();
                    }
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', activateEvAppModal);
        } else {
            activateEvAppModal();
        }
    })();
</script>
@endif
