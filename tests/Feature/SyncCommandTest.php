<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Headwires\TranslatorClient\Commands\SyncCommand;

beforeEach(function () {
    config([
        'translator-client.api_key' => 'test-key',
        'translator-client.cdn_url' => 'https://cdn.test.com',
        'translator-client.storage_path' => base_path('tests/fixtures/lang'),
        'translator-client.metadata_path' => base_path('tests/fixtures/metadata'),
        'translator-client.locales' => ['en', 'es'],
    ]);

    // Clean up test directories
    if (File::exists(base_path('tests/fixtures/lang'))) {
        File::deleteDirectory(base_path('tests/fixtures/lang'));
    }
    if (File::exists(base_path('tests/fixtures/metadata'))) {
        File::deleteDirectory(base_path('tests/fixtures/metadata'));
    }
});

test('sync command downloads and saves translations', function () {
    $manifestData = [
        'project' => ['slug' => 'test', 'version' => '1.0.0'],
        'locales' => ['en', 'es'],
        'base_locale' => 'en',
        'files' => [
            'en' => ['url' => 'en.json', 'checksum' => 'md5:abc123', 'size' => 100],
            'es' => ['url' => 'es.json', 'checksum' => 'md5:def456', 'size' => 110],
        ],
    ];

    $enTranslations = ['messages.welcome' => 'Welcome'];
    $esTranslations = ['messages.welcome' => 'Bienvenido'];

    Http::fake([
        'https://cdn.test.com/projects/test-key/manifest.json' => Http::response($manifestData, 200),
        'https://cdn.test.com/projects/test-key/en.json' => Http::response($enTranslations, 200),
        'https://cdn.test.com/projects/test-key/es.json' => Http::response($esTranslations, 200),
    ]);

    $this->artisan('translator:sync')
        ->expectsOutput('Fetching manifest...')
        ->expectsOutput('Syncing locale: en')
        ->expectsOutput('Syncing locale: es')
        ->expectsOutput('Sync completed successfully!')
        ->assertExitCode(0);

    // Verify files were created
    expect(File::exists(base_path('tests/fixtures/lang/en/messages.php')))->toBeTrue()
        ->and(File::exists(base_path('tests/fixtures/lang/es/messages.php')))->toBeTrue();
});

test('sync command handles single locale option', function () {
    Http::fake([
        'https://cdn.test.com/projects/test-key/manifest.json' => Http::response([
            'project' => ['slug' => 'test', 'version' => '1.0.0'],
            'files' => ['en' => ['checksum' => 'md5:abc', 'size' => 100]],
        ], 200),
        'https://cdn.test.com/projects/test-key/en.json' => Http::response(['key' => 'value'], 200),
    ]);

    $this->artisan('translator:sync', ['--locale' => 'en'])
        ->expectsOutput('Syncing locale: en')
        ->assertExitCode(0);
});

test('sync command handles force option', function () {
    // Create existing metadata to test force bypass
    $metaDir = base_path('tests/fixtures/metadata');
    File::makeDirectory($metaDir, 0755, true);
    File::put("{$metaDir}/en.meta", json_encode(['checksum' => 'md5:old']));

    Http::fake([
        'https://cdn.test.com/projects/test-key/manifest.json' => Http::response([
            'project' => ['version' => '1.0.0'],
            'files' => ['en' => ['checksum' => 'md5:old', 'size' => 100]], // Same checksum
        ], 200),
        'https://cdn.test.com/projects/test-key/en.json' => Http::response(['key' => 'new'], 200),
    ]);

    // Without --force, should skip (same checksum)
    $this->artisan('translator:sync')
        ->assertExitCode(0);

    // With --force, should download
    $this->artisan('translator:sync', ['--force' => true])
        ->expectsOutput('Syncing locale: en')
        ->assertExitCode(0);
});

test('generated files include warning header', function () {
    $manifestData = [
        'project' => ['slug' => 'test-project', 'version' => '1.0.0'],
        'locales' => ['en'],
        'files' => [
            'en' => ['url' => 'en.json', 'checksum' => 'md5:abc123', 'size' => 100],
        ],
    ];

    $translations = [
        'auth.failed' => 'These credentials do not match our records.',
        'auth.throttle' => 'Too many login attempts.',
    ];

    Http::fake([
        'https://cdn.test.com/projects/test-key/manifest.json' => Http::response($manifestData, 200),
        'https://cdn.test.com/projects/test-key/en.json' => Http::response($translations, 200),
    ]);

    $this->artisan('translator:sync')
        ->assertExitCode(0);

    $filePath = base_path('tests/fixtures/lang/en/auth.php');
    expect(File::exists($filePath))->toBeTrue();

    $content = File::get($filePath);

    // Verify warning header is present
    expect($content)->toContain('⚠️  AUTO-GENERATED FILE - DO NOT EDIT MANUALLY')
        ->and($content)->toContain('Headwires Translator Client')
        ->and($content)->toContain('Any manual changes to this file will be OVERWRITTEN')
        ->and($content)->toContain('To add custom translations:')
        ->and($content)->toContain('Create a separate file (e.g., custom.php)')
        ->and($content)->toContain('Locale: en')
        ->and($content)->toContain('File: auth.php')
        ->and($content)->toContain('php artisan translator:sync');

    // Verify translations are still present
    $loadedTranslations = include $filePath;
    expect($loadedTranslations)
        ->toHaveKey('failed')
        ->toHaveKey('throttle');
});
