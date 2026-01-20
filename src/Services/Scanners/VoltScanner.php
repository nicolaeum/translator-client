<?php

namespace Headwires\TranslatorClient\Services\Scanners;

use Headwires\TranslatorClient\Contracts\FileScanner;
use Headwires\TranslatorClient\DTOs\ScanCandidate;

/**
 * Scanner for Volt single-file components.
 *
 * Volt components combine PHP and Blade in a single file,
 * so we need to handle both the PHP block and the Blade template.
 */
class VoltScanner implements FileScanner
{
    private BladeScanner $bladeScanner;

    private PhpScanner $phpScanner;

    public function __construct()
    {
        $this->bladeScanner = new BladeScanner;
        $this->phpScanner = new PhpScanner;
    }

    public function getExtensions(): array
    {
        return ['blade.php'];  // Volt files are .blade.php in resources/views/livewire
    }

    public function getFileType(): string
    {
        return 'volt';
    }

    public function canHandle(string $filePath): bool
    {
        // Check if this is a Volt component (has <?php block at the top)
        if (! str_ends_with($filePath, '.blade.php')) {
            return false;
        }

        // Check if file exists and contains Volt patterns
        if (! file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);

        // Volt components typically start with <?php and use Volt facade or have use function syntax
        return preg_match('/^<\?php/m', $content) &&
               (str_contains($content, 'use function Livewire\\Volt\\') ||
                str_contains($content, 'Volt::') ||
                preg_match('/use\s+function\s+Livewire\\\\Volt\\\\/', $content));
    }

    public function scanFile(string $filePath, string $content): array
    {
        $candidates = [];
        $skipped = [];

        // Split content into PHP and Blade sections
        $sections = $this->splitVoltSections($content);

        // Scan PHP section
        if (! empty($sections['php'])) {
            $phpResult = $this->scanPhpSection($filePath, $sections['php'], $sections['php_start_line']);
            $candidates = array_merge($candidates, $phpResult['candidates']);
            $skipped = array_merge($skipped, $phpResult['skipped']);
        }

        // Scan Blade section
        if (! empty($sections['blade'])) {
            $bladeResult = $this->bladeScanner->scanFile($filePath, $sections['blade']);

            // Adjust line numbers for Blade section
            foreach ($bladeResult['candidates'] as $candidate) {
                $candidate->line += $sections['blade_start_line'];
                $candidate->fileType = 'volt';
                $candidates[] = $candidate;
            }

            foreach ($bladeResult['skipped'] as $skip) {
                if (isset($skip['line'])) {
                    $skip['line'] += $sections['blade_start_line'];
                }
                $skipped[] = $skip;
            }
        }

        return [
            'candidates' => $candidates,
            'skipped' => $skipped,
        ];
    }

    /**
     * Split Volt component into PHP and Blade sections.
     */
    private function splitVoltSections(string $content): array
    {
        $sections = [
            'php' => '',
            'blade' => '',
            'php_start_line' => 0,
            'blade_start_line' => 0,
        ];

        $lines = explode("\n", $content);
        $inPhp = false;
        $phpLines = [];
        $bladeLines = [];
        $phpStartLine = 0;
        $bladeStartLine = 0;

        foreach ($lines as $index => $line) {
            // Detect PHP opening tag
            if (preg_match('/^<\?php/', $line)) {
                $inPhp = true;
                $phpStartLine = $index;
                $phpLines[] = $line;

                continue;
            }

            // Detect PHP closing tag
            if ($inPhp && preg_match('/^\?>/', $line)) {
                $inPhp = false;
                $bladeStartLine = $index + 1;

                continue;
            }

            if ($inPhp) {
                $phpLines[] = $line;
            } else {
                $bladeLines[] = $line;
            }
        }

        $sections['php'] = implode("\n", $phpLines);
        $sections['blade'] = implode("\n", $bladeLines);
        $sections['php_start_line'] = $phpStartLine;
        $sections['blade_start_line'] = $bladeStartLine;

        return $sections;
    }

    /**
     * Scan the PHP section of a Volt component.
     */
    private function scanPhpSection(string $filePath, string $phpContent, int $startLine): array
    {
        $result = $this->phpScanner->scanFile($filePath, $phpContent);

        // Adjust line numbers and file type
        foreach ($result['candidates'] as $candidate) {
            $candidate->line += $startLine;
            $candidate->fileType = 'volt';
        }

        return $result;
    }
}
