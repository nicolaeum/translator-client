<?php

namespace Headwires\TranslatorClient\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncService
{
    private string $cdnUrl;
    private int $timeout;
    private string $strategy;
    private string $metadataPath;

    public function __construct()
    {
        $this->cdnUrl = rtrim(config('translator-internal.cdn_url'), '/');
        $this->timeout = config('translator-internal.http_timeout', 30);
        $this->strategy = config('translator-client.sync_strategy', 'overwrite');
        $this->metadataPath = config('translator-client.metadata_path');
    }

    /**
     * Sync a project's translations.
     *
     * @return array{locales: array, keys: int}
     */
    public function syncProject(string $apiKey, string $basePath, bool $force = false): array
    {
        $manifest = $this->fetchManifest($apiKey);
        $locales = $manifest['locales'] ?? [];
        $totalKeys = 0;

        foreach ($locales as $locale) {
            $keys = $this->syncLocale($apiKey, $locale, $basePath, $manifest, $force);
            $totalKeys += $keys;
        }

        $this->saveProjectMetadata($apiKey, $manifest);

        return [
            'locales' => $locales,
            'keys' => $totalKeys,
        ];
    }

    /**
     * Sync a single locale.
     *
     * @return int Number of keys synced (0 if skipped)
     */
    public function syncLocale(
        string $apiKey,
        string $locale,
        string $basePath,
        ?array $manifest = null,
        bool $force = false
    ): int {
        $manifest = $manifest ?? $this->fetchManifest($apiKey);
        $fileData = $manifest['files'][$locale] ?? null;

        if (!$fileData) {
            return 0;
        }

        // Check checksums (skip if up to date)
        $remoteChecksum = $fileData['checksum'] ?? null;
        $localChecksum = $this->getLocalChecksum($apiKey, $locale);

        if (!$force && $localChecksum === $remoteChecksum) {
            return 0; // Up to date
        }

        // Fetch and save translations
        $translations = $this->fetchLocale($apiKey, $locale);
        $this->saveTranslations($locale, $translations, $basePath, $apiKey);
        $this->saveLocaleMetadata($apiKey, $locale, $fileData);

        return $this->countKeys($translations);
    }

    /**
     * Fetch manifest from CDN.
     */
    public function fetchManifest(string $apiKey): array
    {
        $url = "{$this->cdnUrl}/{$apiKey}/manifest.json";

        $response = Http::timeout($this->timeout)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch manifest: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch locale translations from CDN.
     */
    public function fetchLocale(string $apiKey, string $locale): array
    {
        $url = "{$this->cdnUrl}/{$apiKey}/{$locale}.json";

        $response = Http::timeout($this->timeout)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch {$locale}: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Save translations to files.
     */
    public function saveTranslations(string $locale, array $translations, string $basePath, string $apiKey): void
    {
        // Check if v2 format (has 'project' and/or 'global' keys)
        if (isset($translations['project']) || isset($translations['global'])) {
            $this->saveToFilesV2($locale, $translations, $basePath, $apiKey);
            return;
        }

        // V1 format: Group by file (first part of dot notation)
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

        foreach ($grouped as $file => $data) {
            $this->writeTranslationFile($basePath, $locale, $file, $data, $apiKey);
        }
    }

    /**
     * Save translations using v2 format.
     */
    private function saveToFilesV2(string $locale, array $translations, string $basePath, string $apiKey): void
    {
        $projectTranslations = $translations['project'] ?? [];
        $globalTranslations = $translations['global'] ?? [];

        foreach ($projectTranslations as $group => $data) {
            $this->writeTranslationFile($basePath, $locale, $group, $data, $apiKey);
        }

        if (!empty($globalTranslations)) {
            $this->writeTranslationFile($basePath, $locale, 'global', $globalTranslations, $apiKey, true);
        }
    }

    /**
     * Write a translation file.
     */
    public function writeTranslationFile(
        string $basePath,
        string $locale,
        string $file,
        array $data,
        string $apiKey,
        bool $isGlobal = false
    ): void {
        $path = "{$basePath}/{$locale}/{$file}.php";

        if (!File::exists(dirname($path))) {
            File::makeDirectory(dirname($path), 0755, true);
        }

        // Merge with existing if strategy is 'merge'
        if ($this->strategy === 'merge' && File::exists($path)) {
            $existingData = require $path;
            if (is_array($existingData)) {
                $data = $this->mergeTranslations($existingData, $data);
            }
        }

        $content = $this->generateFileContent($data, $locale, $file, $apiKey, $isGlobal);
        File::put($path, $content);
    }

    /**
     * Recursively merge translations. CDN values take precedence.
     */
    public function mergeTranslations(array $local, array $cdn): array
    {
        $merged = $local;

        foreach ($cdn as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeTranslations($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Generate PHP file content with header.
     */
    public function generateFileContent(
        array $data,
        string $locale,
        string $file,
        string $apiKey,
        bool $isGlobal = false
    ): string {
        $timestamp = now()->toDateTimeString();
        $projectId = substr($apiKey, 0, 12) . '...';
        $typeInfo = $isGlobal ? 'Global translations (shared across projects)' : 'Project translations';

        return <<<PHP
<?php

/**
 * AUTO-GENERATED FILE - DO NOT EDIT MANUALLY
 *
 * This translation file is automatically generated and managed by
 * Headwires Translator Client (https://translator.headwires.com)
 *
 * Any manual changes to this file will be OVERWRITTEN on the next sync.
 *
 * Sync Information:
 *   - Type: {$typeInfo}
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
     * Format var_export output for better readability.
     */
    private function varExportFormatted(array $data): string
    {
        $export = var_export($data, true);

        $export = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $export);
        $export = str_replace(['array (', '  '], ['[', '    '], $export);
        $export = str_replace(')', ']', $export);

        return $export;
    }

    /**
     * Count keys in translations.
     */
    public function countKeys(array $translations): int
    {
        if (isset($translations['project']) || isset($translations['global'])) {
            $count = 0;
            foreach ($translations['project'] ?? [] as $group => $keys) {
                $count += is_array($keys) ? count($keys) : 1;
            }
            foreach ($translations['global'] ?? [] as $group => $keys) {
                $count += is_array($keys) ? count($keys) : 1;
            }
            return $count;
        }

        return count($translations);
    }

    // Metadata methods

    public function getLocalChecksum(string $apiKey, string $locale): ?string
    {
        $metaPath = $this->getMetadataPath($apiKey, $locale);

        if (!File::exists($metaPath)) {
            return null;
        }

        try {
            $meta = json_decode(File::get($metaPath), true);
            return $meta['checksum'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function saveLocaleMetadata(string $apiKey, string $locale, array $fileData): void
    {
        $metaPath = $this->getMetadataPath($apiKey, $locale);
        $metaDir = dirname($metaPath);

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        $meta = [
            'checksum' => $fileData['checksum'] ?? null,
            'synced_at' => now()->toIso8601String(),
            'size' => $fileData['size'] ?? 0,
        ];

        File::put($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    public function saveProjectMetadata(string $apiKey, array $manifest): void
    {
        $metaDir = $this->metadataPath . '/' . $this->hashApiKey($apiKey);

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        File::put("{$metaDir}/manifest.json", json_encode([
            'synced_at' => now()->toIso8601String(),
            'version' => $manifest['project']['version'] ?? null,
            'locales' => $manifest['locales'] ?? [],
        ], JSON_PRETTY_PRINT));
    }

    private function getMetadataPath(string $apiKey, string $locale): string
    {
        $projectDir = $this->hashApiKey($apiKey);
        return "{$this->metadataPath}/{$projectDir}/{$locale}.meta.json";
    }

    private function hashApiKey(string $apiKey): string
    {
        return substr(md5($apiKey), 0, 12);
    }
}
