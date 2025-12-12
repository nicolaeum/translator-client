<?php

namespace Headwires\TranslatorClient\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;
use Headwires\TranslatorClient\Support\ApiKeyParser;

class LiveTranslatorService implements TranslatorServiceInterface
{
    private string $cachePrefix;
    private ?string $webhookSecret;
    private ?string $projectId;

    public function __construct(
        private CacheRepository $cache,
        private string $apiUrl,
        private ?string $apiKey,
        private string $cdnUrl
    ) {
        $this->cachePrefix = config('translator-client.cache.prefix', 'translations');

        // Parse API key to extract webhook secret (if provided)
        if ($apiKey) {
            $parsed = ApiKeyParser::parse($apiKey);
            $this->webhookSecret = $parsed['webhook_secret'];
            $this->projectId = $parsed['project_id'];
        } else {
            $this->webhookSecret = null;
            $this->projectId = null;
        }
    }

    /**
     * Load translations for a given locale and group.
     */
    public function load(string $locale, string $group): array
    {
        $cacheKey = $this->getCacheKey($locale, $group);

        // Get TTL from config (default: 5 minutes as fallback)
        $ttl = config('translator-client.cache.ttl', 300);

        if ($ttl === 0) {
            // Rely only on webhooks (cache forever)
            return $this->cache->rememberForever($cacheKey, function () use ($locale, $group) {
                return $this->fetchFromCdn($locale, $group);
            });
        }

        // Cache with TTL as fallback if webhooks fail
        return $this->cache->remember($cacheKey, $ttl, function () use ($locale, $group) {
            return $this->fetchFromCdn($locale, $group);
        });
    }

    /**
     * Load all translations for a locale.
     */
    public function loadAll(string $locale): array
    {
        // Fetch manifest to know which groups exist
        $manifest = $this->fetchManifest();
        $groups = $manifest['groups'] ?? [];

        $translations = [];
        foreach ($groups as $group) {
            $translations[$group] = $this->load($locale, $group);
        }

        return $translations;
    }

    /**
     * Sync translations from CDN (for cache warmup).
     */
    public function sync(array $options = []): void
    {
        $locales = $options['locales'] ?? $this->getAvailableLocales();
        $groups = $options['groups'] ?? null;

        foreach ($locales as $locale) {
            if ($groups === null) {
                // Sync all groups
                $this->loadAll($locale);
            } else {
                // Sync specific groups
                foreach ($groups as $group) {
                    $this->load($locale, $group);
                }
            }
        }

        Log::info('Live translations synced', [
            'locales' => $locales,
            'groups' => $groups ?? 'all',
        ]);
    }

    /**
     * Flush cached translations.
     */
    public function flush(?string $locale = null, ?string $group = null): void
    {
        if ($locale === null && $group === null) {
            // Flush all translations
            $pattern = "{$this->cachePrefix}:*";

            // Get all cache keys matching pattern (Redis-specific)
            try {
                if (method_exists($this->cache->getStore(), 'connection')) {
                    $redis = $this->cache->getStore()->connection();
                    $keys = $redis->keys($pattern);

                    foreach ($keys as $key) {
                        // Remove prefix that Redis adds
                        $cleanKey = str_replace(
                            $this->cache->getStore()->getPrefix(),
                            '',
                            $key
                        );
                        $this->cache->forget($cleanKey);
                    }
                } else {
                    // For non-Redis drivers, just flush all cache
                    $this->cache->flush();
                }
            } catch (\Exception $e) {
                // Fallback: flush all cache
                $this->cache->flush();
            }

            Log::info('All translation cache flushed');
        } elseif ($locale !== null && $group !== null) {
            // Flush specific locale+group
            $cacheKey = $this->getCacheKey($locale, $group);
            $this->cache->forget($cacheKey);

            Log::info('Translation cache flushed', [
                'locale' => $locale,
                'group' => $group,
            ]);
        } elseif ($locale !== null) {
            // Flush all groups for locale
            $manifest = $this->fetchManifest();
            $groups = $manifest['groups'] ?? [];

            foreach ($groups as $grp) {
                $cacheKey = $this->getCacheKey($locale, $grp);
                $this->cache->forget($cacheKey);
            }

            Log::info('Translation cache flushed for locale', ['locale' => $locale]);
        }
    }

    /**
     * Get metadata about translations.
     */
    public function getMetadata(): array
    {
        return [
            'mode' => 'live',
            'cache_driver' => config('translator-client.cache.driver'),
            'cache_prefix' => $this->cachePrefix,
            'cache_ttl' => config('translator-client.cache.ttl'),
            'cdn_url' => $this->cdnUrl,
            'webhook_enabled' => config('translator-client.webhook.enabled'),
            'webhook_configured' => $this->webhookSecret !== null,
            'project_id' => $this->projectId,
        ];
    }

    /**
     * Get the storage mode.
     */
    public function getMode(): string
    {
        return 'live';
    }

    /**
     * Get webhook secret from API key.
     */
    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    /**
     * Fetch translations from CDN.
     */
    private function fetchFromCdn(string $locale, string $group): array
    {
        try {
            // Try CDN first (faster)
            $url = "{$this->cdnUrl}/{$locale}.json";
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return $data[$group] ?? [];
            }

            // Fallback to API
            return $this->fetchFromApi($locale, $group);
        } catch (\Exception $e) {
            Log::error('Failed to fetch from CDN', [
                'locale' => $locale,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);

            // Try API as last resort
            return $this->fetchFromApi($locale, $group);
        }
    }

    /**
     * Fetch translations from API (fallback).
     */
    private function fetchFromApi(string $locale, string $group): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->apiUrl}/api/translations/{$locale}/{$group}");

            if ($response->successful()) {
                return $response->json()['translations'] ?? [];
            }

            Log::warning('Failed to fetch from API', [
                'locale' => $locale,
                'group' => $group,
                'status' => $response->status(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Failed to fetch from API', [
                'locale' => $locale,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch manifest with available groups and checksum.
     */
    private function fetchManifest(): array
    {
        $cacheKey = "{$this->cachePrefix}:manifest:{$this->projectId}";

        return $this->cache->remember(
            $cacheKey,
            3600, // Cache manifest for 1 hour
            function () {
                try {
                    $response = Http::timeout(10)->get("{$this->cdnUrl}/manifest.json");
                    return $response->successful() ? $response->json() : [];
                } catch (\Exception $e) {
                    Log::error('Failed to fetch manifest', ['error' => $e->getMessage()]);
                    return [];
                }
            }
        );
    }

    /**
     * Get available locales from config.
     */
    private function getAvailableLocales(): array
    {
        return config('translator-client.locales', ['en', 'es']);
    }

    /**
     * Generate cache key.
     */
    private function getCacheKey(string $locale, string $group): string
    {
        return "{$this->cachePrefix}:{$locale}:{$group}";
    }
}
