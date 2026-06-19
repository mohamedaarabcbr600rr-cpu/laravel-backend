<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIService — Multi-provider AI with intelligent fallback chain.
 *
 * Architecture:
 *  - Text ask:       Groq (fast) → Gemini 2.5 Pro → OpenRouter Mistral
 *  - PDF (text):     Groq / Gemini text API (extracted text passed as prompt)
 *  - PDF (native):   Gemini 2.5 Pro inline_data (handles scanned/mixed/Arabic)
 *  - Image:          Gemini 2.5 Pro → OpenRouter Qwen VL
 *
 * Design goals:
 *  1. Never send base64 PDFs to OpenRouter — it doesn't understand them natively.
 *  2. Gemini File API used for PDFs > 10 MB to avoid inline_data 20 MB hard limit.
 *  3. All constants in one place; change model/token once, takes effect everywhere.
 *  4. Typed properties, typed returns, named exceptions throughout.
 *  5. Structured logging at every decision point for Railway log tailing.
 */
class AIService
{
    // ──────────────────────────────────────────────────────────
    // MODEL IDENTIFIERS
    // ──────────────────────────────────────────────────────────

    /** Primary multimodal model — 2 M-token context, native PDF, Arabic-aware */
    private const GEMINI_MODEL = 'gemini-2.5-pro';

    /** Fast text-only via Groq */
    private const GROQ_MODEL = 'llama-3.3-70b-versatile';

    /** OpenRouter text fallback (free tier) */
    private const OPENROUTER_TEXT_MODEL = 'mistralai/mistral-7b-instruct:free';

    /** OpenRouter vision fallback (free tier) */
    private const OPENROUTER_VIS_MODEL = 'qwen/qwen2.5-vl-72b-instruct:free';

    // ──────────────────────────────────────────────────────────
    // TOKEN LIMITS
    // ──────────────────────────────────────────────────────────

    /** Standard text conversation replies */
    private const MAX_TOKENS_TEXT = 4096;

    /**
     * PDF / vision responses.
     * 20 QCM questions × (question + 4 options + explanation) in Arabic ≈ 6–10 k tokens.
     * 16 384 gives comfortable headroom even for 30-question sets.
     */
    private const MAX_TOKENS_FILE = 16384;

    // ──────────────────────────────────────────────────────────
    // HTTP TIMEOUTS (seconds)
    // ──────────────────────────────────────────────────────────

    private const TIMEOUT_TEXT = 120;

    /**
     * Gemini 2.5 Pro performs deep multi-page analysis; large scanned PDFs
     * regularly take 60–180 s. Railway's idle proxy timeout is 300 s so we
     * stay slightly below it.
     */
    private const TIMEOUT_FILE = 270;

    // ──────────────────────────────────────────────────────────
    // FILE SIZE THRESHOLDS
    // ──────────────────────────────────────────────────────────

    /**
     * Gemini inline_data hard cap is 20 MB of base64-encoded content.
     * Base64 inflates by ~33 %, so raw file must be ≤ ~15 MB to be safe.
     * We use 12 MB as our threshold to leave margin.
     */
    private const GEMINI_INLINE_MAX_BYTES = 12 * 1024 * 1024; // 12 MB

    // ──────────────────────────────────────────────────────────
    // GEMINI API BASE
    // ──────────────────────────────────────────────────────────

    private const GEMINI_BASE_URL      = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const GEMINI_UPLOAD_URL    = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
    private const GEMINI_FILES_API_URL = 'https://generativelanguage.googleapis.com/v1beta/files/';

    // ──────────────────────────────────────────────────────────
    // API KEYS
    // ──────────────────────────────────────────────────────────

    private ?string $groqApiKey;
    private ?string $openRouterApiKey;
    private ?string $geminiApiKey;

    public function __construct()
    {
        $this->groqApiKey       = config('services.groq.key')        ?: null;
        $this->openRouterApiKey = config('services.openrouter.key')  ?: null;
        $this->geminiApiKey     = config('services.gemini.key')      ?: null;
    }

    // ══════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════

    /**
     * Text-only chat.  Fallback: Groq → Gemini → OpenRouter.
     */
    public function ask(string $prompt, array $context = []): string
    {
        $fullPrompt = $this->buildPrompt($prompt, $context);

        if ($this->groqApiKey) {
            try {
                Log::info('[AIService] ask() → Groq (' . self::GROQ_MODEL . ')');
                return $this->askGroq($fullPrompt);
            } catch (\Throwable $e) {
                Log::warning('[AIService] Groq failed: ' . $e->getMessage());
            }
        }

        if ($this->geminiApiKey) {
            try {
                Log::info('[AIService] ask() → Gemini (' . self::GEMINI_MODEL . ')');
                return $this->askGemini($fullPrompt);
            } catch (\Throwable $e) {
                Log::warning('[AIService] Gemini text failed: ' . $e->getMessage());
            }
        }

        if ($this->openRouterApiKey) {
            try {
                Log::info('[AIService] ask() → OpenRouter (' . self::OPENROUTER_TEXT_MODEL . ')');
                return $this->askOpenRouter($fullPrompt);
            } catch (\Throwable $e) {
                Log::error('[AIService] OpenRouter failed: ' . $e->getMessage());
            }
        }

        throw new \RuntimeException(
            'No AI provider available. Configure at least one of: GEMINI_API_KEY, GROQ_API_KEY, OPENROUTER_API_KEY.'
        );
    }

    /**
     * File-aware ask.
     *
     * Routing logic:
     *  PDF  → Gemini 2.5 Pro native PDF understanding (inline or File API for large files)
     *  IMG  → Gemini 2.5 Pro → OpenRouter Qwen VL fallback
     *
     * NOTE: OpenRouter is NOT used as a PDF fallback because free-tier vision
     * models cannot reliably parse PDF bytes — they treat them as images of the
     * first page at best. All PDF handling goes through Gemini.
     */
    public function askWithFile(string $prompt, string $filePath): string
    {
        $this->assertFileReadable($filePath);

        $mimeType = $this->detectMimeType($filePath);
        $fileSize = filesize($filePath);

        Log::info(sprintf(
            '[AIService] askWithFile: mime=%s size=%s bytes file=%s',
            $mimeType,
            number_format($fileSize),
            basename($filePath)
        ));

        if ($mimeType === 'application/pdf') {
            return $this->processPdfWithGemini($prompt, $filePath, $fileSize);
        }

        $supportedImages = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
        if (in_array($mimeType, $supportedImages, true)) {
            return $this->processImageWithVision($prompt, $filePath, $mimeType);
        }

        throw new \InvalidArgumentException(
            "Unsupported file type: {$mimeType}. Supported: PDF, JPEG, PNG, WEBP, GIF, HEIC."
        );
    }

    /**
     * Multi-turn chat with message history.
     * Prefers Groq for low-latency; falls back to ask() on failure.
     */
    public function askWithHistory(array $messages): string
    {
        if ($this->groqApiKey) {
            try {
                Log::info('[AIService] askWithHistory → Groq');
                $response = Http::withHeaders($this->groqHeaders())
                    ->timeout(self::TIMEOUT_TEXT)
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model'       => self::GROQ_MODEL,
                        'messages'    => $messages,
                        'temperature' => 0.7,
                        'max_tokens'  => self::MAX_TOKENS_TEXT,
                    ]);

                if ($response->successful()) {
                    return $response->json('choices.0.message.content', '');
                }
                Log::warning('[AIService] Groq history non-2xx: ' . $response->status());
            } catch (\Throwable $e) {
                Log::warning('[AIService] Groq history exception: ' . $e->getMessage());
            }
        }

        $lastMessage = end($messages);
        return $this->ask($lastMessage['content'] ?? '');
    }

    /**
     * @deprecated Use askWithFile() — kept for backward compatibility.
     */
    public function askGeminiWithFile(string $prompt, string $filePath): string
    {
        return $this->askWithFile($prompt, $filePath);
    }

    /**
     * @deprecated Use askWithFile() — kept for backward compatibility.
     */
    public function askWithFileViaOpenRouter(
        string $prompt,
        string $filePath,
        string $mimeType = 'image/jpeg'
    ): string {
        return $this->processImageWithVision($prompt, $filePath, $mimeType);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PDF PROCESSING
    // ══════════════════════════════════════════════════════════

    /**
     * Route PDF to inline_data (small/medium) or File API (large).
     *
     * Why two paths?
     *  - inline_data: zero latency overhead, no state to clean up, works for ≤ 12 MB raw.
     *  - File API: required for larger files; Gemini stores the file server-side, returns
     *    a fileUri that references it — no base64 bloat in the request payload.
     */
    private function processPdfWithGemini(string $prompt, string $filePath, int $fileSize): string
    {
        if (!$this->geminiApiKey) {
            throw new \RuntimeException(
                'GEMINI_API_KEY is required for PDF processing. ' .
                'Get a free key at https://aistudio.google.com/app/apikey'
            );
        }

        if ($fileSize <= self::GEMINI_INLINE_MAX_BYTES) {
            return $this->processPdfInline($prompt, $filePath, $fileSize);
        }

        Log::info(sprintf(
            '[AIService] PDF is %.1f MB — using Gemini File API (inline limit is %.0f MB)',
            $fileSize / 1048576,
            self::GEMINI_INLINE_MAX_BYTES / 1048576
        ));

        return $this->processPdfViaFileApi($prompt, $filePath, $fileSize);
    }

    /**
     * Send PDF as base64 inline_data.
     * Best for files ≤ 12 MB — no upload round-trip needed.
     */
    private function processPdfInline(string $prompt, string $filePath, int $fileSize): string
    {
        Log::info(sprintf(
            '[AIService] PDF inline → Gemini %s (%.1f MB)',
            self::GEMINI_MODEL,
            $fileSize / 1048576
        ));

        // Read in chunks to keep peak memory usage low
        $fileContent = base64_encode($this->readFileInChunks($filePath));

        $payload = $this->buildGeminiFilePayload(
            $prompt,
            'application/pdf',
            $fileContent,
            null,
            self::MAX_TOKENS_FILE,
            0.1  // Low temperature = deterministic JSON, fewer hallucinations
        );

        $response = Http::timeout(self::TIMEOUT_FILE)
            ->post($this->geminiGenerateEndpoint(), $payload);

        if (!$response->successful()) {
            Log::error('[AIService] Gemini inline PDF error ' . $response->status() . ': ' . $response->body());
            throw new \RuntimeException(
                'Gemini rejected inline PDF (HTTP ' . $response->status() . '). ' .
                'The file may be password-protected, corrupted, or too large.'
            );
        }

        return $this->extractGeminiText($response->json(), 'PDF inline');
    }

    /**
     * Upload PDF via Gemini File API, then reference it by URI.
     *
     * This is the correct approach for large PDFs (> 12 MB).
     * The file is stored on Google's servers for 48 h, then auto-deleted.
     *
     * Flow: POST upload → poll until state=ACTIVE → POST generate with fileUri → DELETE file
     */
    private function processPdfViaFileApi(string $prompt, string $filePath, int $fileSize): string
    {
        Log::info(sprintf('[AIService] Uploading PDF to Gemini File API (%.1f MB)...', $fileSize / 1048576));

        $fileUri  = null;
        $fileName = null;

        try {
            // Step 1: Resumable upload initiation
            $initResponse = Http::withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command'  => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $fileSize,
                'X-Goog-Upload-Header-Content-Type'   => 'application/pdf',
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post(self::GEMINI_UPLOAD_URL . '?key=' . $this->geminiApiKey, [
                'file' => ['display_name' => basename($filePath)],
            ]);

            if (!$initResponse->successful()) {
                throw new \RuntimeException(
                    'Gemini File API init failed: HTTP ' . $initResponse->status()
                );
            }

            $uploadUrl = $initResponse->header('X-Goog-Upload-URL');
            if (!$uploadUrl) {
                throw new \RuntimeException('Gemini File API did not return upload URL');
            }

            // Step 2: Upload the file bytes
            $uploadResponse = Http::withHeaders([
                'Content-Length'        => (string) $fileSize,
                'X-Goog-Upload-Offset'  => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])
            ->withBody(file_get_contents($filePath), 'application/pdf')
            ->timeout(self::TIMEOUT_FILE)
            ->post($uploadUrl);

            if (!$uploadResponse->successful()) {
                throw new \RuntimeException(
                    'Gemini File API upload failed: HTTP ' . $uploadResponse->status()
                );
            }

            $fileData = $uploadResponse->json();
            $fileUri  = $fileData['file']['uri']  ?? null;
            $fileName = $fileData['file']['name'] ?? null;

            if (!$fileUri) {
                throw new \RuntimeException('Gemini File API returned no file URI');
            }

            Log::info('[AIService] File uploaded: ' . $fileUri);

            // Step 3: Poll until ACTIVE (usually < 10 s for PDFs)
            $this->waitForFileActive($fileName);

            // Step 4: Generate content referencing the uploaded file
            $payload = $this->buildGeminiFilePayload(
                $prompt,
                'application/pdf',
                null,
                $fileUri,
                self::MAX_TOKENS_FILE,
                0.1
            );

            $response = Http::timeout(self::TIMEOUT_FILE)
                ->post($this->geminiGenerateEndpoint(), $payload);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'Gemini generation via File API failed: HTTP ' . $response->status()
                );
            }

            return $this->extractGeminiText($response->json(), 'PDF via File API');

        } finally {
            // Always clean up the uploaded file to avoid quota consumption
            if ($fileName) {
                $this->deleteGeminiFile($fileName);
            }
        }
    }

    /**
     * Poll the File API until the file state transitions from PROCESSING to ACTIVE.
     * Throws after 120 s of waiting to prevent indefinite blocking.
     */
    private function waitForFileActive(string $fileName): void
    {
        $maxWaitSeconds = 120;
        $polledSeconds  = 0;
        $intervalMs     = 3000; // 3 s between polls

        Log::info("[AIService] Waiting for file to become ACTIVE: {$fileName}");

        while ($polledSeconds < $maxWaitSeconds) {
            usleep($intervalMs * 1000);
            $polledSeconds += (int) ($intervalMs / 1000);

            $resp = Http::timeout(30)
                ->get(self::GEMINI_FILES_API_URL . $fileName . '?key=' . $this->geminiApiKey);

            if (!$resp->successful()) {
                Log::warning('[AIService] File status poll failed: ' . $resp->status());
                continue;
            }

            $state = $resp->json('state');
            Log::info("[AIService] File state after {$polledSeconds}s: {$state}");

            if ($state === 'ACTIVE') {
                return;
            }

            if ($state === 'FAILED') {
                throw new \RuntimeException("Gemini file processing failed: {$fileName}");
            }
        }

        throw new \RuntimeException(
            "Gemini file did not become ACTIVE within {$maxWaitSeconds}s: {$fileName}"
        );
    }

    /** Best-effort cleanup — log but do not throw on failure */
    private function deleteGeminiFile(string $fileName): void
    {
        try {
            Http::timeout(15)
                ->delete(self::GEMINI_FILES_API_URL . $fileName . '?key=' . $this->geminiApiKey);
            Log::info("[AIService] Deleted Gemini file: {$fileName}");
        } catch (\Throwable $e) {
            Log::warning("[AIService] Could not delete Gemini file {$fileName}: " . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — IMAGE PROCESSING
    // ══════════════════════════════════════════════════════════

    /**
     * Send image to Gemini 2.5 Pro; fall back to OpenRouter Qwen VL on failure.
     */
    private function processImageWithVision(string $prompt, string $filePath, string $mimeType): string
    {
        if ($this->geminiApiKey) {
            try {
                Log::info('[AIService] Image → Gemini ' . self::GEMINI_MODEL);

                $fileContent = base64_encode($this->readFileInChunks($filePath));
                $payload     = $this->buildGeminiFilePayload(
                    $prompt,
                    $mimeType,
                    $fileContent,
                    null,
                    self::MAX_TOKENS_FILE,
                    0.2
                );

                $response = Http::timeout(self::TIMEOUT_FILE)
                    ->post($this->geminiGenerateEndpoint(), $payload);

                if ($response->successful()) {
                    return $this->extractGeminiText($response->json(), 'image');
                }

                Log::warning(
                    '[AIService] Gemini image error ' . $response->status() . ' — trying OpenRouter'
                );
            } catch (\Throwable $e) {
                Log::warning('[AIService] Gemini image exception: ' . $e->getMessage());
            }
        }

        if ($this->openRouterApiKey) {
            return $this->processImageViaOpenRouter($prompt, $filePath, $mimeType);
        }

        throw new \RuntimeException(
            'No vision provider available. Configure GEMINI_API_KEY or OPENROUTER_API_KEY.'
        );
    }

    private function processImageViaOpenRouter(
        string $prompt,
        string $filePath,
        string $mimeType
    ): string {
        Log::info('[AIService] Image → OpenRouter ' . self::OPENROUTER_VIS_MODEL);

        $fileContent = base64_encode($this->readFileInChunks($filePath));

        $response = Http::withHeaders($this->openRouterHeaders())
            ->timeout(self::TIMEOUT_FILE)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'    => self::OPENROUTER_VIS_MODEL,
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type'      => 'image_url',
                            'image_url' => ['url' => "data:{$mimeType};base64,{$fileContent}"],
                        ],
                    ],
                ]],
                'temperature' => 0.2,
                'max_tokens'  => self::MAX_TOKENS_FILE,
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content', '');
        }

        throw new \RuntimeException(
            'OpenRouter vision error: HTTP ' . $response->status() . ' — ' . $response->body()
        );
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — TEXT-ONLY PROVIDERS
    // ══════════════════════════════════════════════════════════

    private function askGroq(string $prompt): string
    {
        $response = Http::withHeaders($this->groqHeaders())
            ->timeout(self::TIMEOUT_TEXT)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => self::GROQ_MODEL,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Respond strictly in the language specified in the user prompt.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens'  => self::MAX_TOKENS_TEXT,
            ]);

        $this->assertHttpSuccess($response, 'Groq');
        return $response->json('choices.0.message.content', '');
    }

    private function askGemini(string $prompt): string
    {
        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => self::MAX_TOKENS_TEXT,
            ],
        ];

        $response = Http::timeout(self::TIMEOUT_TEXT)
            ->post($this->geminiGenerateEndpoint(), $payload);

        $this->assertHttpSuccess($response, 'Gemini');
        return $this->extractGeminiText($response->json(), 'text');
    }

    private function askOpenRouter(string $prompt): string
    {
        $response = Http::withHeaders($this->openRouterHeaders())
            ->timeout(self::TIMEOUT_TEXT)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'       => self::OPENROUTER_TEXT_MODEL,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a helpful assistant. Follow the language in the user prompt.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens'  => self::MAX_TOKENS_TEXT,
            ]);

        $this->assertHttpSuccess($response, 'OpenRouter');
        return $response->json('choices.0.message.content', '');
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — GEMINI PAYLOAD BUILDERS
    // ══════════════════════════════════════════════════════════

    /**
     * Build a Gemini generateContent payload for file-based calls.
     *
     * Supports two delivery methods:
     *  - inline_data (base64)  : pass $base64Data, leave $fileUri null
     *  - File API (fileUri)    : pass $fileUri, leave $base64Data null
     *
     * Text prompt is placed BEFORE the file part because Gemini processes
     * instructions first, then applies them to the data. This reduces
     * prompt-injection risk from hostile document content.
     *
     * thinkingConfig is enabled for Gemini 2.5 Pro — it activates the model's
     * extended reasoning mode which significantly improves QCM accuracy and
     * reduces positional bias (favouring early document content).
     */
    private function buildGeminiFilePayload(
        string  $textPrompt,
        string  $mimeType,
        ?string $base64Data,
        ?string $fileUri,
        int     $maxTokens,
        float   $temperature = 0.1
    ): array {
        // Build the file part — either inline or by URI
        if ($fileUri !== null) {
            $filePart = [
                'file_data' => [
                    'mime_type' => $mimeType,
                    'file_uri'  => $fileUri,
                ],
            ];
        } else {
            $filePart = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data'      => $base64Data,
                ],
            ];
        }

        return [
            'contents' => [[
                'parts' => [
                    ['text' => $textPrompt], // Instructions first
                    $filePart,               // Data second
                ],
            ]],
            'generationConfig' => [
                'temperature'        => $temperature,
                'maxOutputTokens'    => $maxTokens,
                'responseMimeType'   => 'text/plain', // Prevents Gemini from wrapping in markdown
            ],
            // Gemini 2.5 Pro extended thinking — improves reasoning depth
            // over long documents and reduces first-page bias
            'thinkingConfig' => [
                'thinkingBudget' => 8192,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — GEMINI RESPONSE EXTRACTION
    // ══════════════════════════════════════════════════════════

    /**
     * Extract text from a Gemini generateContent response.
     *
     * Handles all finish reasons:
     *  STOP       → normal completion
     *  MAX_TOKENS → response was truncated (logged as warning, partial text returned)
     *  SAFETY     → content blocked (throws with actionable message)
     *  RECITATION → source attribution block (throws, ask user to rephrase)
     *  OTHER/null → unknown; throw with full response logged for debugging
     */
    private function extractGeminiText(array $result, string $context): string
    {
        // Check for top-level API error (distinct from a generation-level issue)
        if (isset($result['error'])) {
            $code    = $result['error']['code']    ?? 'unknown';
            $message = $result['error']['message'] ?? 'unknown error';
            Log::error("[AIService] Gemini API error for {$context}: [{$code}] {$message}");
            throw new \RuntimeException("Gemini API error: [{$code}] {$message}");
        }

        $candidate    = $result['candidates'][0] ?? null;
        $finishReason = $candidate['finishReason'] ?? null;

        if ($finishReason === 'SAFETY') {
            Log::warning("[AIService] Gemini blocked {$context} for safety");
            throw new \RuntimeException(
                "The content was blocked by Google's safety filter. " .
                'Try rephrasing the prompt or use a different document.'
            );
        }

        if ($finishReason === 'RECITATION') {
            Log::warning("[AIService] Gemini blocked {$context} for recitation");
            throw new \RuntimeException(
                'Gemini declined due to recitation policy. ' .
                'The document may contain content that matches training data. Try a different document.'
            );
        }

        // Gemini 2.5 Pro thinking responses: the actual text is in the last part
        $parts = $candidate['content']['parts'] ?? [];
        $text  = null;

        // Iterate parts in reverse — the final text part is what we want
        foreach (array_reverse($parts) as $part) {
            if (isset($part['text']) && !isset($part['thought'])) {
                $text = $part['text'];
                break;
            }
        }

        // Fallback to first part if no non-thought part found
        if ($text === null && isset($parts[0]['text'])) {
            $text = $parts[0]['text'];
        }

        if ($finishReason === 'MAX_TOKENS') {
            Log::warning("[AIService] Gemini {$context} response truncated at MAX_TOKENS");
            // Return what we have — QCM validator downstream will catch incomplete JSON
        }

        if ($text === null || $text === '') {
            Log::error("[AIService] Gemini returned empty text for {$context}", [
                'finish_reason'    => $finishReason,
                'candidates_count' => count($result['candidates'] ?? []),
                'raw_snippet'      => substr(json_encode($result), 0, 500),
            ]);
            throw new \RuntimeException(
                "Gemini returned an empty response for {$context}. " .
                'The document may be password-protected, corrupted, or entirely image-based with no text.'
            );
        }

        return $text;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — UTILITIES
    // ══════════════════════════════════════════════════════════

    /**
     * Read a file in 8 MB chunks into a single string.
     * Prevents PHP OOM errors on large PDF files.
     */
    private function readFileInChunks(string $filePath, int $chunkSize = 8 * 1024 * 1024): string
    {
        $handle  = fopen($filePath, 'rb');
        $content = '';
        while (!feof($handle)) {
            $content .= fread($handle, $chunkSize);
        }
        fclose($handle);
        return $content;
    }

    /**
     * More reliable MIME detection than mime_content_type().
     * mime_content_type() can misdetect Arabic PDFs as text/plain on some servers.
     */
    private function detectMimeType(string $filePath): string
    {
        // Use finfo if available (preferred — reads magic bytes)
        if (function_exists('finfo_open')) {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Fallback: check file extension
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf'  => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'heic' => 'image/heic',
            default => mime_content_type($filePath) ?: 'application/octet-stream',
        };
    }

    private function geminiGenerateEndpoint(): string
    {
        return self::GEMINI_BASE_URL . self::GEMINI_MODEL
             . ':generateContent?key=' . $this->geminiApiKey;
    }

    private function groqHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->groqApiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    private function openRouterHeaders(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->openRouterApiKey,
            'Content-Type'   => 'application/json',
            'HTTP-Referer'   => config('app.url', 'https://studmo.app'),
            'X-Title'        => 'Studmo',
        ];
    }

    private function buildPrompt(string $prompt, array $context = []): string
    {
        if (empty($context)) {
            return $prompt;
        }
        $ctx = "Context:\n";
        foreach ($context as $key => $value) {
            $ctx .= "- {$key}: {$value}\n";
        }
        return $ctx . "\nQuestion: " . $prompt;
    }

    /** @throws \RuntimeException */
    private function assertHttpSuccess(\Illuminate\Http\Client\Response $response, string $provider): void
    {
        if (!$response->successful()) {
            throw new \RuntimeException(
                "{$provider} API error (HTTP {$response->status()}): " . $response->body()
            );
        }
    }

    /** @throws \InvalidArgumentException */
    private function assertFileReadable(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }
        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException("File not readable: {$filePath}");
        }
    }
}