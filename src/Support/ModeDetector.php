<?php

namespace Headwires\TranslatorClient\Support;

class ModeDetector
{
    /**
     * Determine if running on serverless (Vapor, Lambda, etc).
     */
    public static function isServerless(): bool
    {
        // Laravel Vapor
        if (isset($_ENV['VAPOR_ARTIFACT_NAME'])) {
            return true;
        }

        // AWS Lambda
        if (isset($_ENV['AWS_LAMBDA_FUNCTION_NAME'])) {
            return true;
        }

        // Environment name
        if (app()->environment('vapor', 'lambda')) {
            return true;
        }

        return false;
    }

    /**
     * Get the storage mode to use.
     */
    public static function getMode(): string
    {
        $configMode = config('translator-client.mode', 'auto');

        // Map legacy storage_mode values
        $legacyMap = [
            'file' => 'static',
            'cache' => 'live',
        ];

        $configMode = $legacyMap[$configMode] ?? $configMode;

        if ($configMode === 'auto') {
            // On serverless: live mode is required
            if (self::isServerless()) {
                return 'live';
            }

            // On traditional servers: prefer live, but allow static
            // Check if user has explicitly set up for static mode
            $hasLangFiles = is_dir(resource_path('lang')) &&
                           count(glob(resource_path('lang/*/*.php'))) > 0;

            // If no lang files exist, assume live mode
            return $hasLangFiles ? 'static' : 'live';
        }

        return $configMode;
    }

    /**
     * Determine if we should use live mode.
     */
    public static function shouldUseLiveMode(): bool
    {
        return self::getMode() === 'live';
    }

    /**
     * Determine if we should use static mode.
     */
    public static function shouldUseStaticMode(): bool
    {
        return self::getMode() === 'static';
    }

    /**
     * Check if running environment supports live mode.
     */
    public static function supportsLiveMode(): bool
    {
        // All environments support live mode if cache is available
        try {
            cache()->get('_test_cache_support');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
