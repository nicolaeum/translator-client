<?php

namespace Headwires\TranslatorClient;

use Illuminate\Support\ServiceProvider;
use Headwires\TranslatorClient\Commands\SyncCommand;
use Headwires\TranslatorClient\Commands\StatusCommand;
use Headwires\TranslatorClient\Commands\CacheWarmupCommand;
use Headwires\TranslatorClient\Contracts\TranslatorServiceInterface;
use Headwires\TranslatorClient\Services\LiveTranslatorService;
use Headwires\TranslatorClient\Services\StaticTranslatorService;
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

        // Register the appropriate translator service based on mode
        $this->app->singleton(TranslatorServiceInterface::class, function ($app) {
            $cache = $app['cache']->store(config('translator-client.cache.driver', 'redis'));
            $baseCdnUrl = rtrim(config('translator-client.cdn_url'), '/');
            $apiKey = config('translator-client.api_key');

            // Build full CDN URL with projects path
            $cdnUrl = "{$baseCdnUrl}/projects/{$apiKey}";
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
        // Auto-register webhook route if live mode and webhooks enabled
        if (ModeDetector::shouldUseLiveMode() && config('translator-client.webhook.enabled', true)) {
            $this->registerWebhookRoute();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/translator-client.php' => config_path('translator-client.php'),
            ], 'translator-client-config');

            $this->commands([
                SyncCommand::class,
                StatusCommand::class,
                CacheWarmupCommand::class,
            ]);
        }
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
