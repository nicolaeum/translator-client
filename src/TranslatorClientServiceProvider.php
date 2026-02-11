<?php

namespace Headwires\TranslatorClient;

use Headwires\TranslatorClient\Commands\ApplyCommand;
use Headwires\TranslatorClient\Commands\CacheWarmupCommand;
use Headwires\TranslatorClient\Commands\ScanCommand;
use Headwires\TranslatorClient\Commands\StatusCommand;
use Headwires\TranslatorClient\Commands\SyncCommand;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;
use Headwires\TranslatorClient\Services\LiveTranslatorService;
use Headwires\TranslatorClient\Services\StaticTranslatorService;
use Headwires\TranslatorClient\Services\SyncService;
use Headwires\TranslatorClient\Support\ModeDetector;
use Headwires\TranslatorClient\Translation\CdnTranslationLoader;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;

class TranslatorClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // User-configurable settings (published)
        $this->mergeConfigFrom(
            __DIR__.'/../config/translator-client.php',
            'translator-client'
        );

        // Internal package settings (NOT published)
        $this->mergeConfigFrom(
            __DIR__.'/../config/translator-internal.php',
            'translator-internal'
        );

        // Register SyncService as singleton
        $this->app->singleton(SyncService::class);

        // Register the appropriate translator service based on mode
        $this->app->singleton(TranslatorServiceInterface::class, function ($app) {
            // Use configured driver, or fall back to Laravel's default cache store
            $cacheDriver = config('translator-client.cache.driver') ?? config('cache.default');
            $cache = $app['cache']->store($cacheDriver);
            $baseCdnUrl = rtrim(config('translator-internal.cdn_url'), '/');

            // Get the first project's API key for backward compatibility
            // In multi-project setups, the service is mainly for live mode cache operations
            $projects = config('translator-client.projects', []);
            $defaultProject = $projects[0] ?? null;
            $apiKey = $defaultProject['api_key'] ?? null;

            // Build full CDN URL (prefix is already included in cdn_url)
            $cdnUrl = $apiKey ? "{$baseCdnUrl}/{$apiKey}" : $baseCdnUrl;
            $apiUrl = $baseCdnUrl;

            if (ModeDetector::shouldUseLiveMode()) {
                return new LiveTranslatorService(
                    cache: $cache,
                    apiUrl: $apiUrl,
                    apiKey: $apiKey,
                    cdnUrl: $cdnUrl
                );
            }

            return new StaticTranslatorService(
                cache: $cache,
                apiUrl: $apiUrl,
                apiKey: $apiKey,
                cdnUrl: $cdnUrl
            );
        });

        // Register facade/wrapper with the active service injected
        $this->app->singleton(TranslatorClientService::class, function ($app) {
            return new TranslatorClientService(
                service: $app->make(TranslatorServiceInterface::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Auto-register webhook route if webhooks enabled (both static and live mode)
        if (config('translator-client.webhook.enabled', true)) {
            $this->registerWebhookRoute();
        }

        // Register CDN translation loader for live mode
        if (ModeDetector::shouldUseLiveMode()) {
            $this->registerCdnTranslationLoader();
        }

        // Publish config
        $this->publishes([
            __DIR__.'/../config/translator-client.php' => config_path('translator-client.php'),
        ], 'translator-client-config');

        // Register commands (always, so Artisan::call works from HTTP context)
        $this->commands([
            ApplyCommand::class,
            SyncCommand::class,
            StatusCommand::class,
            CacheWarmupCommand::class,
            ScanCommand::class,
        ]);
    }

    /**
     * Auto-register webhook route.
     */
    protected function registerWebhookRoute(): void
    {
        $route = config('translator-client.webhook.route', '/api/translator/webhook');

        $this->app['router']->post(
            $route,
            [\Headwires\TranslatorClient\Http\Controllers\WebhookController::class, 'handle']
        )->name('translator-client.webhook');
    }

    /**
     * Register the CDN translation loader for live mode.
     * This replaces Laravel's default file loader with a hybrid loader
     * that first checks the CDN/cache before falling back to local files.
     */
    protected function registerCdnTranslationLoader(): void
    {
        $this->app->extend('translation.loader', function ($fileLoader, $app) {
            return new CdnTranslationLoader(
                fileLoader: $fileLoader,
                translatorClient: $app->make(TranslatorClientService::class)
            );
        });
    }
}
