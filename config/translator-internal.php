<?php

/**
 * Internal configuration for the Translator Client package.
 *
 * This file contains service-level settings that should NOT be modified
 * by end users. These values are set by the package maintainers.
 *
 * For development/testing, you can override these via .env variables.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Headwires Translator API URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Headwires Translator API. This is used by the
    | scanner to send candidates for analysis and storage.
    |
    | Override via CLI_TRANSLATOR_API_URL in .env for local development.
    |
    */
    'api_url' => env('CLI_TRANSLATOR_API_URL', 'https://api.langsyncer.com'),

    /*
    |--------------------------------------------------------------------------
    | CDN URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the CDN where translation files are hosted.
    |
    | Override via CLI_TRANSLATOR_CDN_URL in .env for local development.
    |
    */
    'cdn_url' => env('CLI_TRANSLATOR_CDN_URL', 'https://cdn.langsyncer.com'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to CDN.
    |
    */
    'http_timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Scanner Settings
    |--------------------------------------------------------------------------
    |
    | Technical settings for the scanner API communication.
    |
    */
    'scanner' => [
        'api_timeout' => 300,
        'chunk_size' => 100,
    ],
];
