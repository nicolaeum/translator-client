<?php

namespace Headwires\TranslatorClient;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;

class TranslatorClientService
{
    public function __construct(private ?TranslatorServiceInterface $service = null)
    {
    }

    /**
     * Delegate to the active translator service.
     */
    public function load(string $locale, string $group): array
    {
        if ($this->service) {
            return $this->service->load($locale, $group);
        }

        // Fallback for backward compatibility
        return $this->fetchLocale($locale)[$group] ?? [];
    }

    public function loadAll(string $locale): array
    {
        if ($this->service) {
            return $this->service->loadAll($locale);
        }

        // Fallback
        return $this->fetchLocale($locale);
    }

    public function sync(array $options = []): void
    {
        if ($this->service) {
            $this->service->sync($options);
        }
    }

    public function flush(?string $locale = null, ?string $group = null): void
    {
        if ($this->service) {
            $this->service->flush($locale, $group);
        }
    }

    public function getMetadata(): array
    {
        if ($this->service) {
            return $this->service->getMetadata();
        }

        return ['mode' => 'legacy'];
    }

    public function getMode(): string
    {
        if ($this->service) {
            return $this->service->getMode();
        }

        return 'legacy';
    }

    // ===== Legacy methods for backward compatibility =====

    /**
     * Fetch manifest from CDN
     */
    public function fetchManifest(): array
    {
        $apiKey = config('translator-client.api_key');
        $cdnUrl = config('translator-client.cdn_url');
        $timeout = config('translator-client.http_timeout', 30);

        if (empty($apiKey)) {
            throw new \Exception('TRANSLATOR_API_KEY is not configured');
        }

        $url = "{$cdnUrl}/projects/{$apiKey}/manifest.json";

        $response = Http::timeout($timeout)
            ->withHeaders(['User-Agent' => 'HeadwiresTranslatorClient/1.0'])
            ->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch manifest: HTTP {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Fetch locale translations from CDN
     */
    public function fetchLocale(string $locale, ?string $expectedChecksum = null): array
    {
        $apiKey = config('translator-client.api_key');
        $cdnUrl = config('translator-client.cdn_url');
        $timeout = config('translator-client.http_timeout', 30);

        $url = "{$cdnUrl}/projects/{$apiKey}/{$locale}.json";

        $response = Http::timeout($timeout)
            ->withHeaders(['User-Agent' => 'HeadwiresTranslatorClient/1.0'])
            ->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch {$locale}: HTTP {$response->status()}");
        }

        // Verify checksum if provided and verification enabled
        if ($expectedChecksum && config('translator-client.verify_checksums', true)) {
            $actualChecksum = 'md5:' . md5($response->body());

            if ($actualChecksum !== $expectedChecksum) {
                throw new \Exception("Checksum mismatch for {$locale}. Expected {$expectedChecksum}, got {$actualChecksum}");
            }
        }

        return $response->json();
    }

    /**
     * Get locally stored checksum for a locale
     */
    public function getLocalChecksum(string $locale): ?string
    {
        $metaPath = config('translator-client.metadata_path') . "/{$locale}.meta";

        if (!File::exists($metaPath)) {
            return null;
        }

        try {
            $meta = json_decode(File::get($metaPath), true, 512, JSON_THROW_ON_ERROR);

            return $meta['checksum'] ?? null;
        } catch (\JsonException $e) {
            // Corrupted metadata file - treat as if not exists
            return null;
        }
    }

    /**
     * Save metadata for a locale
     */
    public function saveMetadata(string $locale, array $fileData): void
    {
        $metaDir = config('translator-client.metadata_path');

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        $metaPath = "{$metaDir}/{$locale}.meta";
        $meta = [
            'checksum' => $fileData['checksum'],
            'synced_at' => now()->toIso8601String(),
            'remote_modified' => $fileData['last_modified'] ?? null,
            'size' => $fileData['size'] ?? 0,
        ];

        File::put($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Update global sync metadata
     */
    public function updateGlobalMetadata(array $manifest): void
    {
        $metaDir = config('translator-client.metadata_path');

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        File::put("{$metaDir}/last_sync.json", json_encode([
            'synced_at' => now()->toIso8601String(),
            'version' => $manifest['project']['version'] ?? null,
            'locales' => array_keys($manifest['files'] ?? []),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get last sync information
     */
    public function getLastSync(): ?array
    {
        $metaPath = config('translator-client.metadata_path') . '/last_sync.json';

        if (!File::exists($metaPath)) {
            return null;
        }

        try {
            return json_decode(File::get($metaPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }
}
