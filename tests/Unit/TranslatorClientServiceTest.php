<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Headwires\TranslatorClient\TranslatorClientService;

beforeEach(function () {
    $this->service = new TranslatorClientService();

    // Fake storage
    Storage::fake('local');

    // Use temp directory for metadata in tests
    $this->metadataPath = sys_get_temp_dir() . '/translator-client-test-' . uniqid();

    // Set test config
    config([
        'translator-client.api_key' => 'test-api-key-123',
        'translator-client.cdn_url' => 'https://cdn.test.com',
        'translator-client.metadata_path' => $this->metadataPath,
    ]);
});

test('it fetches manifest from CDN', function () {
    $manifestData = [
        'project' => ['slug' => 'test-project', 'version' => '1.0.0'],
        'locales' => ['en', 'es'],
        'base_locale' => 'en',
        'files' => [
            'en' => [
                'url' => 'en.json',
                'checksum' => 'md5:abc123',
                'size' => 1024,
            ],
        ],
    ];

    Http::fake([
        '*/manifest.json' => Http::response(json_encode($manifestData), 200, ['Content-Type' => 'application/json']),
    ]);

    $manifest = $this->service->fetchManifest();

    expect($manifest)->toBeArray()
        ->and($manifest['project']['slug'])->toBe('test-project')
        ->and($manifest['locales'])->toContain('en', 'es');
});

test('it throws exception when manifest fetch fails', function () {
    Http::fake([
        '*/manifest.json' => Http::response(null, 404),
    ]);

    $this->service->fetchManifest();
})->throws(\Exception::class, 'Failed to fetch manifest');

test('it fetches locale file from CDN', function () {
    $translations = [
        'messages.welcome' => 'Welcome',
        'messages.goodbye' => 'Goodbye',
    ];

    Http::fake([
        '*/en.json' => Http::response(json_encode($translations), 200, ['Content-Type' => 'application/json']),
    ]);

    $result = $this->service->fetchLocale('en');

    expect($result)->toBeArray()
        ->and($result['messages.welcome'])->toBe('Welcome');
});

test('it verifies checksum when enabled', function () {
    $translations = ['key' => 'value'];
    $content = json_encode($translations);
    $correctChecksum = 'md5:' . md5($content);

    Http::fake([
        '*/en.json' => Http::response($content, 200, ['Content-Type' => 'application/json']),
    ]);

    $result = $this->service->fetchLocale('en', $correctChecksum);

    expect($result)->toBe($translations);
});

test('it throws exception on checksum mismatch', function () {
    Http::fake([
        '*/en.json' => Http::response(json_encode(['key' => 'value']), 200, ['Content-Type' => 'application/json']),
    ]);

    $this->service->fetchLocale('en', 'md5:wrong-checksum');
})->throws(\Exception::class, 'Checksum mismatch');

test('it gets local checksum from metadata', function () {
    // Create fake metadata file
    $metaPath = config('translator-client.metadata_path') . '/en.meta';

    if (!file_exists(dirname($metaPath))) {
        mkdir(dirname($metaPath), 0755, true);
    }

    file_put_contents($metaPath, json_encode([
        'checksum' => 'md5:stored-checksum',
        'synced_at' => now()->toIso8601String(),
    ]));

    $checksum = $this->service->getLocalChecksum('en');

    expect($checksum)->toBe('md5:stored-checksum');
});

test('it returns null when no local metadata exists', function () {
    $checksum = $this->service->getLocalChecksum('nonexistent');

    expect($checksum)->toBeNull();
});

test('it saves locale metadata to file', function () {
    $fileData = [
        'checksum' => 'md5:abc123',
        'last_modified' => '2025-11-24T10:00:00Z',
        'size' => 1024,
    ];

    $this->service->saveMetadata('en', $fileData);

    $metaPath = config('translator-client.metadata_path') . '/en.meta';
    expect(File::exists($metaPath))->toBeTrue();

    $saved = json_decode(File::get($metaPath), true);
    expect($saved['checksum'])->toBe('md5:abc123')
        ->and($saved)->toHaveKey('synced_at')
        ->and($saved['remote_modified'])->toBe('2025-11-24T10:00:00Z')
        ->and($saved['size'])->toBe(1024);
});

test('it updates global metadata with manifest info', function () {
    $manifest = [
        'project' => ['version' => '1.2.3'],
        'files' => [
            'en' => ['checksum' => 'abc'],
            'es' => ['checksum' => 'def'],
        ],
    ];

    $this->service->updateGlobalMetadata($manifest);

    $lastSync = $this->service->getLastSync();
    expect($lastSync)->toBeArray()
        ->and($lastSync['version'])->toBe('1.2.3')
        ->and($lastSync['locales'])->toContain('en', 'es');
});

test('it returns last sync information', function () {
    $manifest = [
        'project' => ['version' => '2.0.0'],
        'files' => ['en' => []],
    ];

    $this->service->updateGlobalMetadata($manifest);

    $lastSync = $this->service->getLastSync();
    expect($lastSync)->not->toBeNull()
        ->and($lastSync['version'])->toBe('2.0.0');
});
