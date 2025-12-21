<?php

namespace Headwires\TranslatorClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Headwires\TranslatorClient\TranslatorClientService;
use Headwires\TranslatorClient\Support\ApiKeyParser;
use Headwires\TranslatorClient\Support\ModeDetector;

class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from localization-hub.
     */
    public function handle(Request $request, TranslatorClientService $translator)
    {
        $payload = $request->all();
        $apiKey = $payload['api_key'] ?? null;
        $event = $payload['event'] ?? null;

        // Find project by api_key
        $project = $apiKey ? $translator->getProjectByApiKey($apiKey) : null;

        if (!$project) {
            Log::warning('Webhook received for unknown project', [
                'api_key' => $apiKey ? substr($apiKey, 0, 12) . '...' : null,
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Project not found'], 404);
        }

        // Verify webhook signature using the api_key from payload
        if (!$this->verifySignature($request, $apiKey)) {
            Log::warning('Invalid translator webhook signature', [
                'api_key' => substr($apiKey, 0, 12) . '...',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('Translator webhook received', [
            'event' => $event,
            'api_key' => substr($apiKey, 0, 12) . '...',
            'locales' => $payload['locales'] ?? null,
        ]);

        // Handle based on mode
        if (ModeDetector::shouldUseLiveMode()) {
            // Live mode: invalidate cache
            $this->handleLiveModeEvent($event, $payload, $translator);
        } else {
            // Static mode: run sync for this project
            $this->handleStaticModeEvent($event, $payload, $apiKey);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle webhook in live mode (cache invalidation).
     */
    private function handleLiveModeEvent(string $event, array $payload, TranslatorClientService $translator): void
    {
        match ($event) {
            'translation.updated' => $this->handleTranslationUpdated($payload, $translator),
            'translation.deleted' => $this->handleTranslationDeleted($payload, $translator),
            'translations.bulk_updated' => $this->handleBulkUpdated($payload, $translator),
            'translations.published' => $this->handlePublished($payload, $translator),
            'project.languages_changed' => $this->handleLanguagesChanged($payload, $translator),
            default => Log::warning('Unknown webhook event', ['event' => $event]),
        };
    }

    /**
     * Handle webhook in static mode (run sync).
     */
    private function handleStaticModeEvent(string $event, array $payload, string $apiKey): void
    {
        // For static mode, run sync for this specific project
        if (in_array($event, ['translations.published', 'translations.bulk_updated', 'project.languages_changed'])) {
            try {
                Log::info('Running sync for project via webhook', [
                    'api_key' => substr($apiKey, 0, 12) . '...',
                ]);

                Artisan::call('translator:sync', [
                    '--project' => $apiKey,
                    '--force' => true,
                ]);

                $output = Artisan::output();
                Log::info('Webhook sync completed', [
                    'api_key' => substr($apiKey, 0, 12) . '...',
                    'output' => trim($output),
                ]);
            } catch (\Exception $e) {
                Log::error('Webhook sync failed', [
                    'api_key' => substr($apiKey, 0, 12) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle translations published event.
     */
    private function handlePublished(array $payload, TranslatorClientService $translator): void
    {
        $locales = $payload['locales'] ?? [];

        // Flush cache for all locales
        foreach ($locales as $locale) {
            $translator->flush($locale);
        }

        Log::info('Translation cache flushed (published)', [
            'locales' => $locales,
        ]);
    }

    /**
     * Handle translation updated event.
     */
    private function handleTranslationUpdated(array $payload, TranslatorClientService $translator): void
    {
        $locale = $payload['locale'] ?? null;
        $group = $payload['group'] ?? null;

        if (!$locale || !$group) {
            return;
        }

        // Clear cache for this translation
        $translator->flush($locale, $group);

        // Pre-warm cache if configured
        if (config('translator-client.webhook.prewarm', true)) {
            try {
                $translator->load($locale, $group);

                Log::info('Translation cache invalidated and pre-warmed', [
                    'locale' => $locale,
                    'group' => $group,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to pre-warm cache after webhook', [
                    'locale' => $locale,
                    'group' => $group,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('Translation cache invalidated', [
                'locale' => $locale,
                'group' => $group,
            ]);
        }
    }

    /**
     * Handle translation deleted event.
     */
    private function handleTranslationDeleted(array $payload, TranslatorClientService $translator): void
    {
        $locale = $payload['locale'] ?? null;
        $group = $payload['group'] ?? null;

        if (!$locale || !$group) {
            return;
        }

        $translator->flush($locale, $group);

        Log::info('Translation cache cleared (deleted)', [
            'locale' => $locale,
            'group' => $group,
        ]);
    }

    /**
     * Handle bulk update event.
     */
    private function handleBulkUpdated(array $payload, TranslatorClientService $translator): void
    {
        $locales = $payload['locales'] ?? [];
        $groups = $payload['groups'] ?? [];

        foreach ($locales as $locale) {
            if (empty($groups)) {
                // Flush all groups for locale
                $translator->flush($locale);
            } else {
                foreach ($groups as $group) {
                    $translator->flush($locale, $group);

                    // Optionally prewarm
                    if (config('translator-client.webhook.prewarm', true)) {
                        try {
                            $translator->load($locale, $group);
                        } catch (\Exception $e) {
                            Log::error('Failed to pre-warm after bulk update', [
                                'locale' => $locale,
                                'group' => $group,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        Log::info('Bulk translations cache invalidated', [
            'locales' => $locales,
            'groups' => $groups ?: 'all',
        ]);
    }

    /**
     * Handle languages changed event (new locale added/removed).
     */
    private function handleLanguagesChanged(array $payload, TranslatorClientService $translator): void
    {
        // Clear all translation cache
        $translator->flush();

        Log::info('All translation cache cleared (languages changed)', [
            'added' => $payload['added'] ?? [],
            'removed' => $payload['removed'] ?? [],
        ]);
    }

    /**
     * Verify webhook signature using the api_key from payload.
     */
    private function verifySignature(Request $request, string $apiKey): bool
    {
        $secret = ApiKeyParser::getWebhookSecret($apiKey);

        if (!$secret) {
            // No secret in API key, skip verification
            // This allows backward compatibility with old API keys
            Log::warning('Webhook received but no secret embedded in API key');
            return true;
        }

        // Get signature from header
        $signature = $request->header('X-Translator-Signature');

        if (!$signature) {
            return false;
        }

        // Verify signature
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
