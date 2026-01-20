<?php

namespace Headwires\TranslatorClient\Services\Scanners;

use Headwires\TranslatorClient\Contracts\FileScanner;
use Headwires\TranslatorClient\DTOs\ScanCandidate;

class PhpScanner implements FileScanner
{
    /**
     * Translation function patterns that indicate already-translated text.
     */
    private const TRANSLATION_PATTERNS = [
        '__(',
        'trans(',
        'trans_choice(',
        '@lang(',
        'Lang::get(',
        'Lang::choice(',
    ];

    /**
     * Method calls that typically contain user-facing strings.
     */
    private const USER_FACING_METHODS = [
        // Flash messages
        'flash',
        'with',
        'withSuccess',
        'withError',
        'withWarning',
        'withInfo',
        // Validation
        'message',
        'messages',
        // Livewire
        'dispatch',
        'dispatchBrowserEvent',
        'emit',
        'emitUp',
        'emitTo',
        // Exceptions
        'abort',
        'throw',
        // Notifications
        'success',
        'error',
        'warning',
        'info',
        'notify',
        // Labels and titles
        'setTitle',
        'setDescription',
        'setLabel',
        'label',
        'placeholder',
        'hint',
        'helperText',
    ];

    /**
     * Patterns to skip.
     */
    private const SKIP_PATTERNS = [
        '/^[a-z_\.]+$/',  // Likely a translation key
        '/^[A-Z][a-z]+([A-Z][a-z]+)*$/',  // PascalCase (likely class name)
        '/^[a-z]+(_[a-z]+)*$/',  // snake_case (likely variable/key)
        '/^[a-z]+(-[a-z]+)*$/',  // kebab-case (likely CSS class)
        '/^\$/',  // Variable reference
        '/^[0-9]+(\.[0-9]+)?$/',  // Numbers
        '/^https?:\/\//',  // URLs
        '/^[\\\\\w]+$/',  // Class names with namespace
        '/^[a-z_]+\.[a-z_]+(\.[a-z_]+)*$/i',  // Dot notation (config, routes, translations)
        '/^\s*$/',  // Empty
        '/^<[^>]+>$/',  // HTML tags only
        // Package view/layout names (base-tenant::layouts.app, flux::button)
        '/^[a-z0-9_-]+::[a-z0-9_.-]+$/i',
        // Dot notation with dashes (livewire.admin.settings.manage-setting)
        '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+$/i',
        // Date/time formats
        '/^[YyMmDdHhIiSsAaGg][-\/:\s\.]+[YyMmDdHhIiSsAaGg]/',
        '/^[dDjlNSwzWFmMntLoYyaABgGhHisuveIOPTZcrU\-\/:\s\.]+$/',
    ];

    /**
     * Laravel validation rule keywords.
     */
    private const VALIDATION_KEYWORDS = [
        'accepted', 'active_url', 'after', 'after_or_equal', 'alpha', 'alpha_dash',
        'alpha_num', 'array', 'bail', 'before', 'before_or_equal', 'between',
        'boolean', 'confirmed', 'current_password', 'date', 'date_equals', 'date_format',
        'declined', 'different', 'digits', 'digits_between', 'dimensions', 'distinct',
        'email', 'ends_with', 'enum', 'exclude', 'exclude_if', 'exclude_unless',
        'exists', 'file', 'filled', 'gt', 'gte', 'image', 'in', 'in_array',
        'integer', 'ip', 'ipv4', 'ipv6', 'json', 'lt', 'lte', 'mac_address',
        'max', 'mimes', 'mimetypes', 'min', 'multiple_of', 'not_in', 'not_regex',
        'nullable', 'numeric', 'password', 'present', 'prohibited', 'prohibited_if',
        'prohibited_unless', 'prohibits', 'regex', 'required', 'required_if',
        'required_unless', 'required_with', 'required_with_all', 'required_without',
        'required_without_all', 'same', 'size', 'sometimes', 'starts_with', 'string',
        'timezone', 'unique', 'url', 'uuid',
    ];

    /**
     * Context patterns that indicate non-translatable strings.
     */
    private const NON_TRANSLATABLE_CONTEXTS = [
        'use ',
        'namespace ',
        'extends ',
        'implements ',
        'class ',
        '->where(',
        '->orderBy(',
        '->groupBy(',
        '->select(',
        '->join(',
        '->table(',
        '::class',
        'Route::',
        'config(',
        'env(',
        'Log::',
        'Cache::',
        'Storage::',
        'Session::',
        'Cookie::',
        'DB::',
        'Schema::',
        // Views and layouts
        '->layout(',
        '->view(',
        'view(',
        '->component(',
        '->extends(',
        '->include(',
        '->slot(',
        // Routes
        '->name(',
        '->route(',
        'route(',
        '->middleware(',
        '->prefix(',
        '->domain(',
        // Validation
        '->rules(',
        '->validate(',
        'Validator::',
        'Rule::',
        // Other technical contexts
        '->disk(',
        '->queue(',
        '->connection(',
        '->driver(',
        'dispatch(',
        '->onQueue(',
        '->onConnection(',
    ];

    public function getExtensions(): array
    {
        return ['php'];
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function canHandle(string $filePath): bool
    {
        // Handle .php files but not .blade.php
        return str_ends_with($filePath, '.php') && ! str_ends_with($filePath, '.blade.php');
    }

    public function scanFile(string $filePath, string $content): array
    {
        $candidates = [];
        $skipped = [];
        $lines = explode("\n", $content);

        // Detect file context
        $fileContext = $this->detectFileContext($filePath, $content);

        // Track multi-line state
        $inDocBlock = false;
        $inHeredoc = false;

        foreach ($lines as $lineNumber => $line) {
            // Track doc blocks
            if (preg_match('/\/\*\*/', $line)) {
                $inDocBlock = true;
            }
            if (preg_match('/\*\//', $line)) {
                $inDocBlock = false;

                continue;
            }

            // Skip doc blocks and single-line comments
            if ($inDocBlock || preg_match('/^\s*\/\//', $line) || preg_match('/^\s*#/', $line)) {
                continue;
            }

            // Track heredoc/nowdoc
            if (preg_match('/<<<([\'"]?)(\w+)\1/', $line)) {
                $inHeredoc = true;
            }
            if ($inHeredoc && preg_match('/^\s*\w+;?\s*$/', $line)) {
                $inHeredoc = false;

                continue;
            }
            if ($inHeredoc) {
                continue;
            }

            // Skip non-translatable contexts
            if ($this->isNonTranslatableContext($line)) {
                continue;
            }

            // Scan string literals
            $this->scanStringLiterals($filePath, $line, $lineNumber, $content, $fileContext, $candidates, $skipped);
        }

        return [
            'candidates' => $candidates,
            'skipped' => $skipped,
        ];
    }

    /**
     * Detect the context of the PHP file.
     */
    private function detectFileContext(string $filePath, string $content): array
    {
        $context = [
            'type' => 'unknown',
            'class' => null,
            'namespace' => null,
        ];

        // Extract namespace
        if (preg_match('/namespace\s+([\w\\\\]+);/', $content, $m)) {
            $context['namespace'] = $m[1];
        }

        // Detect file type
        if (str_contains($filePath, '/Livewire/') || str_contains($content, 'extends Component')) {
            $context['type'] = 'livewire';
        } elseif (str_contains($filePath, '/Controllers/') || str_contains($content, 'extends Controller')) {
            $context['type'] = 'controller';
        } elseif (str_contains($filePath, '/Models/') || str_contains($content, 'extends Model')) {
            $context['type'] = 'model';
        } elseif (str_contains($filePath, '/Services/')) {
            $context['type'] = 'service';
        } elseif (str_contains($filePath, '/Jobs/') || str_contains($content, 'implements ShouldQueue')) {
            $context['type'] = 'job';
        } elseif (str_contains($filePath, '/Notifications/')) {
            $context['type'] = 'notification';
        } elseif (str_contains($filePath, '/Mail/')) {
            $context['type'] = 'mail';
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $m)) {
            $context['class'] = $m[1];
        }

        return $context;
    }

    /**
     * Scan string literals in a line.
     */
    private function scanStringLiterals(
        string $filePath,
        string $line,
        int $lineNumber,
        string $fullContent,
        array $fileContext,
        array &$candidates,
        array &$skipped
    ): void {
        // Match single and double quoted strings
        $pattern = '/(["\'])(?:(?!\1|\\\\).|\\\\.)*\1/';

        if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$literal, $offset]) {
                $text = substr($literal, 1, -1);  // Remove quotes
                $text = $this->unescapeString($text, $literal[0]);

                // Skip empty or too short
                if (strlen($text) < 3) {
                    continue;
                }

                // Skip if matches skip patterns
                if ($this->shouldSkip($text)) {
                    $skipped[] = ['text' => $text, 'reason' => 'pattern_match', 'line' => $lineNumber + 1];

                    continue;
                }

                // Skip if already translated
                if ($this->isAlreadyTranslated($line, $offset)) {
                    $skipped[] = ['text' => $text, 'reason' => 'already_translated', 'line' => $lineNumber + 1];

                    continue;
                }

                // Detect element type based on context
                $elementType = $this->detectElementType($line, $offset, $fileContext);

                // Skip if in non-user-facing context
                if ($elementType === 'non_translatable') {
                    $skipped[] = ['text' => $text, 'reason' => 'non_translatable_context', 'line' => $lineNumber + 1];

                    continue;
                }

                $candidates[] = new ScanCandidate(
                    file: $filePath,
                    line: $lineNumber + 1,
                    offset: $offset,
                    text: $text,
                    context: trim($line),
                    elementType: $elementType,
                    fileType: $fileContext['type'] !== 'unknown' ? $fileContext['type'] : 'php',
                    metadata: [
                        'file_context' => $fileContext,
                        'quote_type' => $literal[0],
                        'in_method' => $this->detectMethodContext($line, $offset),
                    ]
                );
            }
        }
    }

    /**
     * Check if text should be skipped.
     */
    private function shouldSkip(string $text): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Check if it looks like a file path
        if (preg_match('/^[\w\/\.\-]+\.(php|js|css|json|html|blade\.php)$/', $text)) {
            return true;
        }

        // Check if it's likely a database column/table name
        if (preg_match('/^[a-z_]+$/', $text) && strlen($text) < 30) {
            return true;
        }

        // Check if it's a Laravel validation rule
        if ($this->isValidationRule($text)) {
            return true;
        }

        return false;
    }

    /**
     * Check if text looks like a Laravel validation rule.
     */
    private function isValidationRule(string $text): bool
    {
        // Contains pipe separator (common in validation rules)
        if (str_contains($text, '|')) {
            // Check if any segment is a validation keyword
            $segments = explode('|', $text);
            foreach ($segments as $segment) {
                // Extract keyword before colon (e.g., "max:500" -> "max")
                $keyword = explode(':', $segment)[0];
                if (in_array(strtolower($keyword), self::VALIDATION_KEYWORDS)) {
                    return true;
                }
            }
        }

        // Starts with validation keyword followed by : or end of string
        $firstPart = explode(':', $text)[0];
        $firstKeyword = explode('|', $firstPart)[0];
        if (in_array(strtolower($firstKeyword), self::VALIDATION_KEYWORDS)) {
            // Make sure it's not a sentence that happens to start with a keyword
            // Validation rules don't have spaces in the keyword part
            if (!str_contains($firstPart, ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the line contains non-translatable context.
     */
    private function isNonTranslatableContext(string $line): bool
    {
        foreach (self::NON_TRANSLATABLE_CONTEXTS as $pattern) {
            if (str_contains($line, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string is already wrapped in a translation function.
     */
    private function isAlreadyTranslated(string $line, int $offset): bool
    {
        $before = substr($line, max(0, $offset - 30), min($offset, 30));

        foreach (self::TRANSLATION_PATTERNS as $pattern) {
            if (str_contains($before, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the element type based on method context.
     */
    private function detectElementType(string $line, int $offset, array $fileContext): string
    {
        $before = substr($line, max(0, $offset - 50), min($offset, 50));

        // Check for user-facing method calls
        foreach (self::USER_FACING_METHODS as $method) {
            if (preg_match('/->'.preg_quote($method, '/').'\s*\(\s*$/', $before) ||
                preg_match('/'.preg_quote($method, '/').'\s*\(\s*$/', $before)) {
                return 'method_'.$method;
            }
        }

        // Check for validation messages
        if (preg_match('/[\'"]message[\'"]\s*=>\s*$/', $before)) {
            return 'validation_message';
        }

        // Check for array values that might be user-facing
        if (preg_match('/[\'"](?:label|title|description|text|content|message|error|success|warning)[\'"]\s*=>\s*$/', $before)) {
            return 'array_label';
        }

        // Check for exception messages
        if (preg_match('/throw\s+new\s+\w+Exception\s*\(\s*$/', $before) ||
            preg_match('/abort\s*\(\s*\d+\s*,\s*$/', $before)) {
            return 'exception_message';
        }

        // Check for return statements with messages
        if (preg_match('/return\s+[\'"]/', $before) && $fileContext['type'] === 'livewire') {
            return 'livewire_return';
        }

        // Generic PHP string in user-facing context
        if (in_array($fileContext['type'], ['livewire', 'controller', 'notification', 'mail'])) {
            return 'user_facing_string';
        }

        return 'non_translatable';
    }

    /**
     * Detect the method context for a string.
     */
    private function detectMethodContext(string $line, int $offset): ?string
    {
        $before = substr($line, max(0, $offset - 50), min($offset, 50));

        if (preg_match('/->(\w+)\s*\(\s*$/', $before, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Unescape string based on quote type.
     */
    private function unescapeString(string $text, string $quoteType): string
    {
        if ($quoteType === '"') {
            $text = stripcslashes($text);
        } else {
            $text = str_replace(["\\\\", "\\'"], ['\\', "'"], $text);
        }

        return $text;
    }
}
