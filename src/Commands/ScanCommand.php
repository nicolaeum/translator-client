<?php

namespace Headwires\TranslatorClient\Commands;

use Headwires\TranslatorClient\DTOs\ScanCandidate;
use Headwires\TranslatorClient\Services\KeyGenerator;
use Headwires\TranslatorClient\Services\ScannerApiClient;
use Headwires\TranslatorClient\Services\SourceScanner;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    protected $signature = 'translator:scan
                            {--project= : Project name from config (uses default if not specified)}
                            {--path=* : Paths to scan (overrides project config)}
                            {--exclude=* : Directories to exclude}
                            {--dry-run : Show what would be found without sending to API}
                            {--min-confidence=0 : Minimum confidence score to include (0-100)}
                            {--format=table : Output format (table, json, csv)}
                            {--output= : Output file path for results}
                            {--list-projects : List configured projects}
                            {--ai : Use AI to enhance key generation and analysis}
                            {--y|yes : Skip confirmation prompts (useful for CI/automation)}';

    protected $description = 'Scan source files for hardcoded text and suggest translation keys';

    private SourceScanner $scanner;

    private KeyGenerator $keyGenerator;

    private ?ScannerApiClient $apiClient = null;

    private ?array $activeProject = null;

    public function handle(
        SourceScanner $scanner,
        KeyGenerator $keyGenerator
    ): int {
        $this->scanner = $scanner;
        $this->keyGenerator = $keyGenerator;

        // Handle --list-projects option
        if ($this->option('list-projects')) {
            return $this->listProjects();
        }

        // Resolve active project from config
        $this->activeProject = $this->resolveProject();

        // Validate project configuration
        if (! $this->activeProject) {
            $this->error('No project configured. Add projects to translator-client.php config.');

            return self::FAILURE;
        }

        if (empty($this->activeProject['api_key'])) {
            $this->error("Project '{$this->activeProject['name']}' has no API key configured.");

            return self::FAILURE;
        }

        $this->info('Starting source translation scanner...');
        if ($this->activeProject) {
            $this->line("Using project: <fg=cyan>{$this->activeProject['name']}</>");
        }
        $this->newLine();

        // Configure scanner
        $this->configureScanner();

        // Get paths to scan
        $paths = $this->getPaths();

        $this->info('Scanning paths:');
        foreach ($paths as $path) {
            $this->line("  - {$path}");
        }
        $this->newLine();

        // Run the scan
        $result = $this->scanner->scanPaths($paths);

        // Display summary
        $this->displaySummary($result);

        if (empty($result->candidates)) {
            $this->info('No translatable strings found.');

            return self::SUCCESS;
        }

        // Generate keys and calculate confidence
        $analyzed = $this->analyzeResults($result->candidates);

        // Filter by minimum confidence
        $minConfidence = (int) $this->option('min-confidence');
        if ($minConfidence > 0) {
            $analyzed = array_filter(
                $analyzed,
                fn ($item) => $item['confidence'] >= $minConfidence
            );
        }

        // Check scanner access early to limit display for free tier
        // Always check if we have an API key - free users only see 3 candidates preview
        $hasScannerAccess = true;
        if ($this->activeProject && ! empty($this->activeProject['api_key'])) {
            $hasScannerAccess = $this->checkScannerAccessEarly();
        }

        // Display results (limited for free tier)
        $this->displayResults($analyzed, $hasScannerAccess);

        // If free tier without access, show upgrade message and stop
        if (! $hasScannerAccess) {
            $this->displayUpgradeMessage(count($analyzed));

            return self::SUCCESS;
        }

        // Output to file if requested
        if ($outputPath = $this->option('output')) {
            $this->outputToFile($analyzed, $outputPath);
        }

        // Handle API integration
        if (! $this->option('dry-run')) {
            return $this->handleApiIntegration($analyzed);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('DRY RUN - No changes sent to API');
        }

        return self::SUCCESS;
    }

    /**
     * Configure the scanner with options.
     */
    private function configureScanner(): void
    {
        // Set excluded directories
        $excludes = $this->option('exclude');
        if (! empty($excludes)) {
            $this->scanner->excludeDirectories($excludes);
        }

        // Default exclusions
        $defaultExcludes = config('translator-client.scanner.excluded_directories', [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
        ]);

        $this->scanner->excludeDirectories(array_merge($defaultExcludes, $excludes));
    }

    /**
     * Get paths to scan.
     */
    private function getPaths(): array
    {
        $paths = $this->option('path');

        if (empty($paths)) {
            // Use project's scan_paths if available
            if ($this->activeProject && ! empty($this->activeProject['scan_paths'])) {
                $paths = $this->activeProject['scan_paths'];
            } else {
                // Default paths
                $paths = [
                    'resources/views',
                    'app',
                ];
            }
        }

        // Convert relative paths to absolute
        $paths = array_map(function ($path) {
            if (! str_starts_with($path, '/')) {
                return base_path($path);
            }

            return $path;
        }, $paths);

        // Filter to existing paths
        return array_filter($paths, fn ($path) => file_exists($path));
    }

    /**
     * Display scan summary.
     */
    private function displaySummary($result): void
    {
        $summary = $result->getSummary();

        $this->info('Scan Summary:');
        $this->line("  Total files scanned: {$summary['total_files']}");
        $this->line("  Total strings found: {$summary['total_strings']}");
        $this->line("  Candidates: {$summary['candidates']}");
        $this->line("  Skipped: {$summary['skipped']}");

        if (! empty($summary['files_by_type'])) {
            $this->newLine();
            $this->line('  Files by type:');
            foreach ($summary['files_by_type'] as $type => $count) {
                $this->line("    - {$type}: {$count}");
            }
        }

        $this->newLine();
    }

    /**
     * Analyze candidates and generate keys.
     */
    private function analyzeResults(array $candidates): array
    {
        $analyzed = [];

        foreach ($candidates as $candidate) {
            $key = $this->keyGenerator->generate($candidate);
            $confidence = $this->keyGenerator->calculateConfidence($candidate, $key);
            $params = $this->keyGenerator->detectParameters($candidate->text);
            $value = ! empty($params)
                ? $this->keyGenerator->applyParameters($candidate->text, $params)
                : $candidate->text;

            $analyzed[] = [
                'candidate' => $candidate,
                'key' => $key,
                'value' => $value,
                'confidence' => $confidence,
                'params' => $params,
                'risk' => $this->assessRisk($candidate, $confidence),
            ];
        }

        // Sort by confidence descending
        usort($analyzed, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $analyzed;
    }

    /**
     * Assess risk level for a candidate.
     */
    private function assessRisk(ScanCandidate $candidate, int $confidence): string
    {
        if ($confidence >= 80) {
            return 'safe';
        }

        if ($confidence >= 50) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * Display results based on format option.
     */
    private function displayResults(array $analyzed, bool $hasScannerAccess = true): void
    {
        $format = $this->option('format');

        // For free tier, only show limited preview in table format
        if (! $hasScannerAccess && $format === 'table') {
            $this->displayLimitedPreview($analyzed);

            return;
        }

        match ($format) {
            'json' => $this->displayJson($analyzed),
            'csv' => $this->displayCsv($analyzed),
            default => $this->displayTable($analyzed),
        };
    }

    /**
     * Display results as a table grouped by confidence.
     */
    private function displayTable(array $analyzed): void
    {
        $grouped = [
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($analyzed as $item) {
            $level = match (true) {
                $item['confidence'] >= 80 => 'high',
                $item['confidence'] >= 50 => 'medium',
                default => 'low',
            };
            $grouped[$level][] = $item;
        }

        foreach (['high', 'medium', 'low'] as $level) {
            $items = $grouped[$level];
            if (empty($items)) {
                continue;
            }

            $icon = match ($level) {
                'high' => '<fg=green>HIGH CONFIDENCE</>',
                'medium' => '<fg=yellow>MEDIUM CONFIDENCE</>',
                'low' => '<fg=red>LOW CONFIDENCE</>',
            };

            $this->newLine();
            $this->line("{$icon} (".count($items).' items)');
            $this->newLine();

            // Show first 15 items per group
            $displayItems = array_slice($items, 0, 15);

            foreach ($displayItems as $item) {
                $candidate = $item['candidate'];
                $relativePath = $this->getRelativePath($candidate->file);

                $this->line("  <fg=cyan>{$relativePath}:{$candidate->line}</>");
                $this->line("    Text: \"{$this->truncate($candidate->text, 60)}\"");
                $this->line("    Key:  <fg=green>{$item['key']}</>");
                $this->line("    Type: {$candidate->elementType} | Confidence: {$item['confidence']}%");

                if (! empty($item['params'])) {
                    $this->line('    Params: '.implode(', ', $item['params']));
                }

                $this->newLine();
            }

            if (count($items) > 15) {
                $remaining = count($items) - 15;
                $this->line("  <fg=gray>... and {$remaining} more</>");
            }
        }

        // Summary
        $this->newLine();
        $this->info('Total: '.count($analyzed).' candidates');
        $this->line('  High confidence: '.count($grouped['high']));
        $this->line('  Medium confidence: '.count($grouped['medium']));
        $this->line('  Low confidence: '.count($grouped['low']));
    }

    /**
     * Display results as JSON.
     */
    private function displayJson(array $analyzed): void
    {
        $output = array_map(fn ($item) => [
            'file' => $item['candidate']->file,
            'line' => $item['candidate']->line,
            'text' => $item['candidate']->text,
            'key' => $item['key'],
            'value' => $item['value'],
            'confidence' => $item['confidence'],
            'risk' => $item['risk'],
            'element_type' => $item['candidate']->elementType,
            'file_type' => $item['candidate']->fileType,
            'params' => $item['params'],
        ], $analyzed);

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Display results as CSV.
     */
    private function displayCsv(array $analyzed): void
    {
        // Header
        $this->line('file,line,text,key,value,confidence,risk,element_type,file_type');

        foreach ($analyzed as $item) {
            $this->line(sprintf(
                '%s,%d,"%s","%s","%s",%d,%s,%s,%s',
                $item['candidate']->file,
                $item['candidate']->line,
                str_replace('"', '""', $item['candidate']->text),
                $item['key'],
                str_replace('"', '""', $item['value']),
                $item['confidence'],
                $item['risk'],
                $item['candidate']->elementType,
                $item['candidate']->fileType
            ));
        }
    }

    /**
     * Output results to a file.
     */
    private function outputToFile(array $analyzed, string $path): void
    {
        $format = pathinfo($path, PATHINFO_EXTENSION) ?: $this->option('format');

        $content = match ($format) {
            'json' => json_encode(
                array_map(fn ($item) => [
                    'file' => $item['candidate']->file,
                    'line' => $item['candidate']->line,
                    'text' => $item['candidate']->text,
                    'key' => $item['key'],
                    'value' => $item['value'],
                    'confidence' => $item['confidence'],
                    'risk' => $item['risk'],
                    'element_type' => $item['candidate']->elementType,
                    'file_type' => $item['candidate']->fileType,
                ], $analyzed),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ),
            default => $this->generateCsvContent($analyzed),
        };

        file_put_contents($path, $content);
        $this->info("Results saved to: {$path}");
    }

    /**
     * Generate CSV content.
     */
    private function generateCsvContent(array $analyzed): string
    {
        $lines = ['file,line,text,key,value,confidence,risk,element_type,file_type'];

        foreach ($analyzed as $item) {
            $lines[] = sprintf(
                '%s,%d,"%s","%s","%s",%d,%s,%s,%s',
                $item['candidate']->file,
                $item['candidate']->line,
                str_replace('"', '""', $item['candidate']->text),
                $item['key'],
                str_replace('"', '""', $item['value']),
                $item['confidence'],
                $item['risk'],
                $item['candidate']->elementType,
                $item['candidate']->fileType
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Handle API integration for sending results.
     */
    private function handleApiIntegration(array $analyzed): int
    {
        $this->newLine();
        $useAI = $this->option('ai');

        try {
            $this->apiClient = new ScannerApiClient(
                config('translator-internal.api_url'),
                $this->activeProject['api_key']
            );

            $candidates = array_map(fn ($item) => [
                'file' => $item['candidate']->file,
                'line' => $item['candidate']->line,
                'text' => $item['candidate']->text,
                'key' => $item['key'],
                'value' => $item['value'],
                'confidence' => $item['confidence'],
                'element_type' => $item['candidate']->elementType,
                'file_type' => $item['candidate']->fileType,
                'context' => $item['candidate']->context,
            ], $analyzed);

            // Note: Scanner access for non-AI mode is already checked early in handle()
            // Free tier users never reach this point - they get preview + upgrade message

            // If using AI, check quota first and confirm
            if ($useAI) {
                if (! $this->confirmAIUsage(count($candidates))) {
                    $this->warn('AI analysis cancelled by user.');
                    $this->line('Run without --ai to use rule-based analysis instead.');

                    return self::SUCCESS;
                }

                $this->info('Sending to LangSyncer API with AI enhancement...');
                $response = $this->apiClient->analyzeWithAI($candidates);
            } else {
                $this->info('Sending to LangSyncer API...');
                $response = $this->apiClient->analyzeAndStore($candidates);
            }

            $processed = $response['processed'] ?? count($candidates);
            $this->newLine();
            $this->info("Successfully processed {$processed} candidates");

            if (! empty($response['created'])) {
                $this->line("  <fg=green>✓ Created:</> {$response['created']} new translations");
            }

            if (! empty($response['skipped'])) {
                $this->line("  <fg=yellow>○ Skipped:</> {$response['skipped']} (already exist)");
            }

            if ($useAI) {
                if (! empty($response['ai_enhanced'])) {
                    $this->line("  <fg=cyan>★ AI enhanced:</> {$response['ai_enhanced']} keys processed");
                }

                if (isset($response['quota_used'])) {
                    $this->line("  <fg=gray>  Quota used:</> {$response['quota_used']}");
                }

                if (isset($response['quota_remaining'])) {
                    $this->line("  <fg=gray>  Quota remaining:</> {$response['quota_remaining']}");
                }
            }

            // Show next steps
            $this->newLine();
            $this->line('<fg=cyan>┌─────────────────────────────────────────┐</>');
            $this->line('<fg=cyan>│</> <fg=white;options=bold>Next Steps</>                             <fg=cyan>│</>');
            $this->line('<fg=cyan>├─────────────────────────────────────────┤</>');
            $this->line('<fg=cyan>│</> Go to <fg=yellow>Scanner Review</> in LangSyncer    <fg=cyan>│</>');
            $this->line('<fg=cyan>│</> to review and approve the suggested   <fg=cyan>│</>');
            $this->line('<fg=cyan>│</> translations.                         <fg=cyan>│</>');
            $this->line('<fg=cyan>└─────────────────────────────────────────┘</>');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('API Error: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Confirm AI usage with quota estimation.
     */
    private function confirmAIUsage(int $candidateCount): bool
    {
        // Skip confirmation if --yes flag is passed
        if ($this->option('yes')) {
            return true;
        }

        $this->info('Checking AI quota...');

        try {
            $estimate = $this->apiClient->estimateQuota($candidateCount);

            // Display quota information
            $this->newLine();
            $this->line('<fg=cyan>┌─────────────────────────────────────────┐</>');
            $this->line('<fg=cyan>│</> <fg=white;options=bold>AI Quota Information</>                  <fg=cyan>│</>');
            $this->line('<fg=cyan>├─────────────────────────────────────────┤</>');

            $quota = $estimate['quota'] ?? [];
            $est = $estimate['estimate'] ?? [];
            $tier = $estimate['tier'] ?? 'unknown';

            $tierQuota = $quota['total'] ?? 0;
            $tierUsed = $quota['used'] ?? 0;
            $tierRemaining = $quota['remaining'] ?? 0;
            $purchasedQuota = $quota['purchased'] ?? 0;
            $totalRemaining = $quota['total_remaining'] ?? $tierRemaining;

            $this->line(sprintf(
                '<fg=cyan>│</> Tier: <fg=yellow>%-30s</><fg=cyan>│</>',
                $tier
            ));

            // Show tier quota
            $this->line(sprintf(
                '<fg=cyan>│</> Plan quota: <fg=green>%d</> / %d used%-14s<fg=cyan>│</>',
                $tierUsed,
                $tierQuota,
                ''
            ));

            // Show purchased quota if any
            if ($purchasedQuota > 0) {
                $this->line(sprintf(
                    '<fg=cyan>│</> Extra quota: <fg=magenta>+%d</> available%-12s<fg=cyan>│</>',
                    $purchasedQuota,
                    ''
                ));
            }

            // Show total available
            $this->line(sprintf(
                '<fg=cyan>│</> Total available: <fg=white;options=bold>%d</>%-20s<fg=cyan>│</>',
                $totalRemaining,
                ''
            ));

            $this->line('<fg=cyan>├─────────────────────────────────────────┤</>');

            $this->line(sprintf(
                '<fg=cyan>│</> Candidates to process: <fg=white>%d</>%-15s<fg=cyan>│</>',
                $candidateCount,
                ''
            ));

            $willExceed = $est['will_exceed'] ?? false;
            $remainingAfter = $est['remaining_after'] ?? 0;

            if ($willExceed) {
                $this->line('<fg=cyan>│</> <fg=red;options=bold>⚠ WARNING: Exceeds your quota!</>         <fg=cyan>│</>');
            } else {
                $this->line(sprintf(
                    '<fg=cyan>│</> After analysis: <fg=green>%d</> remaining%-9s<fg=cyan>│</>',
                    $remainingAfter,
                    ''
                ));
            }

            $this->line('<fg=cyan>└─────────────────────────────────────────┘</>');
            $this->newLine();

            // Check if AI is available
            if (! ($estimate['can_use_ai'] ?? false)) {
                $this->error('AI analysis is not available for your account.');
                $this->line('Upgrade your plan to access AI-powered key generation.');

                return false;
            }

            // Check if quota would be exceeded
            if ($willExceed) {
                $this->warn('You do not have enough quota for this operation.');
                $this->line('Options:');
                $this->line('  1. Reduce the number of candidates (use --min-confidence)');
                $this->line('  2. Purchase additional quota');
                $this->line('  3. Run without --ai for rule-based analysis');

                return false;
            }

            // Ask for confirmation
            return $this->confirm(
                "This will consume <fg=yellow>{$candidateCount}</> AI quota. Proceed?",
                true
            );

        } catch (\Exception $e) {
            $this->warn('Could not check quota: '.$e->getMessage());
            $this->line('Proceeding anyway...');

            return $this->confirm('Continue with AI analysis?', false);
        }
    }

    /**
     * Resolve the active project from config.
     */
    private function resolveProject(): ?array
    {
        $projects = config('translator-client.projects', []);

        if (empty($projects)) {
            return null;
        }

        $projectName = $this->option('project');

        // If project specified, find by name
        if ($projectName) {
            foreach ($projects as $project) {
                if (($project['name'] ?? '') === $projectName) {
                    return $project;
                }
            }

            $this->error("Project '{$projectName}' not found in config.");
            $this->line('Available projects:');
            foreach ($projects as $project) {
                $this->line("  - {$project['name']}");
            }

            return null;
        }

        // If only one project, use it automatically
        if (count($projects) === 1) {
            return $projects[0];
        }

        // Multiple projects configured, user must specify
        $this->error('Multiple projects configured. Please specify which one to use with --project=<name>');
        $this->line('Available projects:');
        foreach ($projects as $project) {
            $this->line("  - {$project['name']}");
        }
        $this->newLine();
        $this->line('Use --list-projects for more details.');

        return null;
    }

    /**
     * List configured projects.
     */
    private function listProjects(): int
    {
        $projects = config('translator-client.projects', []);

        if (empty($projects)) {
            $this->warn('No projects configured.');
            $this->line('Add projects to your translator-client.php config file.');

            return self::SUCCESS;
        }

        $this->info('Configured projects:');
        $this->newLine();

        foreach ($projects as $project) {
            $name = $project['name'] ?? 'unnamed';
            $hasKey = ! empty($project['api_key']);
            $scanPaths = $project['scan_paths'] ?? [];

            $this->line(sprintf('  <fg=cyan>%s</>', $name));
            $this->line(sprintf(
                '    API key: %s',
                $hasKey ? '<fg=green>configured</>' : '<fg=red>missing</>'
            ));

            if (! empty($scanPaths)) {
                $this->line('    Scan paths:');
                foreach ($scanPaths as $path) {
                    $this->line("      - {$path}");
                }
            } else {
                $this->line('    Scan paths: <fg=yellow>using defaults</>');
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Get relative path from base path.
     */
    private function getRelativePath(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    /**
     * Truncate text for display.
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3).'...';
    }

    /**
     * Check scanner access early without displaying anything.
     * Used to determine if we should limit display for free tier.
     *
     * @return bool True if user has scanner access, false for free tier
     */
    private function checkScannerAccessEarly(): bool
    {
        try {
            $apiClient = new ScannerApiClient(
                config('translator-internal.api_url'),
                $this->activeProject['api_key']
            );

            $estimate = $apiClient->estimateQuota(1);

            return $estimate['has_scanner_access'] ?? true;
        } catch (\Exception $e) {
            // If we can't check, assume they have access
            return true;
        }
    }

    /**
     * Display limited preview for free tier users.
     * Shows only first 3 candidates as a teaser.
     */
    private function displayLimitedPreview(array $analyzed): void
    {
        $this->newLine();
        $this->line('<fg=cyan>┌─────────────────────────────────────────┐</>');
        $this->line('<fg=cyan>│</> <fg=white;options=bold>Scanner Preview (Free Tier)</>           <fg=cyan>│</>');
        $this->line('<fg=cyan>└─────────────────────────────────────────┘</>');
        $this->newLine();

        $total = count($analyzed);
        $this->line("<fg=yellow>Found {$total} translatable strings. Here's a preview:</>");
        $this->newLine();

        // Show first 3 candidates
        $preview = array_slice($analyzed, 0, 3);
        foreach ($preview as $index => $item) {
            $candidate = $item['candidate'];
            $relativePath = $this->getRelativePath($candidate->file);
            $confidenceColor = match (true) {
                $item['confidence'] >= 80 => 'green',
                $item['confidence'] >= 50 => 'yellow',
                default => 'red',
            };

            $this->line(sprintf('  <fg=cyan>%d.</> %s:%d', $index + 1, $relativePath, $candidate->line));
            $this->line(sprintf('     Text: "<fg=white>%s</>"', $this->truncate($candidate->text, 50)));
            $this->line(sprintf('     Key:  <fg=green>%s</>', $item['key']));
            $this->line(sprintf('     Confidence: <fg=%s>%d%%</>', $confidenceColor, $item['confidence']));
            $this->newLine();
        }

        if ($total > 3) {
            $remaining = $total - 3;
            $this->line(sprintf('  <fg=gray>... and %d more candidates</>', $remaining));
            $this->newLine();
        }
    }

    /**
     * Display upgrade message for free tier users.
     */
    private function displayUpgradeMessage(int $totalCandidates): void
    {
        $this->line('<fg=yellow>┌─────────────────────────────────────────┐</>');
        $this->line('<fg=yellow>│</> <fg=white;options=bold>Upgrade to unlock full scanner</>        <fg=yellow>│</>');
        $this->line('<fg=yellow>├─────────────────────────────────────────┤</>');
        $this->line('<fg=yellow>│</> • Send all candidates to review       <fg=yellow>│</>');
        $this->line('<fg=yellow>│</> • AI-powered key generation           <fg=yellow>│</>');
        $this->line('<fg=yellow>│</> • Auto-apply to source files          <fg=yellow>│</>');
        $this->line('<fg=yellow>│</>                                         <fg=yellow>│</>');
        $this->line('<fg=yellow>│</> Visit your Subscription settings      <fg=yellow>│</>');
        $this->line('<fg=yellow>│</> to upgrade your plan.                 <fg=yellow>│</>');
        $this->line('<fg=yellow>└─────────────────────────────────────────┘</>');
        $this->newLine();
    }
}
