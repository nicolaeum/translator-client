<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    |
    | Define the projects to sync translations from. Each project has:
    | - name: A unique identifier for the project (used with --project option)
    | - api_key: The project's API key from Headwires Translator
    | - path: Where to store translation files (absolute path)
    | - scan_paths: Paths to scan for hardcoded strings (used by translator:scan)
    |
    | For main app translations, use resource_path('lang').
    | For package translations, use the vendor path, e.g.:
    | base_path('vendor/iworking/iworking-boilerplate/resources/lang')
    |
    | Locales are automatically fetched from each project's manifest.
    |
    */
    'projects' => [
        [
            'name' => env('TRANSLATOR_PROJECT_NAME', 'main'),
            'api_key' => env('TRANSLATOR_API_KEY'),
            'path' => resource_path('lang'),
            'scan_paths' => [
                'resources/views',
                'app',
            ],
        ],
        // Example: Package translations
        // [
        //     'name' => 'boilerplate',
        //     'api_key' => env('TRANSLATOR_PACKAGE_API_KEY'),
        //     'path' => base_path('vendor/vendor-name/package-name/resources/lang'),
        //     'scan_paths' => [
        //         'vendor/vendor-name/package-name/resources/views',
        //         'vendor/vendor-name/package-name/src',
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Mode
    |--------------------------------------------------------------------------
    |
    | Determines how translations are loaded:
    | - 'static': File-based, requires sync command or webhook
    | - 'live': Cache-based with instant updates via webhooks
    | - 'auto': Detects best mode (live for Vapor/serverless, static otherwise)
    |
    */
    'mode' => env('TRANSLATOR_CLIENT_MODE', 'static'),

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
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Used for live mode and caching manifests.
    |
    */
    'cache' => [
        'driver' => env('TRANSLATOR_CLIENT_CACHE_DRIVER', 'file'),
        'prefix' => env('TRANSLATOR_CLIENT_CACHE_PREFIX', 'translator'),
        'ttl' => (int) env('TRANSLATOR_CLIENT_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Path
    |--------------------------------------------------------------------------
    |
    | Path for storing sync metadata (checksums, timestamps).
    | Only used in static mode.
    |
    */
    'metadata_path' => env('TRANSLATOR_METADATA_PATH', storage_path('translator-client')),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Webhook endpoint for receiving translation update notifications.
    | Each project in Headwires Translator should have a webhook configured
    | pointing to this endpoint. The webhook payload includes the api_key
    | to identify which project was updated.
    |
    */
    'webhook' => [
        'enabled' => (bool) env('TRANSLATOR_CLIENT_WEBHOOK_ENABLED', true),
        'route' => env('TRANSLATOR_CLIENT_WEBHOOK_ROUTE', '/api/translator/webhook'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the translator:scan command that finds hardcoded
    | strings in your codebase.
    |
    */
    'scanner' => [
        // Directories to exclude from scanning
        'excluded_directories' => [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
        ],
    ],
];
