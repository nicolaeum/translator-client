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
                    'params' => $change['params'] ?? [],
                    'review_id' => $change['id'],
                ];
            }
        }

        // Apply changes file by file
        foreach ($byFile as $filePath => $fileChanges) {
            // Use path as-is if absolute, otherwise resolve from base_path
            $absolutePath = str_starts_with($filePath, '/') ? $filePath : base_path($filePath);

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
                        $newLine = $this->replaceLine(
                            $originalLine,
                            $change['value'],
                            $change['key'],
                            $change['params'] ?? []
                        );

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
    protected function replaceLine(string $line, string $originalText, string $key, array $params = []): string
    {
        $escaped = preg_quote($originalText, '/');

        // Build the translation helper with or without params
        $translationHelper = $this->buildTranslationHelper($key, $params);
        $bladeHelper = $this->buildBladeTranslationHelper($key, $params);

        // Replace in HTML content: >Text< becomes >{{ __('key') }}<
        $line = preg_replace(
            '/>('.$escaped.')</',
            ">{$bladeHelper}<",
            $line
        );

        // Replace in attributes: placeholder="Text" becomes placeholder="{{ __('key') }}"
        $line = preg_replace(
            '/(placeholder|title|alt|aria-label)=["\']'.$escaped.'["\']/',
            '$1="'.$bladeHelper.'"',
            $line
        );

        // Replace standalone string literals: 'Text' becomes __('key')
        $line = preg_replace(
            '/(["\'])'.$escaped.'\\1(?!\s*=>)/',
            $translationHelper,
            $line
        );

        return $line;
    }

    /**
     * Build the PHP translation helper string.
     * Without params: __('key')
     * With params: __('key', ['name' => $name])
     */
    protected function buildTranslationHelper(string $key, array $params): string
    {
        if (empty($params)) {
            return "__('{$key}')";
        }

        $paramsArray = collect($params)->map(fn ($p) => "'{$p}' => \${$p}")->implode(', ');

        return "__('{$key}', [{$paramsArray}])";
    }

    /**
     * Build the Blade translation helper string.
     * Without params: {{ __('key') }}
     * With params: {{ __('key', ['name' => $name]) }}
     */
    protected function buildBladeTranslationHelper(string $key, array $params): string
    {
        return '{{ '.$this->buildTranslationHelper($key, $params).' }}';
    }
}
