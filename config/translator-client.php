<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Your project API key from the Headwires Translator SaaS platform.
    | Find this in your project settings under "API Key & CDN".
    |
    */
    'api_key' => env('TRANSLATOR_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | CDN URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the CDN where translation files are hosted.
    |
    */
    'cdn_url' => env('TRANSLATOR_CDN_URL', 'https://cdn.headwires-translator.com'),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'driver' => env('TRANSLATOR_CLIENT_CACHE_DRIVER', 'redis'),
        'prefix' => env('TRANSLATOR_CLIENT_CACHE_PREFIX', 'translations'),

        // TTL fallback if webhooks fail (default: 5 minutes = 300 seconds)
        // Set to 0 to rely only on webhooks (cache forever)
        // Legacy TRANSLATOR_CACHE_TTL still supported
        'ttl' => (int) env('TRANSLATOR_CLIENT_CACHE_TTL', env('TRANSLATOR_CACHE_TTL', 300)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of locales to sync from the CDN.
    |
    */
    'locales' => array_filter(explode(',', env('TRANSLATOR_LOCALES', 'en'))),

    /*
    |--------------------------------------------------------------------------
    | Storage Mode
    |--------------------------------------------------------------------------
    |
    | Determines how translations are loaded:
    | - 'auto': Detects best mode (live for Vapor, can be live anywhere)
    | - 'live': Cache-based with instant updates (works everywhere)
    | - 'static': File-based, requires deployments (legacy)
    |
    | Legacy values still supported:
    | - 'file' → mapped to 'static'
    | - 'cache' → mapped to 'live'
    |
    */
    'mode' => env('TRANSLATOR_CLIENT_MODE', env('TRANSLATOR_STORAGE_MODE', 'auto')),

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Base path for storing translation files (file mode only).
    | Default: resources/lang
    |
    */
    'storage_path' => env('TRANSLATOR_STORAGE_PATH', resource_path('lang')),

    /*
    |--------------------------------------------------------------------------
    | Sync Strategy
    |--------------------------------------------------------------------------
    |
    | Determines how translations are handled during sync:
    | - 'overwrite': Replaces local files completely with CDN version (default)
    | - 'merge': Merges CDN translations with local, preserving local-only keys
    |            CDN values take precedence for existing keys (recursive merge)
    |
    */
    'sync_strategy' => env('TRANSLATOR_SYNC_STRATEGY', 'overwrite'),

    /*
    |--------------------------------------------------------------------------
    | Metadata Path
    |--------------------------------------------------------------------------
    |
    | Path for storing sync metadata (checksums, timestamps).
    | Default: storage/translator-client
    |
    */
    'metadata_path' => env('TRANSLATOR_METADATA_PATH', storage_path('translator-client')),

    /*
    |--------------------------------------------------------------------------
    | Auto Sync
    |--------------------------------------------------------------------------
    |
    | Automatically sync translations on application boot.
    | Not recommended for production (use deploy scripts instead).
    |
    */
    'auto_sync' => (bool) env('TRANSLATOR_AUTO_SYNC', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to CDN.
    |
    */
    'http_timeout' => (int) env('TRANSLATOR_HTTP_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Verify Checksums
    |--------------------------------------------------------------------------
    |
    | Verify file integrity using MD5 checksums after download.
    |
    */
    'verify_checksums' => (bool) env('TRANSLATOR_VERIFY_CHECKSUMS', true),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration (Auto-Configured)
    |--------------------------------------------------------------------------
    |
    | Webhooks are auto-configured from your API key.
    | The package automatically:
    | - Extracts webhook secret from API key
    | - Registers webhook route at /api/translator/webhook
    | - Validates signatures
    | - Clears cache on updates
    |
    */
    'webhook' => [
        // Enable webhook auto-registration
        'enabled' => (bool) env('TRANSLATOR_CLIENT_WEBHOOK_ENABLED', true),

        // Route path for webhook endpoint
        'route' => env('TRANSLATOR_CLIENT_WEBHOOK_ROUTE', '/api/translator/webhook'),

        // Pre-warm cache after invalidation
        'prewarm' => (bool) env('TRANSLATOR_CLIENT_WEBHOOK_PREWARM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Mode Configuration
    |--------------------------------------------------------------------------
    */
    'live' => [
        // Deploy-time warmup (recommended for production)
        'warmup_on_deploy' => (bool) env('TRANSLATOR_CLIENT_LIVE_WARMUP', true),

        // Aggressive pre-warming (fetches all locales/groups on first request)
        'aggressive_prewarm' => (bool) env('TRANSLATOR_CLIENT_LIVE_AGGRESSIVE_PREWARM', false),
    ],
];
