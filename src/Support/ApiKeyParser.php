<?php

namespace Headwires\TranslatorClient\Support;

use Illuminate\Support\Str;

class ApiKeyParser
{
    /**
     * Parse API key and extract project ID and webhook secret.
     *
     * Format: lh_proj_{project_id}_{base64_webhook_secret}
     * Example: lh_proj_abc123_d2ViaG9va19zZWNyZXQ0NTY=
     */
    public static function parse(string $apiKey): array
    {
        // Format: lh_proj_{id}_{secret}
        if (!Str::startsWith($apiKey, 'lh_proj_')) {
            throw new \InvalidArgumentException('Invalid API key format');
        }

        $parts = explode('_', $apiKey);

        if (count($parts) < 3) {
            // Invalid format
            return [
                'project_id' => null,
                'webhook_secret' => null,
            ];
        }

        if (count($parts) === 3) {
            // Old format without webhook secret: lh_proj_{id}
            return [
                'project_id' => $parts[2] ?? null,
                'webhook_secret' => null,
            ];
        }

        // New format with webhook secret: lh_proj_{id}_{secret}
        $projectId = $parts[2];
        $encodedSecret = $parts[3];

        try {
            $webhookSecret = base64_decode($encodedSecret, true);

            // Verify it's valid base64
            if ($webhookSecret === false || base64_encode($webhookSecret) !== $encodedSecret) {
                $webhookSecret = null;
            }
        } catch (\Exception $e) {
            $webhookSecret = null;
        }

        return [
            'project_id' => $projectId,
            'webhook_secret' => $webhookSecret,
        ];
    }

    /**
     * Get webhook secret from API key.
     */
    public static function getWebhookSecret(string $apiKey): ?string
    {
        return self::parse($apiKey)['webhook_secret'];
    }

    /**
     * Get project ID from API key.
     */
    public static function getProjectId(string $apiKey): ?string
    {
        return self::parse($apiKey)['project_id'];
    }
}
