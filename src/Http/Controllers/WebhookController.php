<?php

namespace Headwires\TranslatorClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Headwires\TranslatorClient\TranslatorClientService;
use Headwires\TranslatorClient\Support\ApiKeyParser;

class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from localization-hub.
     */
    public function handle(Request $request, TranslatorClientService $translator)
    {
        // Verify webhook signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid translator webhook signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? null;

        Log::info('Translator webhook received', [
            'event' => $event,
            'locale' => $payload['locale'] ?? null,
            'group' => $payload['group'] ?? null,
        ]);

        // Handle events
        match ($event) {
            'translation.updated' => $this->handleTranslationUpdated($payload, $translator),
            'translation.deleted' => $this->handleTranslationDeleted($payload, $translator),
            'translations.bulk_updated' => $this->handleBulkUpdated($payload, $translator),
            'project.languages_changed' => $this->handleLanguagesChanged($payload, $translator),
            default => Log::warning('Unknown webhook event', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
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

        Log::info('Bulk translations cache invalidated', [
            'locales' => $locales,
            'groups' => $groups,
            'count' => count($locales) * count($groups),
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
     * Verify webhook signature.
     */
    private function verifySignature(Request $request): bool
    {
        // Get webhook secret from API key
        $apiKey = config('translator-client.api_key');

        if (!$apiKey) {
            Log::warning('Webhook received but no API key configured');
            return true; // Allow if no API key (for testing)
        }

        $secret = ApiKeyParser::getWebhookSecret($apiKey);

        if (!$secret) {
            // No secret in API key, skip verification
            // This allows backward compatibility with old API keys
            Log::warning('Webhook received but no secret configured in API key');
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
