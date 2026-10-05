<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    | "Jewish Library" is the default name. Everything shown to
    | visitors comes from here so that a rename needs no code changes.
    */

    'name' => env('LIBRARY_NAME', env('APP_NAME', 'Jewish Library')),
    'short_name' => env('LIBRARY_SHORT_NAME', 'Jewish Library'),
    'contact_email' => env('LIBRARY_CONTACT_EMAIL'),
    'rights_email' => env('LIBRARY_RIGHTS_EMAIL', env('LIBRARY_CONTACT_EMAIL')),
    'theme_color' => env('LIBRARY_THEME_COLOR', '#6d1a2d'),

    /*
    |--------------------------------------------------------------------------
    | Interface themes
    |--------------------------------------------------------------------------
    | Offered on the settings page, in this order. Each key has a matching
    | block of colours in resources/css/app.css and a name in lang/<locale>/ui.php.
    | "color" is the browser toolbar colour, "scheme" the CSS color-scheme.
    */

    'themes' => [
        'auto' => ['color' => '#6d1a2d', 'scheme' => 'light dark'],
        'noir' => ['color' => '#0a0a0b', 'scheme' => 'dark'],
        'ivory' => ['color' => '#f7f5ef', 'scheme' => 'light'],
        'contrast' => ['color' => '#000000', 'scheme' => 'dark'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Interface locales
    |--------------------------------------------------------------------------
    | Interface languages need a lang/<locale>.json file. Book and metadata
    | languages are rows in the `languages` table and need no deployment.
    */

    'ui_locales' => array_filter(array_map('trim', explode(',', env('LIBRARY_UI_LOCALES', 'en,ru,he')))),
    'rtl_locales' => ['he', 'ar', 'fa', 'ur', 'yi'],
    'locale_names' => [
        'en' => 'English',
        'ru' => 'Русский',
        'he' => 'עברית',
    ],

    /*
    |--------------------------------------------------------------------------
    | Email-dependent flows
    |--------------------------------------------------------------------------
    | Password reset and email verification are only offered when this is true.
    | Leave false until SMTP has been configured and tested on the host.
    */

    'mail_enabled' => (bool) env('LIBRARY_MAIL_ENABLED', false),

    // Administrators are sent to enable two-factor authentication before they
    // can use the administration area. Editors may enable it voluntarily.
    'admin_require_2fa' => (bool) env('LIBRARY_ADMIN_REQUIRE_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Book content origin
    |--------------------------------------------------------------------------
    | Optional credential-free origin (e.g. https://content.example.org) that
    | points at the same application. When set, sanitized book resources are
    | only served from that host and never receive session cookies.
    */

    'content_origin' => env('LIBRARY_CONTENT_ORIGIN'),

    'storage_disk' => env('LIBRARY_STORAGE_DISK', 'library'),

    'imports' => [
        'max_upload_bytes' => (int) env('LIBRARY_MAX_UPLOAD_MB', 64) * 1024 * 1024,
        'max_cover_bytes' => 8 * 1024 * 1024,
        'max_cover_pixels' => 40_000_000,
        'zip_max_entries' => (int) env('LIBRARY_ZIP_MAX_ENTRIES', 5000),
        'zip_max_expanded_bytes' => (int) env('LIBRARY_ZIP_MAX_EXPANDED_MB', 512) * 1024 * 1024,
        'zip_max_entry_bytes' => 96 * 1024 * 1024,
        'zip_max_ratio' => (int) env('LIBRARY_ZIP_MAX_RATIO', 200),
        'xml_max_bytes' => 16 * 1024 * 1024,
        'stale_hours' => 24,
    ],

    'downloads' => [
        'per_minute' => (int) env('LIBRARY_DOWNLOADS_PER_MINUTE', 30),
    ],

    'search' => [
        'fallback_limit' => 200,
        'per_page' => 24,
    ],

    'kindle' => [
        'send_to_kindle_url' => 'https://www.amazon.com/sendtokindle',
    ],

    'worker' => [
        // Seconds one cron-launched queue run may last. Keep below the cron interval.
        'max_time' => (int) env('LIBRARY_WORKER_MAX_TIME', 50),
        'job_timeout' => 40,
        'tries' => 3,
        'backoff' => [60, 300],
    ],
];
