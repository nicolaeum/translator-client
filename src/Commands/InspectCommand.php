<?php

namespace Headwires\TranslatorClient\Commands;

use Headwires\TranslatorClient\Services\SyncService;
use Headwires\TranslatorClient\Support\ApiKeyParser;
use Illuminate\Console\Command;

class InspectCommand extends Command
{
    protected $signature = 'translator:inspect
                            {--project= : Inspect specific project only (by name or api_key)}
                            {--locale= : Show translations for specific locale}
                            {--json : Output raw JSON (useful for debugging)}
                            {--keys : Show only translation keys, not values}';

    protected $description = 'Inspect CDN content for configured projects (manifest and translations)';

    public function __construct(
        private SyncService $syncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $projects = config('translator-client.projects', []);

        if (empty($projects)) {
            $this->error('No projects configured. Add projects to config/translator-client.php');
            return self::FAILURE;
        }

        $projectFilter = $this->option('project');

        if ($projectFilter) {
            $projects = array_filter(
                $projects,
                fn ($p) => ($p['name'] ?? '') === $projectFilter || ($p['api_key'] ?? '') === $projectFilter
            );

            if (empty($projects)) {
                $this->error("Project '{$projectFilter}' not found in config.");
                return self::FAILURE;
            }
        }

        $hasErrors = false;

        foreach ($projects as $project) {
            try {
                $this->inspectProject($project);
            } catch (\Exception $e) {
                $this->error("Error: {$e->getMessage()}");
                $hasErrors = true;
            }
            $this->newLine();
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    private function inspectProject(array $project): void
    {
        $name = $project['name'] ?? 'unnamed';
        $apiKey = $project['api_key'] ?? null;

        if (!$apiKey) {
            $this->error("Project '{$name}' has no API key configured.");
            return;
        }

        // Fetch manifest from CDN
        $manifest = $this->syncService->fetchManifest($apiKey);

        $locale = $this->option('locale');
        $jsonOutput = $this->option('json');

        // JSON output mode
        if ($jsonOutput) {
            $this->outputJson($apiKey, $manifest, $locale);
            return;
        }

        // Pretty output
        $this->outputPretty($name, $apiKey, $manifest, $locale);
    }

    private function outputJson(string $apiKey, array $manifest, ?string $locale): void
    {
        if ($locale) {
            // Output translations for specific locale
            $translations = $this->syncService->fetchLocale($apiKey, $locale);
            $this->line(json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            // Output manifest
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    private function outputPretty(string $name, string $apiKey, array $manifest, ?string $locale): void
    {
        $parsed = ApiKeyParser::parse($apiKey);
        $projectId = $parsed['project_id'] ?? substr($apiKey, 0, 12) . '...';

        $this->line("📦 <fg=cyan;options=bold>{$name}</> <fg=gray>({$projectId})</>");
        $this->newLine();

        // Show manifest info
        $this->showManifestInfo($manifest);

        // If locale specified, show translations
        if ($locale) {
            $this->newLine();
            $this->showLocaleTranslations($apiKey, $locale, $manifest);
        }
    }

    private function showManifestInfo(array $manifest): void
    {
        $this->components->twoColumnDetail('<fg=yellow>CDN Manifest</>');

        // Project info
        $projectInfo = $manifest['project'] ?? [];
        if (!empty($projectInfo)) {
            if (isset($projectInfo['version'])) {
                $this->components->twoColumnDetail('  Version', $projectInfo['version']);
            }
            if (isset($projectInfo['published_at'])) {
                $this->components->twoColumnDetail('  Published', $projectInfo['published_at']);
            }
        }

        // Locales
        $locales = $manifest['locales'] ?? [];
        if (!empty($locales)) {
            $this->components->twoColumnDetail('  Locales', implode(', ', $locales));
        }

        // Files info
        $files = $manifest['files'] ?? [];
        if (!empty($files)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Files</>');

            foreach ($files as $locale => $fileInfo) {
                $size = $this->formatBytes($fileInfo['size'] ?? 0);
                $checksum = substr($fileInfo['checksum'] ?? 'n/a', 0, 8) . '...';
                $this->components->twoColumnDetail(
                    "  {$locale}.json",
                    "<fg=gray>{$size}</> <fg=blue>checksum: {$checksum}</>"
                );
            }
        }

        $this->newLine();
        $this->line('<fg=gray>Run with --locale=XX to see translations content.</>');
    }

    private function showLocaleTranslations(string $apiKey, string $locale, array $manifest): void
    {
        $locales = $manifest['locales'] ?? [];

        if (!in_array($locale, $locales)) {
            $this->error("Locale '{$locale}' not found. Available: " . implode(', ', $locales));
            return;
        }

        $this->components->twoColumnDetail("<fg=yellow>Translations for '{$locale}'</>");
        $this->newLine();

        $translations = $this->syncService->fetchLocale($apiKey, $locale);

        $keysOnly = $this->option('keys');

        // Check if v2 format
        if (isset($translations['project']) || isset($translations['global'])) {
            $this->showV2Translations($translations, $keysOnly);
        } else {
            $this->showV1Translations($translations, $keysOnly);
        }
    }

    private function showV2Translations(array $translations, bool $keysOnly): void
    {
        // Project translations
        $projectTranslations = $translations['project'] ?? [];
        if (!empty($projectTranslations)) {
            $this->line('<fg=cyan>Project translations:</>');
            foreach ($projectTranslations as $group => $keys) {
                $this->line("  <fg=green>[{$group}]</>");
                $this->showKeys($keys, $keysOnly, '    ');
            }
            $this->newLine();
        }

        // Global translations
        $globalTranslations = $translations['global'] ?? [];
        if (!empty($globalTranslations)) {
            $this->line('<fg=cyan>Global translations:</>');
            foreach ($globalTranslations as $group => $keys) {
                $this->line("  <fg=green>[{$group}]</>");
                $this->showKeys($keys, $keysOnly, '    ');
            }
        }
    }

    private function showV1Translations(array $translations, bool $keysOnly): void
    {
        // Group by file prefix
        $grouped = [];
        foreach ($translations as $key => $value) {
            if (str_contains($key, '.')) {
                [$file, $subKey] = explode('.', $key, 2);
            } else {
                $file = 'messages';
                $subKey = $key;
            }
            $grouped[$file][$subKey] = $value;
        }

        foreach ($grouped as $group => $keys) {
            $this->line("<fg=green>[{$group}]</>");
            $this->showKeys($keys, $keysOnly, '  ');
            $this->newLine();
        }
    }

    private function showKeys(array $keys, bool $keysOnly, string $indent = ''): void
    {
        foreach ($keys as $key => $value) {
            if (is_array($value)) {
                $this->line("{$indent}<fg=white>{$key}</>:");
                $this->showKeys($value, $keysOnly, $indent . '  ');
            } else {
                if ($keysOnly) {
                    $this->line("{$indent}<fg=white>{$key}</>");
                } else {
                    $displayValue = strlen($value) > 60 ? substr($value, 0, 60) . '...' : $value;
                    $this->line("{$indent}<fg=white>{$key}</>: <fg=gray>{$displayValue}</>");
                }
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
