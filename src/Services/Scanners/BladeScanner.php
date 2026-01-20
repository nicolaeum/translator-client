<?php

namespace Headwires\TranslatorClient\Services\Scanners;

use Headwires\TranslatorClient\Contracts\FileScanner;
use Headwires\TranslatorClient\DTOs\ScanCandidate;

class BladeScanner implements FileScanner
{
    /**
     * Translation function patterns that indicate already-translated text.
     */
    private const TRANSLATION_PATTERNS = [
        '@lang',
        'trans',
        '__',
        '@choice',
        'trans_choice',
    ];

    /**
     * Patterns to skip (CSS classes, data attributes, Alpine.js, etc).
     */
    private const SKIP_PATTERNS = [
        '/^(class|id|name|type|method|action|href|src|rel|target)=/',
        '/^(data-|x-|wire:|@click|@|:class|:id|:)/',
        '/^\$/',
        '/^[0-9]+(\.[0-9]+)?$/',
        '/^https?:\/\//',
        '/^mailto:/',
        '/^tel:/',
        '/^#[a-fA-F0-9]{3,8}$/',
        '/^[a-z0-9_-]+$/',  // Likely CSS class or identifier
        '/^[\s\S]*\{[\s\S]*\}[\s\S]*$/',  // JSON-like content
        '/^\s*$/',  // Empty or whitespace only
        // View/component paths
        '/^[a-z0-9_-]+::[a-z0-9_.-]+$/i',  // Package views (base-tenant::layouts.app)
        '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+$/i',  // Dot notation views (livewire.component)
        // Icon names
        '/^[a-z]+-[a-z-]+$/i',  // heroicon-o-user, flux-icon
    ];

    /**
     * Context patterns that indicate non-translatable strings in Blade.
     */
    private const NON_TRANSLATABLE_CONTEXTS = [
        // Blade view directives
        '@extends(',
        '@include(',
        '@includeIf(',
        '@includeWhen(',
        '@includeFirst(',
        '@component(',
        '@livewire(',
        '@livewireStyles',
        '@livewireScripts',
        // Section/stack names
        '@section(',
        '@yield(',
        '@push(',
        '@pushOnce(',
        '@stack(',
        '@slot(',
        // Routes and URLs
        'route(',
        'url(',
        'asset(',
        'mix(',
        'vite(',
        'secure_url(',
        // Config and env
        'config(',
        'env(',
        // Other technical
        'storage_path(',
        'public_path(',
        'base_path(',
        'resource_path(',
        'app_path(',
        // Flux/Livewire attributes that take component names
        ':icon=',
        'icon="',
        "icon='",
        ':name=',
        ':variant=',
        'variant="',
        ':size=',
        'size="',
    ];

    /**
     * Attribute names that should be translated.
     */
    private const TRANSLATABLE_ATTRIBUTES = [
        'placeholder',
        'title',
        'alt',
        'aria-label',
        'aria-description',
        'label',
    ];

    public function getExtensions(): array
    {
        return ['blade.php'];
    }

    public function getFileType(): string
    {
        return 'blade';
    }

    public function canHandle(string $filePath): bool
    {
        return str_ends_with($filePath, '.blade.php');
    }

    public function scanFile(string $filePath, string $content): array
    {
        $candidates = [];
        $skipped = [];
        $lines = explode("\n", $content);

        // Track state for multi-line elements
        $inScript = false;
        $inStyle = false;
        $inPhpBlock = false;

        foreach ($lines as $lineNumber => $line) {
            // Track script/style blocks
            if (preg_match('/<script[^>]*>/i', $line)) {
                $inScript = true;
            }
            if (preg_match('/<\/script>/i', $line)) {
                $inScript = false;

                continue;
            }
            if (preg_match('/<style[^>]*>/i', $line)) {
                $inStyle = true;
            }
            if (preg_match('/<\/style>/i', $line)) {
                $inStyle = false;

                continue;
            }

            // Skip script/style content
            if ($inScript || $inStyle) {
                continue;
            }

            // Track PHP blocks
            if (preg_match('/@php\b/', $line)) {
                $inPhpBlock = true;
            }
            if (preg_match('/@endphp\b/', $line)) {
                $inPhpBlock = false;

                continue;
            }

            // Skip @php blocks (handled by PHP scanner)
            if ($inPhpBlock) {
                continue;
            }

            // Scan PHP string literals in Blade
            $this->scanPhpStrings($filePath, $line, $lineNumber, $content, $candidates, $skipped);

            // Scan translatable HTML attributes
            $this->scanHtmlAttributes($filePath, $line, $lineNumber, $content, $candidates, $skipped);

            // Scan plain HTML text content
            $this->scanHtmlText($filePath, $line, $lineNumber, $content, $candidates, $skipped);
        }

        return [
            'candidates' => $candidates,
            'skipped' => $skipped,
        ];
    }

    /**
     * Scan PHP string literals embedded in Blade.
     */
    private function scanPhpStrings(
        string $filePath,
        string $line,
        int $lineNumber,
        string $fullContent,
        array &$candidates,
        array &$skipped
    ): void {
        // Match PHP string literals (single and double quoted)
        if (preg_match_all('/(["\'])(?:(?=(\\\\?))\2.)*?\1/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$literal, $offset]) {
                $text = trim($literal, "'\"");

                if ($this->shouldSkip($text, $line, $offset)) {
                    $skipped[] = ['text' => $text, 'reason' => 'pattern_match', 'line' => $lineNumber + 1];

                    continue;
                }

                if ($this->isAlreadyTranslated($line, $offset)) {
                    $skipped[] = ['text' => $text, 'reason' => 'already_translated', 'line' => $lineNumber + 1];

                    continue;
                }

                $candidates[] = new ScanCandidate(
                    file: $filePath,
                    line: $lineNumber + 1,
                    offset: $offset,
                    text: $text,
                    context: $this->getContext($fullContent, $this->getGlobalOffset($fullContent, $lineNumber, $offset)),
                    elementType: $this->detectElementType($line, $offset),
                    fileType: 'blade',
                    metadata: [
                        'in_attribute' => $this->isInAttribute($line, $offset),
                        'quote_type' => $literal[0],
                    ]
                );
            }
        }
    }

    /**
     * Scan HTML attributes that should be translated.
     */
    private function scanHtmlAttributes(
        string $filePath,
        string $line,
        int $lineNumber,
        string $fullContent,
        array &$candidates,
        array &$skipped
    ): void {
        foreach (self::TRANSLATABLE_ATTRIBUTES as $attr) {
            $pattern = '/'.$attr.'\s*=\s*(["\'])([^"\']+)\1/i';
            if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[2] as $index => [$text, $offset]) {
                    if ($this->shouldSkip($text, $line, $offset)) {
                        $skipped[] = ['text' => $text, 'reason' => 'pattern_match', 'line' => $lineNumber + 1];

                        continue;
                    }

                    // Skip if it contains Blade/PHP syntax
                    if (preg_match('/(\{\{|\{\!\!|@|\$)/', $text)) {
                        $skipped[] = ['text' => $text, 'reason' => 'contains_blade', 'line' => $lineNumber + 1];

                        continue;
                    }

                    $candidates[] = new ScanCandidate(
                        file: $filePath,
                        line: $lineNumber + 1,
                        offset: $offset,
                        text: $text,
                        context: $line,
                        elementType: $attr.'_attr',
                        fileType: 'blade',
                        metadata: [
                            'attribute_name' => $attr,
                            'in_attribute' => true,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Scan plain HTML text content.
     */
    private function scanHtmlText(
        string $filePath,
        string $line,
        int $lineNumber,
        string $fullContent,
        array &$candidates,
        array &$skipped
    ): void {
        // Remove Blade/PHP constructs
        $cleaned = $this->removeBladeConstructs($line);

        // Remove HTML tags to get text content
        $textOnly = strip_tags($cleaned);
        $textOnly = html_entity_decode($textOnly, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textOnly = trim($textOnly);

        // Skip if empty or too short
        if ($textOnly === '' || strlen($textOnly) < 3) {
            return;
        }

        // Skip if purely numeric
        if (is_numeric($textOnly)) {
            return;
        }

        if ($this->shouldSkip($textOnly, $line, 0)) {
            $skipped[] = ['text' => $textOnly, 'reason' => 'pattern_match', 'line' => $lineNumber + 1];

            return;
        }

        // Find position in original line
        $offset = strpos($line, $textOnly) ?: 0;

        $candidates[] = new ScanCandidate(
            file: $filePath,
            line: $lineNumber + 1,
            offset: $offset,
            text: $textOnly,
            context: $line,
            elementType: $this->detectHtmlElementType($line),
            fileType: 'blade',
            metadata: [
                'is_plain_text' => true,
                'surrounding_tag' => $this->getSurroundingTag($line),
            ]
        );
    }

    /**
     * Check if text should be skipped.
     */
    private function shouldSkip(string $text, string $line, int $offset): bool
    {
        // Too short
        if (strlen($text) < 3) {
            return true;
        }

        // Pure number
        if (is_numeric($text)) {
            return true;
        }

        // Contains only special characters
        if (preg_match('/^[\s\W]+$/', $text)) {
            return true;
        }

        // Check skip patterns
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Check context for things like class="" or data-
        $beforeText = substr($line, max(0, $offset - 20), 20);
        if (preg_match('/(class|id|name|data-\w+|wire:|x-)\s*=\s*["\']$/', $beforeText)) {
            return true;
        }

        // Check non-translatable contexts
        if ($this->isNonTranslatableContext($line, $offset)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the string is in a non-translatable context.
     */
    private function isNonTranslatableContext(string $line, int $offset): bool
    {
        // Get text before the string (context)
        $beforeText = substr($line, max(0, $offset - 50), min($offset, 50));

        foreach (self::NON_TRANSLATABLE_CONTEXTS as $pattern) {
            // Check if pattern appears right before the string
            if (str_ends_with(rtrim($beforeText), rtrim($pattern, '(')) ||
                str_contains($beforeText, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text is already wrapped in a translation function.
     */
    private function isAlreadyTranslated(string $line, int $offset): bool
    {
        $before = substr($line, max(0, $offset - 30), min($offset, 30));

        foreach (self::TRANSLATION_PATTERNS as $pattern) {
            if (preg_match('/'.preg_quote($pattern, '/').'\s*\(\s*$/u', $before)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the element type based on context.
     */
    private function detectElementType(string $line, int $offset): string
    {
        $before = substr($line, max(0, $offset - 50), min($offset, 50));

        // Check for specific element patterns
        if (preg_match('/<(h[1-6])[^>]*>$/i', $before, $m)) {
            return 'heading_'.$m[1];
        }
        if (preg_match('/<button[^>]*>$/i', $before)) {
            return 'button';
        }
        if (preg_match('/<label[^>]*>$/i', $before)) {
            return 'label';
        }
        if (preg_match('/<a[^>]*>$/i', $before)) {
            return 'link';
        }
        if (preg_match('/<p[^>]*>$/i', $before)) {
            return 'paragraph';
        }
        if (preg_match('/<span[^>]*>$/i', $before)) {
            return 'span';
        }
        if (preg_match('/<(td|th)[^>]*>$/i', $before)) {
            return 'table_cell';
        }
        if (preg_match('/<li[^>]*>$/i', $before)) {
            return 'list_item';
        }
        if (preg_match('/placeholder\s*=\s*["\']$/i', $before)) {
            return 'placeholder';
        }
        if (preg_match('/title\s*=\s*["\']$/i', $before)) {
            return 'title_attr';
        }
        if (preg_match('/alt\s*=\s*["\']$/i', $before)) {
            return 'alt_attr';
        }

        return 'string_literal';
    }

    /**
     * Detect HTML element type from tag structure.
     */
    private function detectHtmlElementType(string $line): string
    {
        if (preg_match('/<(h[1-6])\b/i', $line, $m)) {
            return 'heading_'.$m[1];
        }
        if (preg_match('/<button\b/i', $line)) {
            return 'button';
        }
        if (preg_match('/<label\b/i', $line)) {
            return 'label';
        }
        if (preg_match('/<a\b/i', $line)) {
            return 'link';
        }
        if (preg_match('/<p\b/i', $line)) {
            return 'paragraph';
        }
        if (preg_match('/<span\b/i', $line)) {
            return 'span';
        }
        if (preg_match('/<(td|th)\b/i', $line)) {
            return 'table_cell';
        }
        if (preg_match('/<li\b/i', $line)) {
            return 'list_item';
        }
        if (preg_match('/<option\b/i', $line)) {
            return 'option';
        }

        return 'html_text';
    }

    /**
     * Get surrounding tag name.
     */
    private function getSurroundingTag(string $line): ?string
    {
        if (preg_match('/<(\w+)[^>]*>/', $line, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Check if offset is within an attribute value.
     */
    private function isInAttribute(string $line, int $offset): bool
    {
        $before = substr($line, 0, $offset);

        return (bool) preg_match('/\w+\s*=\s*["\']$/i', $before);
    }

    /**
     * Get context around a position.
     */
    private function getContext(string $content, int $offset, int $length = 100): string
    {
        $start = max(0, $offset - $length);
        $end = min(strlen($content), $offset + $length);

        return substr($content, $start, $end - $start);
    }

    /**
     * Calculate global offset from line number and local offset.
     */
    private function getGlobalOffset(string $content, int $lineNumber, int $localOffset): int
    {
        $lines = explode("\n", $content);
        $offset = 0;

        for ($i = 0; $i < $lineNumber; $i++) {
            $offset += strlen($lines[$i]) + 1; // +1 for newline
        }

        return $offset + $localOffset;
    }

    /**
     * Remove Blade/PHP constructs from a line.
     */
    private function removeBladeConstructs(string $line): string
    {
        // Remove raw output
        $line = preg_replace('/\{!!.*?!!\}/s', '', $line);
        // Remove escaped output
        $line = preg_replace('/\{\{.*?\}\}/s', '', $line);
        // Remove Blade directives
        $line = preg_replace('/@\w+(\((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*\))?/s', '', $line);
        // Remove PHP tags
        $line = preg_replace('/<\?php.*?\?>/s', '', $line);
        $line = preg_replace('/<\?=.*?\?>/s', '', $line);

        return $line;
    }
}
