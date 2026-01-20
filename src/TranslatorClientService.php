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

        return [];
    }

    public function loadAll(string $locale): array
    {
        if ($this->service) {
            return $this->service->loadAll($locale);
        }

        return [];
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

    // ===== Multi-Project Support =====

    /**
     * Get all configured projects.
     */
    public function getProjects(): array
    {
        return config('translator-client.projects', []);
    }

    /**
     * Find a project by its API key.
     */
    public function getProjectByApiKey(string $apiKey): ?array
    {
        $projects = $this->getProjects();

        foreach ($projects as $project) {
            if (($project['api_key'] ?? null) === $apiKey) {
                return $project;
            }
        }

        return null;
    }

    /**
     * Get the first configured project (for backward compatibility).
     */
    public function getDefaultProject(): ?array
    {
        $projects = $this->getProjects();

        return $projects[0] ?? null;
    }

    /**
     * Hash an API key for filesystem-safe directory names.
     */
    public function hashApiKey(string $apiKey): string
    {
        return substr(md5($apiKey), 0, 12);
    }

    // ===== CDN Methods (Project-aware) =====

    /**
     * Fetch manifest from CDN for a specific project.
     */
    public function fetchManifest(string $apiKey): array
    {
        $cdnUrl = rtrim(config('translator-internal.cdn_url'), '/');
        $timeout = config('translator-internal.http_timeout', 30);

        $url = "{$cdnUrl}/{$apiKey}/manifest.json";

        $response = Http::timeout($timeout)
            ->withHeaders(['User-Agent' => 'HeadwiresTranslatorClient/1.0'])
            ->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch manifest: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch locale translations from CDN for a specific project.
     */
    public function fetchLocale(string $apiKey, string $locale): array
    {
        $cdnUrl = rtrim(config('translator-internal.cdn_url'), '/');
        $timeout = config('translator-internal.http_timeout', 30);

        $url = "{$cdnUrl}/{$apiKey}/{$locale}.json";

        $response = Http::timeout($timeout)
            ->withHeaders(['User-Agent' => 'HeadwiresTranslatorClient/1.0'])
            ->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch {$locale}: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    // ===== Metadata Methods (Project-aware) =====

    /**
     * Get the metadata directory for a project.
     */
    public function getProjectMetadataPath(string $apiKey): string
    {
        $baseDir = config('translator-client.metadata_path');
        $projectDir = $this->hashApiKey($apiKey);

        return "{$baseDir}/{$projectDir}";
    }

    /**
     * Get locally stored checksum for a locale (project-aware).
     */
    public function getLocalChecksum(string $apiKey, string $locale): ?string
    {
        $metaPath = $this->getProjectMetadataPath($apiKey) . "/{$locale}.meta.json";

        if (!File::exists($metaPath)) {
            return null;
        }

        try {
            $meta = json_decode(File::get($metaPath), true, 512, JSON_THROW_ON_ERROR);

            return $meta['checksum'] ?? null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Save metadata for a locale (project-aware).
     */
    public function saveLocaleMetadata(string $apiKey, string $locale, array $fileData): void
    {
        $metaDir = $this->getProjectMetadataPath($apiKey);

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        $metaPath = "{$metaDir}/{$locale}.meta.json";
        $meta = [
            'checksum' => $fileData['checksum'] ?? null,
            'synced_at' => now()->toIso8601String(),
            'size' => $fileData['size'] ?? 0,
        ];

        File::put($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Save project metadata (manifest info).
     */
    public function saveProjectMetadata(string $apiKey, array $manifest): void
    {
        $metaDir = $this->getProjectMetadataPath($apiKey);

        if (!File::exists($metaDir)) {
            File::makeDirectory($metaDir, 0755, true);
        }

        File::put("{$metaDir}/manifest.json", json_encode([
            'synced_at' => now()->toIso8601String(),
            'version' => $manifest['project']['version'] ?? null,
            'locales' => $manifest['locales'] ?? [],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get last sync information for a project.
     */
    public function getLastSync(string $apiKey): ?array
    {
        $metaPath = $this->getProjectMetadataPath($apiKey) . '/manifest.json';

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
