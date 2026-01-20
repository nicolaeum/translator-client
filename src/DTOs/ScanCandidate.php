<?php

namespace Headwires\TranslatorClient\DTOs;

class ScanCandidate
{
    public function __construct(
        public string $file,
        public int $line,
        public int $offset,
        public string $text,
        public string $context,
        public string $elementType,
        public string $fileType,
        public array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'offset' => $this->offset,
            'text' => $this->text,
            'context' => $this->context,
            'element_type' => $this->elementType,
            'file_type' => $this->fileType,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get relative path from base path.
     */
    public function getRelativePath(string $basePath): string
    {
        return str_replace($basePath.'/', '', $this->file);
    }

    /**
     * Get a unique identifier for this candidate.
     */
    public function getIdentifier(): string
    {
        return md5($this->file.':'.$this->line.':'.$this->text);
    }
}
