<?php

namespace Headwires\TranslatorClient;

use Illuminate\Support\ServiceProvider;
use Headwires\TranslatorClient\Commands\SyncCommand;
use Headwires\TranslatorClient\Commands\StatusCommand;
use Headwires\TranslatorClient\Commands\CacheWarmupCommand;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;
use Headwires\TranslatorClient\Services\LiveTranslatorService;
use Headwires\TranslatorClient\Services\StaticTranslatorService;
use Headwires\TranslatorClient\Services\SyncService;
use Headwires\TranslatorClient\Support\ModeDetector;

class TranslatorClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/translator-client.php',
            'translator-client'
        );

        // Register SyncService as singleton
        $this->app->singleton(SyncService::class);

        // Register the appropriate translator service based on mode
        $this->app->singleton(TranslatorServiceInterface::class, function ($app) {
            $cache = $app['cache']->store(config('translator-client.cache.driver', 'file'));
            $baseCdnUrl = rtrim(config('translator-client.cdn_url'), '/');

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

        // Publish config
        $this->publishes([
            __DIR__.'/../config/translator-client.php' => config_path('translator-client.php'),
        ], 'translator-client-config');

        // Register commands (always, so Artisan::call works from HTTP context)
        $this->commands([
            SyncCommand::class,
            StatusCommand::class,
            CacheWarmupCommand::class,
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
}
