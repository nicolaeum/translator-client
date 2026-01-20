<?php

namespace Headwires\TranslatorClient\Contracts;

interface FileScanner
{
    /**
     * Get the file extensions this scanner handles.
     *
     * @return array<string>
     */
    public function getExtensions(): array;

    /**
     * Get the file type identifier.
     */
    public function getFileType(): string;

    /**
     * Check if this scanner can handle the given file.
     */
    public function canHandle(string $filePath): bool;

    /**
     * Scan a file and return candidates and skipped entries.
     *
     * @return array{candidates: array, skipped: array}
     */
    public function scanFile(string $filePath, string $content): array;
}
