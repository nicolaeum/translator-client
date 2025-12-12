<?php

namespace Headwires\TranslatorClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Headwires\TranslatorClient\TranslatorClientService;

class SyncCommand extends Command
{
    protected $signature = 'translator:sync
                            {--locale= : Sync specific locale only}
                            {--force : Force sync even if checksums match}
                            {--verify : Verify checksums after download}';

    protected $description = 'Sync translations from CDN';

    public function handle(TranslatorClientService $service): int
    {
        try {
            $this->info('Fetching manifest...');
            $manifest = $service->fetchManifest();

            $locales = $this->option('locale')
                ? [$this->option('locale')]
                : config('translator-client.locales', []);

            foreach ($locales as $locale) {
                $this->syncLocale($service, $locale, $manifest);
            }

            // Update global metadata
            $service->updateGlobalMetadata($manifest);

            $this->info('Sync completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function syncLocale(
        TranslatorClientService $service,
        string $locale,
        array $manifest
    ): void {
        $this->info("Syncing locale: {$locale}");

        if (!isset($manifest['files'][$locale])) {
            $this->warn("Locale {$locale} not found in manifest. Skipping.");
            return;
        }

        $fileData = $manifest['files'][$locale];
        $remoteChecksum = $fileData['checksum'] ?? null;
        $localChecksum = $service->getLocalChecksum($locale);

        // Skip if checksums match (unless --force)
        if (!$this->option('force') && $localChecksum === $remoteChecksum) {
            $this->line("  ↳ Up to date (checksum matches)");
            return;
        }

        // Fetch translations
        $expectedChecksum = $this->option('verify') ? $remoteChecksum : null;
        $translations = $service->fetchLocale($locale, $expectedChecksum);

        // Save translations
        $this->saveTranslations($locale, $translations);

        // Save metadata
        $service->saveMetadata($locale, $fileData);

        $count = count($translations);
        $this->line("  ↳ Downloaded {$count} translation keys");
    }

    protected function saveTranslations(string $locale, array $translations): void
    {
        $mode = config('translator-client.storage_mode', 'file');

        if ($mode === 'cache') {
            $this->saveToCache($locale, $translations);
        } else {
            $this->saveToFiles($locale, $translations);
        }
    }

    protected function saveToFiles(string $locale, array $translations): void
    {
        // Group by file (first part of dot notation)
        $grouped = [];

        foreach ($translations as $key => $value) {
            if (str_contains($key, '.')) {
                [$file, $subKey] = explode('.', $key, 2);
            } else {
                $file = 'messages';
                $subKey = $key;
            }

            $grouped[$file][$subKey] = $value;
        }

        // Write each file
        $basePath = config('translator-client.storage_path');

        foreach ($grouped as $file => $data) {
            $path = "{$basePath}/{$locale}/{$file}.php";

            // Create directory if needed
            if (!File::exists(dirname($path))) {
                File::makeDirectory(dirname($path), 0755, true);
            }

            $content = $this->generateFileContent($data, $locale, $file);
            File::put($path, $content);
        }
    }

    /**
     * Generate file content with warning header
     */
    protected function generateFileContent(array $data, string $locale, string $file): string
    {
        $timestamp = now()->toDateTimeString();
        $apiKey = config('translator-client.api_key');
        $projectId = substr($apiKey, 0, 8) . '...';

        return <<<PHP
<?php

/**
 * ⚠️  AUTO-GENERATED FILE - DO NOT EDIT MANUALLY
 *
 * This translation file is automatically generated and managed by
 * Headwires Translator Client (https://translator.headwires.com)
 *
 * Any manual changes to this file will be OVERWRITTEN on the next sync.
 *
 * To add custom translations:
 *   - Create a separate file (e.g., custom.php) for your local translations
 *   - Or add translations through the Headwires Translator dashboard
 *
 * Sync Information:
 *   - Locale: {$locale}
 *   - File: {$file}.php
 *   - Project: {$projectId}
 *   - Last Sync: {$timestamp}
 *
 * To sync translations, run:
 *   php artisan translator:sync
 */

return {$this->varExportFormatted($data)};

PHP;
    }

    /**
     * Format var_export output for better readability
     */
    protected function varExportFormatted(array $data, int $indent = 0): string
    {
        $export = var_export($data, true);

        // Format array for better readability
        $export = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $export);
        $export = str_replace(['array (', '  '], ['[', '    '], $export);
        $export = str_replace(')', ']', $export);

        return $export;
    }

    protected function saveToCache(string $locale, array $translations): void
    {
        $ttl = config('translator-client.cache_ttl', 3600);
        cache()->put("translator.{$locale}", $translations, $ttl);
    }
}
