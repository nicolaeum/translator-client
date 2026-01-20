<?php

namespace Headwires\TranslatorClient\Services;

use Headwires\TranslatorClient\Contracts\FileScanner;
use Headwires\TranslatorClient\DTOs\ScanCandidate;
use Headwires\TranslatorClient\DTOs\ScanResult;
use Headwires\TranslatorClient\Services\Scanners\BladeScanner;
use Headwires\TranslatorClient\Services\Scanners\PhpScanner;
use Headwires\TranslatorClient\Services\Scanners\VoltScanner;
use Illuminate\Support\Facades\File;

class SourceScanner
{
    /**
     * Registered file scanners.
     *
     * @var array<FileScanner>
     */
    private array $scanners = [];

    /**
     * Directories to exclude from scanning.
     */
    private array $excludedDirectories = [];

    /**
     * Specific files to exclude.
     */
    private array $excludedFiles = [];

    /**
     * Only scan these file patterns.
     */
    private array $includePatterns = [];

    public function __construct()
    {
        // Register default scanners (order matters - more specific first)
        $this->registerScanner(new VoltScanner);
        $this->registerScanner(new BladeScanner);
        $this->registerScanner(new PhpScanner);
        $this->registerScanner(new PhpScanner);
    }

    /**
     * Register a file scanner.
     */
    public function registerScanner(FileScanner $scanner): self
    {
        $this->scanners[] = $scanner;

        return $this;
    }

    /**
     * Set directories to exclude from scanning.
     */
    public function excludeDirectories(array $directories): self
    {
        $this->excludedDirectories = $directories;

        return $this;
    }

    /**
     * Set files to exclude from scanning.
     */
    public function excludeFiles(array $files): self
    {
        $this->excludedFiles = $files;

        return $this;
    }

    /**
     * Set include patterns (glob patterns).
     */
    public function includePatterns(array $patterns): self
    {
        $this->includePatterns = $patterns;

        return $this;
    }

    /**
     * Scan a directory for translatable strings.
     */
    public function scan(string $directory): ScanResult
    {
        $allCandidates = [];
        $allSkipped = [];
        $totalFiles = 0;
        $filesByType = [];

        $files = $this->getFilesToScan($directory);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();

            // Skip excluded files
            if ($this->shouldExcludeFile($filePath)) {
                continue;
            }

            // Find appropriate scanner
            $scanner = $this->getScannerForFile($filePath);
            if (! $scanner) {
                continue;
            }

            $totalFiles++;
            $fileType = $scanner->getFileType();
            $filesByType[$fileType] = ($filesByType[$fileType] ?? 0) + 1;

            // Read file content
            $content = File::get($filePath);

            // Scan the file
            $result = $scanner->scanFile($filePath, $content);

            $allCandidates = array_merge($allCandidates, $result['candidates']);
            $allSkipped = array_merge($allSkipped, $result['skipped']);
        }

        // Deduplicate candidates (same text in same file/line)
        $allCandidates = $this->deduplicateCandidates($allCandidates);

        return new ScanResult(
            candidates: $allCandidates,
            skipped: $allSkipped,
            totalFiles: $totalFiles,
            totalStrings: count($allCandidates) + count($allSkipped),
            filesByType: $filesByType
        );
    }

    /**
     * Scan specific paths (can be files or directories).
     *
     * @param  array<string>  $paths
     */
    public function scanPaths(array $paths): ScanResult
    {
        $allCandidates = [];
        $allSkipped = [];
        $totalFiles = 0;
        $filesByType = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $result = $this->scan($path);
                $allCandidates = array_merge($allCandidates, $result->candidates);
                $allSkipped = array_merge($allSkipped, $result->skipped);
                $totalFiles += $result->totalFiles;
                foreach ($result->filesByType as $type => $count) {
                    $filesByType[$type] = ($filesByType[$type] ?? 0) + $count;
                }
            } elseif (is_file($path)) {
                $scanner = $this->getScannerForFile($path);
                if ($scanner) {
                    $totalFiles++;
                    $fileType = $scanner->getFileType();
                    $filesByType[$fileType] = ($filesByType[$fileType] ?? 0) + 1;

                    $content = File::get($path);
                    $result = $scanner->scanFile($path, $content);

                    $allCandidates = array_merge($allCandidates, $result['candidates']);
                    $allSkipped = array_merge($allSkipped, $result['skipped']);
                }
            }
        }

        $allCandidates = $this->deduplicateCandidates($allCandidates);

        return new ScanResult(
            candidates: $allCandidates,
            skipped: $allSkipped,
            totalFiles: $totalFiles,
            totalStrings: count($allCandidates) + count($allSkipped),
            filesByType: $filesByType
        );
    }

    /**
     * Get files to scan from a directory.
     */
    private function getFilesToScan(string $directory): iterable
    {
        if (! empty($this->includePatterns)) {
            // Use include patterns
            $files = [];
            foreach ($this->includePatterns as $pattern) {
                $matched = File::glob($directory.'/'.$pattern);
                foreach ($matched as $file) {
                    if (is_file($file)) {
                        $files[] = new \SplFileInfo($file);
                    }
                }
            }

            return $files;
        }

        // Scan all files
        return File::allFiles($directory);
    }

    /**
     * Check if a file should be excluded.
     */
    private function shouldExcludeFile(string $filePath): bool
    {
        // Check excluded directories
        foreach ($this->excludedDirectories as $dir) {
            if (str_contains($filePath, '/'.$dir.'/')) {
                return true;
            }
        }

        // Check excluded files
        foreach ($this->excludedFiles as $file) {
            if (str_ends_with($filePath, '/'.$file) || $filePath === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the appropriate scanner for a file.
     */
    private function getScannerForFile(string $filePath): ?FileScanner
    {
        foreach ($this->scanners as $scanner) {
            if ($scanner->canHandle($filePath)) {
                return $scanner;
            }
        }

        return null;
    }

    /**
     * Deduplicate candidates by file, line, and text.
     *
     * @param  array<ScanCandidate>  $candidates
     * @return array<ScanCandidate>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->getIdentifier();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    /**
     * Get registered scanner types.
     *
     * @return array<string>
     */
    public function getRegisteredScannerTypes(): array
    {
        return array_map(
            fn (FileScanner $scanner) => $scanner->getFileType(),
            $this->scanners
        );
    }
}
