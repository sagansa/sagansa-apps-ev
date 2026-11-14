@php
    $isComingSoon = ($mode ?? 'landing') === 'ios-coming-soon';

    // Normalisasi nomor WA sama dengan komponen app-download-modal.
    $rawWa = $setting?->whatsapp_number ?? '08111923572';
    $digitsWa = preg_replace('/[^0-9]/', '', $rawWa);
    $cleanWa = str_starts_with($digitsWa, '0') ? ('62' . substr($digitsWa, 1)) : $digitsWa;
    $waUrl = 'https://wa.me/' . $cleanWa;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $setting->title }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: linear-gradient(180deg, #0f172a 0%, #0b1120 100%);
            color: #ffffff;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: linear-gradient(180deg, #0f172a 0%, #0b1120 100%);
            border: 1px solid rgba(52, 211, 153, 0.35);
            border-radius: 24px;
            padding: 28px 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 35px rgba(16, 185, 129, 0.15);
            text-align: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(52, 211, 153, 0.4);
            border-radius: 9999px;
            color: #6ee7b7;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981;
            box-shadow: 0 0 8px #10b981;
        }

        .logo {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: #1e293b;
            padding: 5px;
            border: 1px solid rgba(52, 211, 153, 0.4);
            object-fit: contain;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            line-height: 1.2;
        }

        .subtitle {
            margin: 8px 0 0 0;
            font-size: 14px;
            color: #cbd5e1;
            line-height: 1.5;
        }

        .notice {
            margin: 18px 0 0 0;
            padding: 12px 14px;
            background: rgba(251, 191, 36, 0.08);
            border: 1px solid rgba(251, 191, 36, 0.35);
            border-radius: 14px;
        }

        .notice strong {
            display: block;
            font-size: 13px;
            color: #fbbf24;
        }

        .notice p {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: #cbd5e1;
            line-height: 1.5;
        }

        .picker-label {
            margin: 20px 0 0 0;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #94a3b8;
        }

        .buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 12px;
        }

        @media (min-width: 420px) {
            .buttons { grid-template-columns: 1fr 1fr; }
            .buttons .full-row { grid-column: 1 / -1; }
        }

        .store-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #000000;
            border: 1px solid #334155;
            color: #ffffff;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5);
            text-align: left;
        }

        .store-btn:hover {
            border-color: #34d399;
            background: #090d16;
            box-shadow: 0 8px 22px rgba(16, 185, 129, 0.25);
            transform: translateY(-1px);
        }

        .store-btn.disabled {
            background: #1e293b;
            color: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
        }

        .store-btn .sub {
            display: block;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            line-height: 1.15;
        }

        .store-btn .main {
            display: block;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.01em;
            line-height: 1.15;
        }

        .store-btn.disabled .main { color: #94a3b8; }
        .store-btn.disabled .sub { color: #fbbf24; }

        .footer-note {
            margin: 18px 0 0 0;
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
        }

        .footer-note a {
            color: #6ee7b7;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">
            <span class="dot"></span>
            <span>{{ $setting->badge_text ?? 'Official Mobile App' }}</span>
        </div>

        <img src="{{ asset('images/logo-files/logo.png') }}"
             alt="EV Charge Logo"
             class="logo"
             onerror="this.style.display='none';">
        <h1>{{ $setting->title }}</h1>
        <p class="subtitle">{{ $setting->subtitle }}</p>

        @if($isComingSoon)
        <!-- Pemindai iPhone/iPad saat link iOS belum diisi di panel admin -->
        <div class="notice">
            <strong>&#63743; Versi iOS Sedang Dalam Proses</strong>
            <p>
                Aplikasi EV Charge ID untuk iPhone belum tersedia di App Store.
                Anda akan diberi tahu segera setelah rilis &mdash; untuk saat ini,
                gunakan versi Android di bawah bila memakai perangkat tersebut.
            </p>
        </div>

        @if(filled($setting->android_url))
        <div class="buttons" style="grid-template-columns: 1fr;">
            <a href="{{ $setting->android_url }}"
               target="_blank"
               rel="noopener noreferrer"
               class="store-btn full-row">
                <svg viewBox="0 0 512 512" style="width:26px; height:26px; flex-shrink:0;" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#00D3FF" d="M32.5 17.5c-4.2 4.4-6.5 11.2-6.5 20.3v436.4c0 9.1 2.3 15.9 6.5 20.3l1.2 1.1 244.7-244.7v-5.8L33.7 16.4l-1.2 1.1z"/>
                    <path fill="#00F076" d="M359.8 322.2l-81.4-81.4v-5.8l81.4-81.4 1.8 1 96.5 54.8c27.5 15.6 27.5 41.2 0 56.9l-96.5 54.9-1.8 1z"/>
                    <path fill="#FF3A44" d="M361.6 321.2L278.4 238 32.5 483.9c9.1 9.6 24 10.7 41.1 1.1l288-163.8z"/>
                    <path fill="#FFC400" d="M361.6 190.8L73.6 27c-17.1-9.6-32-8.5-41.1 1.1L278.4 274l83.2-83.2z"/>
                </svg>
                <span>
                    <span class="sub">Tetap unduh versi Android di</span>
                    <span class="main">Google Play</span>
                </span>
            </a>
        </div>
        @endif
        @else
        <!-- Desktop / perangkat tak terdeteksi: pilih store secara manual -->
        <p class="picker-label">Pilih platform perangkat Anda</p>
        <div class="buttons">
            @if(filled($setting->android_url))
            <a href="{{ $setting->android_url }}"
               target="_blank"
               rel="noopener noreferrer"
               class="store-btn">
                <svg viewBox="0 0 512 512" style="width:26px; height:26px; flex-shrink:0;" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#00D3FF" d="M32.5 17.5c-4.2 4.4-6.5 11.2-6.5 20.3v436.4c0 9.1 2.3 15.9 6.5 20.3l1.2 1.1 244.7-244.7v-5.8L33.7 16.4l-1.2 1.1z"/>
                    <path fill="#00F076" d="M359.8 322.2l-81.4-81.4v-5.8l81.4-81.4 1.8 1 96.5 54.8c27.5 15.6 27.5 41.2 0 56.9l-96.5 54.9-1.8 1z"/>
                    <path fill="#FF3A44" d="M361.6 321.2L278.4 238 32.5 483.9c9.1 9.6 24 10.7 41.1 1.1l288-163.8z"/>
                    <path fill="#FFC400" d="M361.6 190.8L73.6 27c-17.1-9.6-32-8.5-41.1 1.1L278.4 274l83.2-83.2z"/>
                </svg>
                <span>
                    <span class="sub">Temukan di</span>
                    <span class="main">Google Play</span>
                </span>
            </a>
            @endif

            @if(filled($setting->ios_url))
            <a href="{{ $setting->ios_url }}"
               target="_blank"
               rel="noopener noreferrer"
               class="store-btn">
                <svg viewBox="0 0 170 170" style="width:24px; height:24px; flex-shrink:0;" fill="#ffffff" xmlns="http://www.w3.org/2000/svg">
                    <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.7-3.04-7.7-7.83-12-14.37-6.08-9.13-10.74-19.46-13.98-30.98-3.24-11.53-4.86-22.37-4.86-32.53 0-14.89 3.8-27.18 11.41-36.87 7.6-9.69 17.27-14.65 28.99-14.89 5.33 0 11.09 1.41 17.27 4.23 6.18 2.83 10.12 4.3 11.83 4.41 1.71-.11 5.86-1.63 12.44-4.58 6.58-2.94 12.18-4.23 16.79-3.88 13.04.88 23.47 5.92 31.29 15.13-11.19 6.8-16.66 16.14-16.42 28.02.24 9.47 3.84 17.48 10.8 24.03 6.96 6.56 15.35 10.28 25.17 11.15-2.07 6.31-4.73 12.48-7.98 18.51zM119.22 33.74c0-7.39 2.67-14.35 8.01-20.88 5.34-6.53 11.96-10.72 19.86-12.57.87 7.61-1.46 14.77-6.99 21.48-5.54 6.7-12.5 10.78-20.88 12.23v-.26z"/>
                </svg>
                <span>
                    <span class="sub">Download on the</span>
                    <span class="main">App Store</span>
                </span>
            </a>
            @else
            <div class="store-btn disabled">
                <svg viewBox="0 0 170 170" style="width:24px; height:24px; flex-shrink:0;" fill="#64748b" xmlns="http://www.w3.org/2000/svg">
                    <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.7-3.04-7.7-7.83-12-14.37-6.08-9.13-10.74-19.46-13.98-30.98-3.24-11.53-4.86-22.37-4.86-32.53 0-14.89 3.8-27.18 11.41-36.87 7.6-9.69 17.27-14.65 28.99-14.89 5.33 0 11.09 1.41 17.27 4.23 6.18 2.83 10.12 4.3 11.83 4.41 1.71-.11 5.86-1.63 12.44-4.58 6.58-2.94 12.18-4.23 16.79-3.88 13.04.88 23.47 5.92 31.29 15.13-11.19 6.8-16.66 16.14-16.42 28.02.24 9.47 3.84 17.48 10.8 24.03 6.96 6.56 15.35 10.28 25.17 11.15-2.07 6.31-4.73 12.48-7.98 18.51zM119.22 33.74c0-7.39 2.67-14.35 8.01-20.88 5.34-6.53 11.96-10.72 19.86-12.57.87 7.61-1.46 14.77-6.99 21.48-5.54 6.7-12.5 10.78-20.88 12.23v-.26z"/>
                </svg>
                <span>
                    <span class="sub">Segera Hadir di</span>
                    <span class="main">App Store</span>
                </span>
            </div>
            @endif
        </div>
        @endif

        <p class="footer-note">
            Butuh bantuan? <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer">Chat WhatsApp</a>
        </p>
    </div>
</body>
</html>
