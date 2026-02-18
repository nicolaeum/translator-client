<?php

namespace Headwires\TranslatorClient\Commands;

use Headwires\TranslatorClient\Support\ApiKeyParser;
use Headwires\TranslatorClient\TranslatorClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class StatusCommand extends Command
{
    protected $signature = 'translator:status
                            {--project= : Show status for specific project only (by name or api_key)}';

    protected $description = 'Show translation sync status for all configured projects';

    public function handle(TranslatorClientService $service): int
    {
        $this->components->info('Translation Sync Status');
        $this->newLine();

        $projects = config('translator-client.projects', []);

        if (empty($projects)) {
            $this->components->error('No projects configured. Add projects to config/translator-client.php');

            return self::FAILURE;
        }

        $projectFilter = $this->option('project');

        if ($projectFilter) {
            $projects = array_filter($projects, fn ($p) => ($p['name'] ?? '') === $projectFilter || ($p['api_key'] ?? '') === $projectFilter);

            if (empty($projects)) {
                $this->components->error("Project '{$projectFilter}' not found in config.");

                return self::FAILURE;
            }
        }

        foreach ($projects as $project) {
            $this->showProjectStatus($project, $service);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    protected function showProjectStatus(array $project, TranslatorClientService $service): void
    {
        $name = $project['name'] ?? 'unnamed';
        $apiKey = $project['api_key'] ?? null;

        $this->line("📦 <fg=cyan;options=bold>{$name}</>");
        $this->line("   API Key: {$this->maskApiKey($apiKey)}");
        $this->line('   Path: '.($project['path'] ?? 'not set'));

        if (! $apiKey) {
            $this->line('   Status: <fg=red>API key not configured</>');

            return;
        }

        // Get mode
        $mode = $service->getMode();
        $this->line("   Mode: <fg=yellow>{$mode}</>");

        // Last sync info (only relevant for static mode)
        if ($mode === 'static') {
            $lastSync = $service->getLastSync($apiKey);

            if ($lastSync) {
                $syncedAt = Carbon::parse($lastSync['synced_at']);
                $this->line("   Last Sync: <fg=green>{$syncedAt->diffForHumans()}</>");

                if (isset($lastSync['version'])) {
                    $this->line("   Version: <fg=cyan>{$lastSync['version']}</>");
                }

                if (! empty($lastSync['locales'])) {
                    $this->line('   Locales: <fg=cyan>'.implode(', ', $lastSync['locales']).'</>');
                }
            } else {
                $this->line('   Last Sync: <fg=yellow>Never synced</>');
                $this->line('   <fg=gray>Run "php artisan translator:sync" to download translations.</>');
            }
        } else {
            // Live mode - show cache info
            $metadata = $service->getMetadata();
            $this->line('   Cache TTL: '.($metadata['cache_ttl'] ?? 'N/A').'s');
            $this->line('   Webhook: '.($metadata['webhook_configured'] ?? false ? '<fg=green>configured</>' : '<fg=yellow>not configured</>'));
        }
    }

    protected function maskApiKey(?string $key): string
    {
        if (empty($key)) {
            return '<fg=red>Not configured</>';
        }

        // Parse API key to get project ID
        $parsed = ApiKeyParser::parse($key);
        $projectId = $parsed['project_id'] ?? null;

        if ($projectId) {
            return "<fg=green>{$projectId}</> (configured)";
        }

        if (strlen($key) < 10) {
            return $key;
        }

        return substr($key, 0, 6).'...'.substr($key, -6);
    }
}
