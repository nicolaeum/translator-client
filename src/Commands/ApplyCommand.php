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

    private ?ScannerApiClient $apiClient = null;

    public function handle(FileRewriter $rewriter): int
    {
        $this->components->info('Translator: Apply Approved Changes');
        $this->newLine();

        // Get project API key
        $apiKey = $this->option('project') ?? $this->resolveProjectApiKey();
        if (! $apiKey) {
            $this->components->error('No project specified. Use --project or configure a project in translator-client.php');

            return self::FAILURE;
        }

        // Create API client with the resolved key
        $this->apiClient = new ScannerApiClient(
            config('translator-internal.api_url'),
            $apiKey
        );

        // Fetch pending changes
        $data = null;
        $this->components->task('Fetching approved translations', function () use (&$data) {
            $data = $this->apiClient->getPendingApply();

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
                    'params' => $change['params'] ?? [],
                ];
            }
        }

        foreach ($byFile as $file => $changes) {
            $this->line("  <fg=cyan>📄 {$file}</>");
            foreach ($changes as $change) {
                $translationHelper = $this->formatTranslationHelper($change['key'], $change['params']);
                $this->line("     └─ Line {$change['line']}: \"{$change['value']}\" → <fg=green>{$translationHelper}</>");
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

        // Check if any changes were actually made
        if ($results['total_changes'] === 0) {
            $this->newLine();
            $this->components->warn('No changes were applied. The text in the files may not match the stored values.');
            $this->components->info('Reviews were NOT marked as applied. You can edit them in Scanner Review and try again.');

            return self::SUCCESS;
        }

        // Notify API and trigger CDN regeneration
        $this->newLine();
        $this->components->task('Syncing with LangSyncer & regenerating CDN', function () use ($data) {
            $reviewIds = array_column($data['changes'], 'id');
            $this->apiClient->markApplied($reviewIds);

            return true;
        });

        // Success summary
        $this->newLine();
        $fileCount = count($results['files']);
        $this->components->info("✅ Success! {$results['total_changes']} translations applied to {$fileCount} files");
        $this->components->info('📡 CDN regeneration queued - translations will be available shortly');
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

    /**
     * Format translation helper string for display.
     */
    private function formatTranslationHelper(string $key, array $params): string
    {
        if (empty($params)) {
            return "__('{$key}')";
        }

        $paramsArray = collect($params)->map(fn ($p) => "'{$p}' => \${$p}")->implode(', ');

        return "__('{$key}', [{$paramsArray}])";
    }
}
