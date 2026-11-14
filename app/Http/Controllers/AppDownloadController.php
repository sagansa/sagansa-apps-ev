<?php

namespace App\Http\Controllers;

use App\Models\AppDownloadSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman pintar tujuan QR code download app (route /download).
 *
 * QR di popup desktop meng-encode URL halaman ini — bukan link store mentah —
 * karena gambar QR statis tidak bisa menyesuaikan isi berdasarkan ponsel yang
 * memindai. Deteksi OS dilakukan DI SINI saat link dibuka, dari User-Agent:
 * Android → link Android (Play Store/IAS), iOS → link iOS (App Store/TestFlight)
 * atau halaman "Segera Hadir" selama link belum diisi, desktop → landing
 * dengan kedua tombol store.
 */
class AppDownloadController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $setting = AppDownloadSetting::current();
        $ua = $request->userAgent() ?? '';

        $isAndroid = preg_match('/Android/i', $ua) === 1;

        // iPad di iPadOS 13+ secara default melaporkan UA Mac desktop; token
        // "Touch" adalah satu-satunya pembeda yang tersedia di UA itu.
        $isIos = preg_match('/iPhone|iPad|iPod/i', $ua) === 1
            || (preg_match('/Macintosh/i', $ua) === 1 && str_contains($ua, 'Touch'));

        if ($isAndroid && filled($setting->android_url)) {
            return redirect()->away($setting->android_url);
        }

        if ($isIos) {
            if (filled($setting->ios_url)) {
                return redirect()->away($setting->ios_url);
            }

            return response()->view('app-download', [
                'mode' => 'ios-coming-soon',
                'setting' => $setting,
            ]);
        }

        // Desktop / UA tak dikenal / Android tanpa link: landing dua tombol.
        return response()->view('app-download', [
            'mode' => 'landing',
            'setting' => $setting,
        ]);
    }
}
