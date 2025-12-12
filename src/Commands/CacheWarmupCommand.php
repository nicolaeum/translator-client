<?php

namespace Headwires\TranslatorClient\Commands;

use Illuminate\Console\Command;
use Headwires\TranslatorClient\TranslatorClientService;
use Headwires\TranslatorClient\Support\ModeDetector;

class CacheWarmupCommand extends Command
{
    protected $signature = 'translator:warmup
                            {--locales=* : Specific locales to warmup}
                            {--groups=* : Specific groups to warmup}
                            {--force : Force warmup even in static mode}';

    protected $description = 'Pre-cache all translations for live mode';

    public function handle(TranslatorClientService $translator): int
    {
        if (ModeDetector::shouldUseStaticMode() && !$this->option('force')) {
            $this->warn('Static mode detected. Use --force to warmup anyway.');
            $this->info('Tip: Live mode uses cache automatically. Static mode uses files.');
            return self::FAILURE;
        }

        $this->info('Starting translation cache warmup...');
        $this->info('Mode: ' . $translator->getMode());
        $this->newLine();

        $locales = $this->option('locales') ?: config('translator-client.locales', ['en', 'es']);
        $groups = $this->option('groups') ?: null;

        $startTime = microtime(true);
        $totalCached = 0;

        foreach ($locales as $locale) {
            $this->line("🔥 Warming cache for locale: <fg=cyan>{$locale}</>");

            try {
                if ($groups === null) {
                    // Warmup all groups
                    $translations = $translator->loadAll($locale);
                    $totalCached += count($translations);
                    $this->info("  ✓ Cached " . count($translations) . " groups");
                } else {
                    // Warmup specific groups
                    foreach ($groups as $group) {
                        $translator->load($locale, $group);
                        $totalCached++;
                        $this->line("  ✓ Cached group: {$group}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('🎉 Cache warmup complete!');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Locales processed', count($locales)],
                ['Groups cached', $totalCached],
                ['Duration', "{$duration}s"],
                ['Mode', $translator->getMode()],
                ['Cache driver', config('translator-client.cache.driver')],
            ]
        );

        return self::SUCCESS;
    }
}
