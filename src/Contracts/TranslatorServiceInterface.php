<?php

namespace Headwires\TranslatorClient\Contracts;

interface TranslatorServiceInterface
{
    /**
     * Load translations for a given locale and group.
     */
    public function load(string $locale, string $group): array;

    /**
     * Load all translations for a locale.
     */
    public function loadAll(string $locale): array;

    /**
     * Sync translations from API/CDN.
     */
    public function sync(array $options = []): void;

    /**
     * Flush cached translations.
     */
    public function flush(?string $locale = null, ?string $group = null): void;

    /**
     * Get metadata about translations.
     */
    public function getMetadata(): array;

    /**
     * Get the storage mode (live or static).
     */
    public function getMode(): string;
}
