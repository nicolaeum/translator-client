<?php

namespace Headwires\TranslatorClient\Commands;

use Headwires\TranslatorClient\Services\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'translator:sync
                            {--project= : Sync specific project only (by api_key)}
                            {--force : Force sync even if checksums match}';

    protected $description = 'Sync translations from CDN for all configured projects';

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
            $projects = array_filter($projects, fn($p) => $p['api_key'] === $projectFilter);

            if (empty($projects)) {
                $this->error("Project with api_key '{$projectFilter}' not found in config.");
                return self::FAILURE;
            }
        }

        $hasErrors = false;
        $force = $this->option('force');

        foreach ($projects as $project) {
            try {
                $this->syncProject($project, $force);
            } catch (\Exception $e) {
                $this->error("  ✗ Error: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->warn('Sync completed with errors.');
            return self::FAILURE;
        }

        $this->info('Sync completed successfully!');
        return self::SUCCESS;
    }

    private function syncProject(array $project, bool $force): void
    {
        $apiKey = $project['api_key'];
        $path = $project['path'];
        $shortKey = substr($apiKey, 0, 12) . '...';

        $this->info("Syncing project: {$shortKey}");
        $this->line("  Path: {$path}");

        // Fetch manifest to show locales
        $manifest = $this->syncService->fetchManifest($apiKey);
        $locales = $manifest['locales'] ?? [];

        if (empty($locales)) {
            $this->warn("  No locales found in manifest. Skipping.");
            return;
        }

        $this->line("  Locales: " . implode(', ', $locales));

        // Sync each locale
        foreach ($locales as $locale) {
            $keys = $this->syncService->syncLocale($apiKey, $locale, $path, $manifest, $force);

            if ($keys > 0) {
                $this->line("    {$locale}: Downloaded {$keys} keys");
            } else {
                $this->line("    {$locale}: Up to date");
            }
        }

        // Save project metadata
        $this->syncService->saveProjectMetadata($apiKey, $manifest);
    }
}
