<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OBD2 Feature Toggle (Kill Switch)
    |--------------------------------------------------------------------------
    |
    | Master switch for the OBD2 feature. When false, all /obd/* routes
    | return 404 immediately. Set to true to enable the feature.
    | This can be toggled in production without a deploy.
    |
    */

    'enabled' => env('OBD_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Access Mode
    |--------------------------------------------------------------------------
    |
    | Controls who can see the OBD Monitor menu in the app.
    |
    | Supported: "nonaktif" | "tester" | "semua"
    |   - nonaktif: Everyone is denied (default safe mode)
    |   - tester: Only users in the allowlist are granted
    |   - semua: All authenticated users are granted
    |
    */

    'access_mode' => env('OBD_ACCESS_MODE', 'nonaktif'),

    /*
    |--------------------------------------------------------------------------
    | Allowlist
    |--------------------------------------------------------------------------
    |
    | List of user IDs or emails that are granted access when access_mode
    | is "tester". This is a secondary gate alongside the in-app allowlist.
    |
    */

    'allowlist' => array_filter(array_map('trim', explode(',', env('OBD_ALLOWLIST', '')))),

];
