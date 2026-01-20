<?php

namespace Headwires\TranslatorClient\Commands;

use Headwires\TranslatorClient\Services\FileRewriter;
use Headwires\TranslatorClient\Services\ScannerApiClient;
use Illuminate\Console\Command;

class ApplyCommand extends Command
{
    protected $signature = 'translator:apply
                            {--project= : Project ID or API key}
                            {--dry-run : Preview changes without applying}
                            {--y|yes : Skip confirmation prompt}';

    protected $description = 'Apply approved scanner translations to source files';

    public function handle(ScannerApiClient $apiClient, FileRewriter $rewriter): int
    {
        $this->components->info('Translator: Apply Approved Changes');
        $this->newLine();

        // Get project
        $project = $this->option('project') ?? $this->resolveProjectApiKey();
        if (! $project) {
            $this->components->error('No project specified. Use --project or set TRANSLATOR_API_KEY');

            return self::FAILURE;
        }

        $apiClient->setApiKey($project);

        // Fetch pending changes
        $data = null;
        $this->components->task('Fetching approved translations', function () use ($apiClient, &$data) {
            $data = $apiClient->getPendingApply();

            return true;
        });

        if (empty($data['changes'])) {
            $this->components->info('No pending translations to apply.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info("Found {$data['total']} translations pending to apply:");
        $this->newLine();

        // Group by file for display
        $byFile = [];
        foreach ($data['changes'] as $change) {
            foreach ($change['locations'] as $loc) {
                $file = $loc['file'];
                if (! isset($byFile[$file])) {
                    $byFile[$file] = [];
                }
                $byFile[$file][] = [
                    'line' => $loc['line'],
                    'value' => $change['value'],
                    'key' => $change['key'],
                ];
            }
        }

        foreach ($byFile as $file => $changes) {
            $this->line("  <fg=cyan>📄 {$file}</>");
            foreach ($changes as $change) {
                $this->line("     └─ Line {$change['line']}: \"{$change['value']}\" → <fg=green>__('{$change['key']}')</>");
            }
            $this->newLine();
        }

        // Dry run check
        if ($this->option('dry-run')) {
            $this->components->warn('DRY RUN - No changes made');

            return self::SUCCESS;
        }

        // Confirmation
        if (! $this->option('yes') && ! $this->confirm('Apply these changes?', true)) {
            $this->components->warn('Cancelled');

            return self::SUCCESS;
        }

        // Apply changes
        $this->newLine();
        $this->components->info('Applying changes...');

        $results = $rewriter->apply($data['changes']);

        foreach ($results['files'] as $file => $result) {
            if ($result['success']) {
                $this->line("  <fg=green>✓</> {$file} ({$result['changes']} changes)");
            } else {
                $this->line("  <fg=red>✗</> {$file} - {$result['error']}");
            }
        }

        if (! $results['success']) {
            $this->components->error('Some files failed to update');

            return self::FAILURE;
        }

        // Notify API
        $this->newLine();
        $this->components->task('Notifying Localization Hub', function () use ($apiClient, $data) {
            $reviewIds = array_column($data['changes'], 'id');
            $apiClient->markApplied($reviewIds);

            return true;
        });

        // Success summary
        $this->newLine();
        $fileCount = count($results['files']);
        $this->components->info("✅ Success! {$results['total_changes']} translations applied to {$fileCount} files");
        $this->newLine();
        $this->components->bulletList([
            'Review the changes: <fg=yellow>git diff</>',
            'Run tests: <fg=yellow>php artisan test</>',
            'Commit: <fg=yellow>git add . && git commit -m "Apply translations"</>',
        ]);

        return self::SUCCESS;
    }

    /**
     * Resolve project API key from config.
     */
    private function resolveProjectApiKey(): ?string
    {
        $projects = config('translator-client.projects', []);

        if (empty($projects)) {
            return null;
        }

        // If only one project, use its API key
        if (count($projects) === 1) {
            return $projects[0]['api_key'] ?? null;
        }

        // Multiple projects - user must specify
        return null;
    }
}
