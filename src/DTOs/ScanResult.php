<?php

namespace Headwires\TranslatorClient\DTOs;

class ScanResult
{
    public function __construct(
        public array $candidates,
        public array $skipped,
        public int $totalFiles,
        public int $totalStrings,
        public array $filesByType = []
    ) {}

    /**
     * Get candidates grouped by file type.
     */
    public function getCandidatesByFileType(): array
    {
        $grouped = [];

        foreach ($this->candidates as $candidate) {
            $type = $candidate->fileType ?? 'unknown';
            $grouped[$type][] = $candidate;
        }

        return $grouped;
    }

    /**
     * Get candidates grouped by confidence level.
     */
    public function getCandidatesByConfidence(callable $confidenceCalculator): array
    {
        $grouped = [
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($this->candidates as $candidate) {
            $confidence = $confidenceCalculator($candidate);
            $level = match (true) {
                $confidence >= 80 => 'high',
                $confidence >= 50 => 'medium',
                default => 'low',
            };
            $grouped[$level][] = $candidate;
        }

        return $grouped;
    }

    /**
     * Get summary statistics.
     */
    public function getSummary(): array
    {
        return [
            'total_files' => $this->totalFiles,
            'total_strings' => $this->totalStrings,
            'candidates' => count($this->candidates),
            'skipped' => count($this->skipped),
            'files_by_type' => $this->filesByType,
        ];
    }
}
