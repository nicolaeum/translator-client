<?php

namespace Headwires\TranslatorClient\Translation;

use Illuminate\Contracts\Translation\Loader as LoaderInterface;
use Illuminate\Translation\FileLoader;
use Illuminate\Support\Facades\Log;
use Headwires\TranslatorClient\TranslatorClientService;

class CdnTranslationLoader implements LoaderInterface
{
    public function __construct(
        private FileLoader $fileLoader,
        private TranslatorClientService $translatorClient
    ) {}

    /**
     * Load the messages for the given locale.
     *
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array
     */
    public function load($locale, $group, $namespace = null): array
    {
        // If there's a namespace, always use file loader (packages)
        if ($namespace !== null && $namespace !== '*') {
            return $this->fileLoader->load($locale, $group, $namespace);
        }

        // Try to load from CDN/cache first
        try {
            $translations = $this->loadFromCdn($locale, $group);

            if (!empty($translations)) {
                Log::debug('Translation loaded from CDN', [
                    'locale' => $locale,
                    'group' => $group,
                    'keys_count' => count($translations),
                ]);

                return $translations;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load translation from CDN, falling back to files', [
                'locale' => $locale,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to file loader
        return $this->fileLoader->load($locale, $group, $namespace);
    }

    /**
     * Load translations from CDN via the translator client.
     */
    private function loadFromCdn(string $locale, string $group): array
    {
        // Get the service and check mode
        $mode = $this->translatorClient->getMode();

        if ($mode === 'legacy') {
            return [];
        }

        // Load from the translator service (uses cache in live mode)
        return $this->translatorClient->load($locale, $group);
    }

    /**
     * Add a new namespace to the loader.
     *
     * @param  string  $namespace
     * @param  string  $hint
     * @return void
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->fileLoader->addNamespace($namespace, $hint);
    }

    /**
     * Add a new JSON path to the loader.
     *
     * @param  string  $path
     * @return void
     */
    public function addJsonPath($path): void
    {
        $this->fileLoader->addJsonPath($path);
    }

    /**
     * Get an array of all the registered namespaces.
     *
     * @return array
     */
    public function namespaces(): array
    {
        return $this->fileLoader->namespaces();
    }
}
