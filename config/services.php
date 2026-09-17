<?php

return [

    'google' => [
        // Web client ID (server-side). Prefer GOOGLE_WEB_CLIENT_ID; fall back to GOOGLE_CLIENT_ID.
        'client_id' => env('GOOGLE_WEB_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        // Per-platform client IDs — accepted as alternative ID-token audiences so mobile
        // clients (which issue tokens with their own client ID) pass verification.
        'android_client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
        'ios_client_id' => env('GOOGLE_IOS_CLIENT_ID'),
    ],

    'apple' => [
        // Services ID — audience untuk web flow (Sign in with Apple JS).
        'service_id' => env('APPLE_SERVICE_ID'),
        // App bundle ID — audience untuk native iOS Sign in with Apple
        // (ASAuthorizationAppleIDProvider), yang token-nya ber-`aud` bundle ID.
        'bundle_id' => env('APPLE_BUNDLE_ID'),
        // Kredensial App Store Server API (verifikasi transaksi server-side).
        // OPTIONAL: bila kosong, register-apple memercayai klaim client (dev).
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key_path' => env('APPLE_PRIVATE_KEY_PATH'),
        'store_api_sandbox' => env('APPLE_STORE_API_SANDBOX', true),
    ],

    'google_play' => [
        // Package name (Android application ID) — dipakai verifikasi purchase.
        'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
        // OPTIONAL: path ke service account JSON untuk Play Developer API.
        'service_account_json' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON'),
    ],

    'admob' => [
        'client_id' => env('ADMOB_WEB_CLIENT_ID', 'ca-pub-3940256099942544'),
        'banner_slot' => env('ADMOB_WEB_BANNER_SLOT', '6300978111'),
        'enabled' => env('ADMOB_ENABLED', true),
    ],

    'openchargemap' => [
        'key' => env('OCM_API_KEY'),
        'base_url' => env('OCM_BASE_URL', 'https://api.openchargemap.io/v3/poi/'),
    ],

];
