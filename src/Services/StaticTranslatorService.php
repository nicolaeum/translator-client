<?php

namespace Headwires\TranslatorClient\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;

class StaticTranslatorService implements TranslatorServiceInterface
{
    private string $cachePrefix;
    private string $langPath;

    public function __construct(
        private CacheRepository $cache,
        private string $apiUrl,
        private ?string $apiKey,
        private string $cdnUrl
    ) {
        $this->cachePrefix = config('translator-client.cache.prefix', 'translations');
        $this->langPath = config('translator-client.storage_path', resource_path('lang'));
    }

    /**
     * Load translations (files + cache).
     */
    public function load(string $locale, string $group): array
    {
        // Try file first
        $filePath = "{$this->langPath}/{$locale}/{$group}.php";

        if (File::exists($filePath)) {
            return require $filePath;
        }

        // Fallback to cache
        $cacheKey = $this->getCacheKey($locale, $group);
        return $this->cache->remember($cacheKey, 3600, function () use ($locale, $group) {
            return $this->fetchFromCdn($locale, $group);
        });
    }

    /**
     * Load all translations.
     */
    public function loadAll(string $locale): array
    {
        $translations = [];
        $localePath = "{$this->langPath}/{$locale}";

        if (File::isDirectory($localePath)) {
            foreach (File::files($localePath) as $file) {
                $group = $file->getFilenameWithoutExtension();
                $translations[$group] = require $file->getRealPath();
            }
        }

        return $translations;
    }

    /**
     * Sync translations (download to files + cache).
     */
    public function sync(array $options = []): void
    {
        $locales = $options['locales'] ?? config('translator-client.locales', ['en', 'es']);

        foreach ($locales as $locale) {
            $this->syncLocale($locale);
        }

        Log::info('Static translations synced', ['locales' => $locales]);
    }

    /**
     * Sync a single locale.
     */
    private function syncLocale(string $locale): void
    {
        $manifest = $this->fetchManifest();
        $formatVersion = $manifest['format_version'] ?? 1;
        $strategy = config('translator-client.sync_strategy', 'overwrite');

        if ($formatVersion >= 2) {
            $this->syncLocaleV2($locale, $manifest, $strategy);
        } else {
            $this->syncLocaleV1($locale, $manifest, $strategy);
        }
    }

    /**
     * Sync locale using v1 format (flat structure).
     */
    private function syncLocaleV1(string $locale, array $manifest, string $strategy): void
    {
        $groups = $manifest['groups'] ?? [];

        foreach ($groups as $group) {
            $cdnTranslations = $this->fetchFromCdnV1($locale, $group);
            $this->writeGroupFile($locale, $group, $cdnTranslations, $strategy);
        }
    }

    /**
     * Sync locale using v2 format (structured with project/global separation).
     */
    private function syncLocaleV2(string $locale, array $manifest, string $strategy): void
    {
        $data = $this->fetchLocaleData($locale);

        if (empty($data)) {
            Log::warning('No translation data found for locale', ['locale' => $locale]);
            return;
        }

        $projectTranslations = $data['project'] ?? [];
        $globalTranslations = $data['global'] ?? [];

        // Write project translations (one file per group)
        foreach ($projectTranslations as $group => $translations) {
            $this->writeGroupFile($locale, $group, $translations, $strategy);
        }

        // Write global translations (single global.php file with all groups)
        if (!empty($globalTranslations)) {
            $this->writeGlobalFile($locale, $globalTranslations, $strategy);
        }
    }

    /**
     * Write a group file (e.g., auth.php, messages.php).
     */
    private function writeGroupFile(string $locale, string $group, array $translations, string $strategy): void
    {
        $filePath = "{$this->langPath}/{$locale}/{$group}.php";

        // Merge with existing if strategy is 'merge'
        if ($strategy === 'merge' && File::exists($filePath)) {
            $localTranslations = require $filePath;
            $translations = $this->mergeTranslations($localTranslations, $translations);
        }

        // Write to file
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\nreturn " . var_export($translations, true) . ";\n");

        // Update cache
        $cacheKey = $this->getCacheKey($locale, $group);
        $this->cache->put($cacheKey, $translations, 3600);
    }

    /**
     * Write global.php file with all global groups as nested keys.
     * Structure: ['actions' => [...], 'buttons' => [...], ...]
     */
    private function writeGlobalFile(string $locale, array $globalTranslations, string $strategy): void
    {
        $filePath = "{$this->langPath}/{$locale}/global.php";

        // Merge with existing if strategy is 'merge'
        if ($strategy === 'merge' && File::exists($filePath)) {
            $localTranslations = require $filePath;
            $globalTranslations = $this->mergeTranslations($localTranslations, $globalTranslations);
        }

        // Write to file
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\nreturn " . var_export($globalTranslations, true) . ";\n");

        // Update cache for each group within global
        foreach ($globalTranslations as $group => $translations) {
            $cacheKey = $this->getCacheKey($locale, "global.{$group}");
            $this->cache->put($cacheKey, $translations, 3600);
        }
    }

    /**
     * Fetch full locale data from CDN (v2 format).
     */
    private function fetchLocaleData(string $locale): array
    {
        try {
            $url = "{$this->cdnUrl}/{$locale}.json";
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            return [];
        } catch (\Exception $e) {
            Log::error('Failed to fetch locale data from CDN', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Recursively merge translations. CDN values take precedence.
     */
    private function mergeTranslations(array $local, array $cdn): array
    {
        $merged = $local;

        foreach ($cdn as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // Recursive merge for nested arrays
                $merged[$key] = $this->mergeTranslations($merged[$key], $value);
            } else {
                // CDN value takes precedence
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Flush cache (keeps files).
     */
    public function flush(?string $locale = null, ?string $group = null): void
    {
        if ($locale === null && $group === null) {
            // Flush all
            try {
                if (method_exists($this->cache->getStore(), 'connection')) {
                    $redis = $this->cache->getStore()->connection();
                    $keys = $redis->keys("{$this->cachePrefix}:*");
                    foreach ($keys as $key) {
                        $cleanKey = str_replace(
                            $this->cache->getStore()->getPrefix(),
                            '',
                            $key
                        );
                        $this->cache->forget($cleanKey);
                    }
                } else {
                    $this->cache->flush();
                }
            } catch (\Exception $e) {
                $this->cache->flush();
            }
        } elseif ($locale !== null && $group !== null) {
            $cacheKey = $this->getCacheKey($locale, $group);
            $this->cache->forget($cacheKey);
        } elseif ($locale !== null) {
            $manifest = $this->fetchManifest();
            $groups = $manifest['groups'] ?? [];

            foreach ($groups as $grp) {
                $cacheKey = $this->getCacheKey($locale, $grp);
                $this->cache->forget($cacheKey);
            }
        }

        Log::info('Static translation cache flushed');
    }

    /**
     * Get metadata.
     */
    public function getMetadata(): array
    {
        return [
            'mode' => 'static',
            'cache_driver' => config('translator-client.cache.driver'),
            'lang_path' => $this->langPath,
            'cdn_url' => $this->cdnUrl,
        ];
    }

    /**
     * Get the storage mode.
     */
    public function getMode(): string
    {
        return 'static';
    }

    /**
     * Fetch translations from CDN (v1 format - flat structure).
     */
    private function fetchFromCdnV1(string $locale, string $group): array
    {
        try {
            $url = "{$this->cdnUrl}/{$locale}.json";
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return $data[$group] ?? [];
            }

            return $this->fetchFromApi($locale, $group);
        } catch (\Exception $e) {
            Log::error('Static mode: Failed to fetch from CDN', [
                'locale' => $locale,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

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

            return [];
        } catch (\Exception $e) {
            Log::error('Static mode: Failed to fetch from API', [
                'locale' => $locale,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function fetchManifest(): array
    {
        return $this->cache->remember(
            "{$this->cachePrefix}:manifest",
            3600,
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

    private function getCacheKey(string $locale, string $group): string
    {
        return "{$this->cachePrefix}:{$locale}:{$group}";
    }
}
