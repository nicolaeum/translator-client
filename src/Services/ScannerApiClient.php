<?php

namespace Headwires\TranslatorClient\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ScannerApiClient
{
    private string $baseUrl;

    private string $apiKey;

    private int $timeout;

    private int $chunkSize;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('translator-internal.api_url', ''), '/');
        $this->apiKey = $apiKey ?? '';
        $this->timeout = config('translator-internal.scanner.api_timeout', 300);
        $this->chunkSize = config('translator-internal.scanner.chunk_size', 100);

        if (empty($this->apiKey)) {
            throw new \RuntimeException(
                'Translator API key not configured. Provide api_key or set TRANSLATOR_API_KEY in your .env file.'
            );
        }
    }

    /**
     * Get configured HTTP client.
     */
    private function client(): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->timeout);

        // Disable SSL verification for local development domains
        if ($this->isLocalDevelopment()) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Check if we're connecting to a local development server.
     */
    private function isLocalDevelopment(): bool
    {
        $localDomains = ['.test', '.local', '.localhost', 'localhost'];

        foreach ($localDomains as $domain) {
            if (str_contains($this->baseUrl, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send candidates to API for analysis.
     *
     * @param  array  $candidates  Array of candidate data
     * @return array Analysis results
     *
     * @throws RequestException
     */
    public function analyze(string $projectId, array $candidates): array
    {
        // Chunk large requests
        if (count($candidates) > $this->chunkSize) {
            return $this->analyzeInChunks($projectId, $candidates);
        }

        $response = $this->client()
            ->post('/api/scanner/analyze', [
                'project_id' => $projectId,
                'candidates' => $candidates,
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Analyze candidates in chunks for large requests.
     */
    private function analyzeInChunks(string $projectId, array $candidates): array
    {
        $chunks = array_chunk($candidates, $this->chunkSize);
        $allSuggestions = [];
        $stats = [
            'total_analyzed' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
        ];

        foreach ($chunks as $index => $chunk) {
            $response = $this->client()
                ->post('/api/scanner/analyze', [
                    'project_id' => $projectId,
                    'candidates' => $chunk,
                    'chunk_index' => $index,
                    'total_chunks' => count($chunks),
                ]);

            if (! $response->successful()) {
                $this->handleError($response);
            }

            $data = $response->json();

            if (isset($data['suggestions'])) {
                $allSuggestions = array_merge($allSuggestions, $data['suggestions']);
            }

            if (isset($data['stats'])) {
                $stats['total_analyzed'] += $data['stats']['total_analyzed'] ?? 0;
                $stats['high_confidence'] += $data['stats']['high_confidence'] ?? 0;
                $stats['medium_confidence'] += $data['stats']['medium_confidence'] ?? 0;
                $stats['low_confidence'] += $data['stats']['low_confidence'] ?? 0;
            }
        }

        return [
            'suggestions' => $allSuggestions,
            'stats' => $stats,
        ];
    }

    /**
     * Send analyzed candidates to create translations.
     *
     * @param  array  $translations  Array of translation data with keys and values
     * @return array Creation results
     *
     * @throws RequestException
     */
    public function createTranslations(string $projectId, array $translations): array
    {
        // Chunk large requests
        if (count($translations) > $this->chunkSize) {
            return $this->createTranslationsInChunks($projectId, $translations);
        }

        $response = $this->client()
            ->post("/api/projects/{$projectId}/bulk-scanner-translations", [
                'translations' => $translations,
                'metadata' => [
                    'source' => 'blade_scanner',
                    'timestamp' => now()->toIso8601String(),
                    'client_version' => $this->getClientVersion(),
                ],
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Create translations in chunks.
     */
    private function createTranslationsInChunks(string $projectId, array $translations): array
    {
        $chunks = array_chunk($translations, $this->chunkSize);
        $totalCreated = 0;
        $totalSkipped = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            $response = $this->client()
                ->post("/api/projects/{$projectId}/bulk-scanner-translations", [
                    'translations' => $chunk,
                    'metadata' => [
                        'source' => 'blade_scanner',
                        'timestamp' => now()->toIso8601String(),
                        'client_version' => $this->getClientVersion(),
                    ],
                ]);

            if (! $response->successful()) {
                $errors[] = $response->json('message', 'Unknown error');

                continue;
            }

            $data = $response->json();
            $totalCreated += $data['created'] ?? 0;
            $totalSkipped += $data['skipped'] ?? 0;
        }

        return [
            'success' => empty($errors),
            'created' => $totalCreated,
            'skipped' => $totalSkipped,
            'errors' => $errors,
        ];
    }

    /**
     * Analyze and store translations in one operation.
     *
     * @param  array  $candidates  Array of candidate data with pre-generated keys
     * @return array Results with processed count and created/skipped info
     *
     * @throws RequestException
     */
    public function analyzeAndStore(array $candidates): array
    {
        $response = $this->client()
            ->post('/api/scanner/analyze-and-store', [
                'project_id' => $this->apiKey,
                'candidates' => $candidates,
                'metadata' => [
                    'source' => 'blade_scanner',
                    'timestamp' => now()->toIso8601String(),
                    'client_version' => $this->getClientVersion(),
                ],
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Estimate AI quota consumption before analysis.
     *
     * @param  int  $candidateCount  Number of candidates to analyze
     * @return array Quota estimation info
     *
     * @throws RequestException
     */
    public function estimateQuota(int $candidateCount): array
    {
        $response = $this->client()
            ->post('/api/scanner/estimate-quota', [
                'project_id' => $this->apiKey,
                'candidate_count' => $candidateCount,
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Analyze candidates using AI for enhanced key generation.
     *
     * @param  array  $candidates  Array of candidate data
     * @return array AI-enhanced analysis results
     *
     * @throws RequestException
     */
    public function analyzeWithAI(array $candidates): array
    {
        $response = $this->client()
            ->post('/api/scanner/analyze-ai', [
                'project_id' => $this->apiKey,
                'candidates' => $candidates,
                'metadata' => [
                    'source' => 'blade_scanner',
                    'timestamp' => now()->toIso8601String(),
                    'client_version' => $this->getClientVersion(),
                ],
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Get project information.
     *
     * @return array Project data
     *
     * @throws RequestException
     */
    public function getProject(string $projectId): array
    {
        $response = $this->client()
            ->get("/api/projects/{$projectId}");

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json();
    }

    /**
     * Check if a translation key already exists.
     *
     * @return bool True if key exists
     *
     * @throws RequestException
     */
    public function keyExists(string $projectId, string $key): bool
    {
        $response = $this->client()
            ->get("/api/projects/{$projectId}/translations/check", [
                'key' => $key,
            ]);

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json('exists', false);
    }

    /**
     * Get existing translation keys for a project.
     *
     * @return array List of existing keys
     *
     * @throws RequestException
     */
    public function getExistingKeys(string $projectId): array
    {
        $response = $this->client()
            ->get("/api/projects/{$projectId}/translations/keys");

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response->json('keys', []);
    }

    /**
     * Test API connection.
     *
     * @return array Connection status
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client()
                ->get('/api/scanner/ping');

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->json('message', 'Connected'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle API error response.
     *
     * @throws \RuntimeException
     */
    private function handleError($response): void
    {
        $status = $response->status();
        $body = $response->json();

        // For validation errors, show detailed error messages
        if (in_array($status, [400, 422]) && isset($body['errors'])) {
            $errors = collect($body['errors'])->flatten()->implode(', ');
            throw new \RuntimeException("Validation error: {$errors}");
        }

        $message = match ($status) {
            400 => 'Bad request: '.($body['message'] ?? json_encode($body)),
            401 => 'Invalid API key. Check your TRANSLATOR_API_KEY configuration.',
            403 => 'Access denied. Your API key may not have permission for this operation.',
            404 => 'Resource not found. Check that the project ID is correct.',
            422 => 'Validation error: '.($body['message'] ?? 'Invalid data provided.'),
            429 => 'Rate limit exceeded. Please wait before making more requests.',
            500 => 'Server error. Please try again later.',
            default => $body['message'] ?? "API request failed with status {$status}",
        };

        throw new \RuntimeException($message);
    }

    /**
     * Get pending translations ready to apply.
     *
     * @return array Approved translations ready to apply to source files
     *
     * @throws RequestException
     */
    public function getPendingApply(): array
    {
        $response = $this->client()
            ->get('/api/scanner/pending-apply', [
                'project_id' => $this->apiKey,
            ]);

        if (! $response->successful()) {
            throw new \Exception('API Error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Mark translations as applied to source files.
     *
     * @param  array  $reviewIds  Array of review IDs that were applied
     * @return array Status of the mark operation
     *
     * @throws RequestException
     */
    public function markApplied(array $reviewIds): array
    {
        $response = $this->client()
            ->post('/api/scanner/mark-applied', [
                'project_id' => $this->apiKey,
                'review_ids' => $reviewIds,
                'applied_by' => gethostname(),
            ]);

        if (! $response->successful()) {
            throw new \Exception('API Error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Set the API key for this client.
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get the client package version.
     */
    private function getClientVersion(): string
    {
        return '1.0.0';
    }
}
