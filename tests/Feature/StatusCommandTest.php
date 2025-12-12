<?php

use Illuminate\Support\Facades\File;
use Headwires\TranslatorClient\Commands\StatusCommand;

test('status command shows sync information', function () {
    // Use the configured metadata path from TestCase
    $metaDir = config('translator-client.metadata_path');

    // Clean up if exists
    if (File::exists($metaDir)) {
        File::deleteDirectory($metaDir);
    }

    File::makeDirectory($metaDir, 0755, true);

    $syncData = [
        'synced_at' => now()->subHours(2)->toIso8601String(),
        'version' => '1.2.3',
        'locales' => ['en', 'es'],
    ];

    File::put("{$metaDir}/last_sync.json", json_encode($syncData, JSON_PRETTY_PRINT));

    File::put("{$metaDir}/en.meta", json_encode([
        'checksum' => 'md5:abc123',
        'synced_at' => now()->subHours(2)->toIso8601String(),
    ], JSON_PRETTY_PRINT));

    config()->set('translator-client.api_key', 'abc123000000xyz789');

    $this->artisan('translator:status')
        ->expectsOutput('Translation Sync Status')
        ->expectsOutputToContain('API Key')
        ->expectsOutputToContain('Last Sync')
        ->assertExitCode(0);

    // Clean up
    File::deleteDirectory($metaDir);
});

test('status command shows never synced message', function () {
    config([
        'translator-client.api_key' => 'test-key',
        'translator-client.metadata_path' => base_path('tests/fixtures/empty'),
    ]);

    $this->artisan('translator:status')
        ->expectsOutput('Translation Sync Status')
        ->expectsOutputToContain('Never synced')
        ->assertExitCode(0);
});
