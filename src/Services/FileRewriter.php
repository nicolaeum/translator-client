<?php

namespace Headwires\TranslatorClient\Services;

use Illuminate\Support\Facades\File;

class FileRewriter
{
    /**
     * Apply translation changes to source files.
     */
    public function apply(array $changes): array
    {
        $results = [
            'files' => [],
            'total_changes' => 0,
            'success' => true,
        ];

        // Group changes by file
        $byFile = [];
        foreach ($changes as $change) {
            foreach ($change['locations'] as $location) {
                $file = $location['file'];
                if (! isset($byFile[$file])) {
                    $byFile[$file] = [];
                }
                $byFile[$file][] = [
                    'line' => $location['line'],
                    'key' => $change['key'],
                    'value' => $change['value'],
                    'review_id' => $change['id'],
                ];
            }
        }

        // Apply changes file by file
        foreach ($byFile as $filePath => $fileChanges) {
            $absolutePath = base_path($filePath);

            if (! File::exists($absolutePath)) {
                $results['files'][$filePath] = [
                    'success' => false,
                    'error' => 'File not found',
                    'changes' => 0,
                ];
                $results['success'] = false;

                continue;
            }

            try {
                $content = File::get($absolutePath);
                $lines = explode("\n", $content);
                $changesApplied = 0;

                // Sort by line number descending to avoid offset issues
                usort($fileChanges, fn ($a, $b) => $b['line'] <=> $a['line']);

                foreach ($fileChanges as $change) {
                    $lineIndex = $change['line'] - 1;

                    if (isset($lines[$lineIndex])) {
                        $originalLine = $lines[$lineIndex];
                        $newLine = $this->replaceLine($originalLine, $change['value'], $change['key']);

                        if ($newLine !== $originalLine) {
                            $lines[$lineIndex] = $newLine;
                            $changesApplied++;
                        }
                    }
                }

                if ($changesApplied > 0) {
                    File::put($absolutePath, implode("\n", $lines));
                }

                $results['files'][$filePath] = [
                    'success' => true,
                    'changes' => $changesApplied,
                ];
                $results['total_changes'] += $changesApplied;

            } catch (\Exception $e) {
                $results['files'][$filePath] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'changes' => 0,
                ];
                $results['success'] = false;
            }
        }

        return $results;
    }

    /**
     * Replace text in a line with translation function.
     */
    protected function replaceLine(string $line, string $originalText, string $key): string
    {
        $escaped = preg_quote($originalText, '/');

        // Replace in HTML content: >Text< becomes >{{ __('key') }}<
        $line = preg_replace(
            '/>('.$escaped.')</',
            ">{{ __('{$key}') }}<",
            $line
        );

        // Replace in attributes: placeholder="Text" becomes placeholder="{{ __('key') }}"
        $line = preg_replace(
            '/(placeholder|title|alt|aria-label)=["\']'.$escaped.'["\']/',
            '$1="{{ __(\''.$key.'\') }}"',
            $line
        );

        // Replace standalone string literals: 'Text' becomes __('key')
        $line = preg_replace(
            '/(["\'])'.$escaped.'\\1(?!\s*=>)/',
            "__('{$key}')",
            $line
        );

        return $line;
    }
}
