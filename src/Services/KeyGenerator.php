<?php

namespace Headwires\TranslatorClient\Services;

use Headwires\TranslatorClient\DTOs\ScanCandidate;
use Illuminate\Support\Str;

class KeyGenerator
{
    /**
     * Common action verbs that should be grouped under 'actions'.
     */
    private const COMMON_ACTIONS = [
        'save',
        'cancel',
        'delete',
        'edit',
        'create',
        'update',
        'submit',
        'reset',
        'clear',
        'close',
        'open',
        'search',
        'filter',
        'sort',
        'export',
        'import',
        'download',
        'upload',
        'confirm',
        'apply',
        'add',
        'remove',
        'copy',
        'paste',
        'undo',
        'redo',
        'send',
        'share',
        'print',
        'refresh',
        'reload',
        'back',
        'next',
        'previous',
        'continue',
        'skip',
        'finish',
        'done',
        'start',
        'stop',
        'pause',
        'resume',
        'retry',
        'accept',
        'reject',
        'approve',
        'deny',
        'login',
        'logout',
        'register',
        'signup',
        'signin',
        'signout',
        'select',
        'deselect',
        'enable',
        'disable',
        'show',
        'hide',
        'expand',
        'collapse',
        'view',
        'preview',
        'publish',
        'unpublish',
        'archive',
        'restore',
    ];

    /**
     * Common form labels.
     */
    private const COMMON_LABELS = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'address',
        'city',
        'country',
        'zip',
        'zipcode',
        'postal',
        'state',
        'region',
        'province',
        'description',
        'title',
        'status',
        'date',
        'time',
        'datetime',
        'message',
        'comment',
        'note',
        'notes',
        'website',
        'url',
        'company',
        'organization',
        'first_name',
        'last_name',
        'full_name',
        'birthday',
        'gender',
        'age',
        'price',
        'amount',
        'quantity',
        'total',
        'subtotal',
        'tax',
        'discount',
        'currency',
        'language',
        'timezone',
        'role',
        'type',
        'category',
        'tags',
        'keywords',
        'subject',
        'content',
        'body',
        'summary',
        'excerpt',
        'image',
        'photo',
        'avatar',
        'file',
        'attachment',
        'color',
        'size',
    ];

    /**
     * Message type keywords for classification.
     */
    private const MESSAGE_KEYWORDS = [
        'success' => ['success', 'successfully', 'created', 'updated', 'deleted', 'saved', 'completed', 'done'],
        'error' => ['error', 'failed', 'invalid', 'incorrect', 'wrong', 'unable', 'cannot', 'not found'],
        'warning' => ['warning', 'caution', 'attention', 'notice', 'alert'],
        'info' => ['info', 'information', 'note', 'tip', 'hint'],
        'confirm' => ['confirm', 'sure', 'certain', 'proceed', 'continue'],
    ];

    /**
     * Generate a semantic translation key for a candidate.
     */
    public function generate(ScanCandidate $candidate): string
    {
        // Get the section from file path
        $section = $this->extractSection($candidate->file, $candidate->fileType);

        // Get the type based on element type
        $type = $this->mapElementType($candidate->elementType);

        // Generate description from text
        $description = $this->generateDescription($candidate->text, $candidate->elementType);

        // Build the key
        $key = $this->buildKey($section, $type, $description);

        return $this->sanitizeKey($key);
    }

    /**
     * Extract a meaningful section name from the file path.
     */
    private function extractSection(string $filePath, string $fileType): string
    {
        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);

        // Common Laravel path patterns
        $patterns = [
            '/resources\/views\/(.+?)\.blade\.php$/' => '$1',
            '/resources\/views\/livewire\/(.+?)\.blade\.php$/' => 'livewire.$1',
            '/app\/Livewire\/(.+?)\.php$/' => 'livewire.$1',
            '/app\/Http\/Controllers\/(.+?)Controller\.php$/' => '$1',
            '/app\/(.+?)\.php$/' => '$1',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $filePath, $matches)) {
                $section = preg_replace($pattern, $replacement, $filePath);
                // Clean up the section
                $section = str_replace('/', '.', $section);
                $section = str_replace('\\', '.', $section);
                $section = Str::kebab($section);
                $section = str_replace('.', '-', $section);

                return $this->shortenSection($section);
            }
        }

        // Fallback: use filename without extension
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        $filename = str_replace('.blade', '', $filename);

        return Str::kebab($filename);
    }

    /**
     * Shorten long section names.
     */
    private function shortenSection(string $section): string
    {
        // Remove common prefixes
        $section = preg_replace('/^(app-|http-|controllers-|livewire-)/', '', $section);

        // Limit depth to 2 levels
        $parts = explode('-', $section);
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }

        return implode('-', $parts);
    }

    /**
     * Map element type to a category.
     */
    private function mapElementType(string $elementType): string
    {
        // Heading types
        if (str_starts_with($elementType, 'heading_')) {
            return 'headings';
        }

        // Standard mappings
        $mappings = [
            'button' => 'buttons',
            'label' => 'labels',
            'placeholder' => 'placeholders',
            'placeholder_attr' => 'placeholders',
            'title_attr' => 'tooltips',
            'alt_attr' => 'images',
            'aria-label_attr' => 'accessibility',
            'html_text' => 'content',
            'paragraph' => 'content',
            'span' => 'content',
            'link' => 'links',
            'list_item' => 'lists',
            'table_cell' => 'tables',
            'option' => 'options',
            'string_literal' => 'misc',
            'validation_message' => 'validation',
            'exception_message' => 'errors',
            'method_flash' => 'messages',
            'method_with' => 'messages',
            'method_withSuccess' => 'messages.success',
            'method_withError' => 'messages.error',
            'method_withWarning' => 'messages.warning',
            'method_withInfo' => 'messages.info',
            'method_dispatch' => 'events',
            'method_notify' => 'notifications',
            'array_label' => 'labels',
            'livewire_return' => 'messages',
            'user_facing_string' => 'misc',
        ];

        return $mappings[$elementType] ?? 'misc';
    }

    /**
     * Generate a description from the text.
     */
    private function generateDescription(string $text, string $elementType): string
    {
        $lowerText = strtolower($text);

        // Check if it's a common action
        foreach (self::COMMON_ACTIONS as $action) {
            if ($lowerText === $action || str_starts_with($lowerText, $action.' ')) {
                return $action;
            }
        }

        // Check if it's a common label
        foreach (self::COMMON_LABELS as $label) {
            $labelWithSpaces = str_replace('_', ' ', $label);
            if ($lowerText === $label || $lowerText === $labelWithSpaces) {
                return $label;
            }
        }

        // Detect message type for flash/notification messages
        if (str_contains($elementType, 'message') || str_contains($elementType, 'method_')) {
            $messageType = $this->detectMessageType($text);
            if ($messageType) {
                return $messageType.'-'.Str::slug(Str::limit($text, 30, ''), '-');
            }
        }

        // For buttons, try to extract action verb
        if ($elementType === 'button' || str_contains($elementType, 'button')) {
            foreach (self::COMMON_ACTIONS as $action) {
                if (str_contains($lowerText, $action)) {
                    // If text is just the action or action + object
                    $words = explode(' ', $lowerText);
                    if (count($words) <= 3) {
                        return Str::slug($text, '-');
                    }

                    return $action;
                }
            }
        }

        // Generate slug from text
        $slug = Str::slug($text, '-');

        // Limit length
        if (strlen($slug) > 50) {
            // Try to break at word boundary
            $slug = Str::limit($slug, 50, '');
            $lastHyphen = strrpos($slug, '-');
            if ($lastHyphen !== false && $lastHyphen > 20) {
                $slug = substr($slug, 0, $lastHyphen);
            }
        }

        return $slug;
    }

    /**
     * Detect the type of message (success, error, etc).
     */
    private function detectMessageType(string $text): ?string
    {
        $lowerText = strtolower($text);

        foreach (self::MESSAGE_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerText, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Build the final key from parts.
     */
    private function buildKey(string $section, string $type, string $description): string
    {
        // Handle nested types (e.g., 'messages.success')
        if (str_contains($type, '.')) {
            return "{$section}.{$type}.{$description}";
        }

        return "{$section}.{$type}.{$description}";
    }

    /**
     * Sanitize a key to ensure it's valid.
     */
    private function sanitizeKey(string $key): string
    {
        // Replace multiple dots with single dot
        $key = preg_replace('/\.+/', '.', $key);

        // Replace multiple hyphens with single hyphen
        $key = preg_replace('/-+/', '-', $key);

        // Remove leading/trailing dots and hyphens from each part
        $parts = explode('.', $key);
        $parts = array_map(fn ($part) => trim($part, '-'), $parts);
        $parts = array_filter($parts, fn ($part) => ! empty($part));

        return implode('.', $parts);
    }

    /**
     * Detect if text contains parameters that need placeholder handling.
     */
    public function detectParameters(string $text): array
    {
        $params = [];

        // Detect existing Laravel placeholders
        if (preg_match_all('/:[a-z_]+/', $text, $matches)) {
            $params = array_merge($params, $matches[0]);
        }

        // Detect name patterns (e.g., "Hello John" -> ":name")
        if (preg_match('/\b(hello|hi|welcome|dear|hey)\s+([A-Z][a-z]+)\b/i', $text)) {
            $params[] = ':name';
        }

        // Detect number patterns
        if (preg_match('/\b\d+\b/', $text)) {
            $params[] = ':count';
        }

        // Detect email patterns
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $text)) {
            $params[] = ':email';
        }

        // Detect date patterns
        if (preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text)) {
            $params[] = ':date';
        }

        // Detect currency amounts
        if (preg_match('/[$€£¥]\d+/', $text) || preg_match('/\d+\s*(USD|EUR|GBP|JPY)/i', $text)) {
            $params[] = ':amount';
        }

        return array_unique($params);
    }

    /**
     * Apply parameter placeholders to text.
     */
    public function applyParameters(string $text, array $params): string
    {
        foreach ($params as $param) {
            switch ($param) {
                case ':name':
                    $text = preg_replace(
                        '/\b(hello|hi|welcome|dear|hey)\s+([A-Z][a-z]+)\b/i',
                        '$1 :name',
                        $text
                    );
                    break;

                case ':count':
                    // Only replace standalone numbers, not those in dates or IDs
                    $text = preg_replace('/(?<![\/\-])\b(\d+)\b(?![\/\-])/', ':count', $text);
                    break;

                case ':email':
                    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', ':email', $text);
                    break;

                case ':date':
                    $text = preg_replace('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', ':date', $text);
                    break;

                case ':amount':
                    $text = preg_replace('/[$€£¥]\d+(\.\d{2})?/', ':amount', $text);
                    $text = preg_replace('/\d+(\.\d{2})?\s*(USD|EUR|GBP|JPY)/i', ':amount', $text);
                    break;
            }
        }

        return $text;
    }

    /**
     * Calculate a confidence score for a generated key.
     */
    public function calculateConfidence(ScanCandidate $candidate, string $key): int
    {
        $score = 50; // Base score

        // Boost for well-known element types
        if (in_array($candidate->elementType, ['heading_h1', 'heading_h2', 'button', 'label', 'placeholder_attr'])) {
            $score += 20;
        }

        // Boost for appropriate text length
        $length = strlen($candidate->text);
        if ($length >= 5 && $length <= 80) {
            $score += 15;
        }

        // Penalty for very short text
        if ($length < 5) {
            $score -= 15;
        }

        // Penalty for very long text
        if ($length > 200) {
            $score -= 20;
        }

        // Boost for not being in attribute (cleaner context)
        if (! ($candidate->metadata['in_attribute'] ?? false)) {
            $score += 10;
        }

        // Boost for user-facing file types
        if (in_array($candidate->fileType, ['blade', 'volt', 'livewire'])) {
            $score += 10;
        }

        // Penalty for strings that look like code
        if (preg_match('/[{}()\[\];=<>]/', $candidate->text)) {
            $score -= 20;
        }

        // Boost for common action words in buttons
        if ($candidate->elementType === 'button') {
            $lowerText = strtolower($candidate->text);
            foreach (self::COMMON_ACTIONS as $action) {
                if (str_contains($lowerText, $action)) {
                    $score += 15;
                    break;
                }
            }
        }

        return max(0, min(100, $score));
    }
}
