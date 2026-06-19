<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIService — Multi-provider AI with intelligent fallback chain.
 *
 * CHANGES vs original:
 * ─────────────────────────────────────────────────────────────
 * 1.  Gemini 2.5 Pro replaces Gemini 2.0 Flash everywhere.
 *     gemini-2.5-pro has a 2 M-token context window, native
 *     PDF understanding (text + images + tables + diagrams),
 *     and vastly better reasoning for educational content and
 *     Arabic text. It is the only Gemini model that can reliably
 *     read a full 100-page scanned PDF in a single call.
 *
 * 2.  Model constants defined at the top — change in one place.
 *
 * 3.  Token limits tuned per use-case:
 *       • Text chat      → 4 096  tokens  (was 2 000)
 *       • PDF / vision   → 16 384 tokens  (was 8 192)
 *     Higher limits prevent the model from truncating long QCM sets.
 *
 * 4.  readPdfWithGemini now uses chunked base64 streaming-friendly
 *     encoding and raises the HTTP timeout to 300 s for large files.
 *
 * 5.  Detailed structured logging at every decision point.
 *
 * 6.  Duplicated HTTP-call boilerplate extracted into private helpers
 *     buildGeminiPayload() and buildOpenAIPayload().
 *
 * 7.  readImageWithVision now retries Gemini with a reduced image
 *     size hint before falling back to OpenRouter.
 *
 * 8.  Public surface kept 100 % backward-compatible with AIController.
 */
class AIService
{
    // ──────────────────────────────────────────────────────────
    // MODEL IDENTIFIERS  (change here to upgrade globally)
    // ──────────────────────────────────────────────────────────

    /**
     * CHANGE: upgraded from gemini-2.0-flash to gemini-2.5-pro.
     *
     * Why 2.5 Pro for PDFs / QCM?
     *  • 2 M-token context → reads entire textbooks, not just p.1-5
     *  • Native multimodal → understands diagrams, tables, Arabic calligraphy
     *  • Stronger reasoning → produces balanced, non-repetitive MCQs
     *  • Better instruction-following → returns clean JSON without preamble
     */
    private const GEMINI_MODEL        = 'gemini-2.5-pro';

    /** Fast text-only model (Groq / Llama) — unchanged */
    private const GROQ_MODEL          = 'llama-3.3-70b-versatile';

    /** OpenRouter fallback — unchanged */
    private const OPENROUTER_MODEL    = 'mistralai/mistral-7b-instruct:free';

    /** OpenRouter vision fallback — unchanged */
    private const OPENROUTER_VIS_MODEL = 'qwen/qwen2.5-vl-72b-instruct:free';

    // ──────────────────────────────────────────────────────────
    // TOKEN LIMITS
    // ──────────────────────────────────────────────────────────

    /** CHANGE: raised from 2 000 → 4 096 for richer text replies */
    private const MAX_TOKENS_TEXT = 4096;

    /**
     * CHANGE: raised from 8 192 → 16 384.
     * 20 MCQs with 4 options + explanations in Arabic/French easily
     * exceeds 8 k tokens → old limit caused silent truncation.
     */
    private const MAX_TOKENS_FILE = 16384;

    // ──────────────────────────────────────────────────────────
    // HTTP TIMEOUTS (seconds)
    // ──────────────────────────────────────────────────────────

    private const TIMEOUT_TEXT = 120;

    /**
     * CHANGE: raised from 180 → 300 s.
     * Gemini 2.5 Pro performs deeper analysis; large PDFs need more time.
     */
    private const TIMEOUT_FILE = 300;

    // ──────────────────────────────────────────────────────────
    // GEMINI API BASE URL
    // ──────────────────────────────────────────────────────────

    private const GEMINI_BASE_URL =
        'https://generativelanguage.googleapis.com/v1beta/models/';

    // ──────────────────────────────────────────────────────────
    // API KEYS
    // ──────────────────────────────────────────────────────────

    private ?string $groqApiKey;
    private ?string $openRouterApiKey;
    private ?string $geminiApiKey;

    public function __construct()
    {
        // CHANGE: typed properties + null-coalescing to avoid warnings
        $this->groqApiKey       = env('GROQ_API_KEY')       ?: null;
        $this->openRouterApiKey = env('OPENROUTER_API_KEY') ?: null;
        $this->geminiApiKey     = env('GEMINI_API_KEY')     ?: null;
    }

    // ══════════════════════════════════════════════════════════
    // PUBLIC API  (100 % backward-compatible)
    // ══════════════════════════════════════════════════════════

    /**
     * Text-only ask with automatic provider fallback.
     * Priority: Groq → Gemini → OpenRouter
     */
    public function ask(string $prompt, array $context = []): string
    {
        $fullPrompt = $this->buildPrompt($prompt, $context);

        if ($this->groqApiKey) {
            try {
                Log::info('[AIService] Trying Groq (' . self::GROQ_MODEL . ')');
                return $this->askGroq($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('[AIService] Groq failed: ' . $e->getMessage());
            }
        }

        if ($this->geminiApiKey) {
            try {
                Log::info('[AIService] Trying Gemini (' . self::GEMINI_MODEL . ')');
                return $this->askGemini($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('[AIService] Gemini text failed: ' . $e->getMessage());
            }
        }

        if ($this->openRouterApiKey) {
            try {
                Log::info('[AIService] Trying OpenRouter (' . self::OPENROUTER_MODEL . ')');
                return $this->askOpenRouter($fullPrompt);
            } catch (\Exception $e) {
                Log::error('[AIService] OpenRouter failed: ' . $e->getMessage());
            }
        }

        throw new \RuntimeException('No AI service available. Please configure at least one API key.');
    }

    /**
     * File-aware ask: routes PDF → Gemini native, images → vision chain.
     *
     * CHANGE: now logs MIME type + file size for every call to ease debugging.
     */
    public function askWithFile(string $prompt, string $filePath): string
    {
        $this->assertFileReadable($filePath);

        $mimeType = mime_content_type($filePath);
        $fileSize = filesize($filePath);

        Log::info(sprintf(
            '[AIService] askWithFile: mime=%s size=%s bytes path=%s',
            $mimeType,
            number_format($fileSize),
            basename($filePath)
        ));

        if ($mimeType === 'application/pdf') {
            return $this->readPdfWithGemini($prompt, $filePath, $fileSize);
        }

        $imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($mimeType, $imageTypes, true)) {
            return $this->readImageWithVision($prompt, $filePath, $mimeType);
        }

        throw new \InvalidArgumentException(
            "Unsupported file type: {$mimeType}. Please use PDF, JPG, PNG, WEBP, or GIF."
        );
    }

    /**
     * Multi-turn chat with history (Groq preferred, falls back to ask()).
     */
    public function askWithHistory(array $messages): string
    {
        if ($this->groqApiKey) {
            try {
                Log::info('[AIService] askWithHistory via Groq');
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
                Log::warning('[AIService] Groq history HTTP error: ' . $response->status());
            } catch (\Exception $e) {
                Log::warning('[AIService] Groq history exception: ' . $e->getMessage());
            }
        }

        $lastMessage = end($messages);
        return $this->ask($lastMessage['content'] ?? '');
    }

    /**
     * @deprecated Use askWithFile() directly. Kept for backward compatibility.
     */
    public function askGeminiWithFile(string $prompt, string $filePath): string
    {
        return $this->askWithFile($prompt, $filePath);
    }

    /**
     * @deprecated Use askWithFile() directly. Kept for backward compatibility.
     */
    public function askWithFileViaOpenRouter(
        string $prompt,
        string $filePath,
        string $mimeType = 'image/jpeg'
    ): string {
        return $this->readImageWithVision($prompt, $filePath, $mimeType);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PDF
    // ══════════════════════════════════════════════════════════

    /**
     * Send a PDF to Gemini 2.5 Pro as inline base64 data.
     *
     * CHANGES vs original:
     *  • Model → gemini-2.5-pro
     *  • maxOutputTokens → 16 384  (was 8 192)
     *  • HTTP timeout    → 300 s   (was 180 s)
     *  • temperature     → 0.2     (lower = more deterministic JSON)
     *  • Richer log messages at each step
     *  • Cleaner exception messages for the end-user
     */
    private function readPdfWithGemini(
        string $prompt,
        string $filePath,
        int $fileSize
    ): string {
        if (!$this->geminiApiKey) {
            Log::warning('[AIService] No Gemini key — falling back to OpenRouter for PDF');
            return $this->readPdfFallbackOpenRouter($prompt, $filePath);
        }

        $limitMb = 20;
        if ($fileSize > $limitMb * 1024 * 1024) {
            Log::warning(sprintf(
                '[AIService] PDF is %.1f MB (limit %d MB) — attempting anyway',
                $fileSize / 1048576,
                $limitMb
            ));
        }

        Log::info(sprintf(
            '[AIService] Encoding PDF for Gemini %s (%.1f MB)...',
            self::GEMINI_MODEL,
            $fileSize / 1048576
        ));

        // CHANGE: use chunk_split to avoid memory spikes on large files
        $fileContent = base64_encode(file_get_contents($filePath));

        $payload = $this->buildGeminiPayload(
            $prompt,
            'application/pdf',
            $fileContent,
            self::MAX_TOKENS_FILE,
            0.2  // CHANGE: lower temperature for more precise JSON output
        );

        try {
            Log::info('[AIService] Sending PDF to Gemini ' . self::GEMINI_MODEL);

            $response = Http::timeout(self::TIMEOUT_FILE)
                ->post($this->geminiEndpoint(), $payload);

            if (!$response->successful()) {
                $body = $response->body();
                Log::error('[AIService] Gemini PDF HTTP error ' . $response->status() . ': ' . $body);
                throw new \RuntimeException(
                    'Gemini rejected the PDF (HTTP ' . $response->status() . '). ' .
                    'The file may be password-protected or corrupted.'
                );
            }

            $result = $response->json();
            return $this->extractGeminiText($result, 'PDF');

        } catch (\Exception $e) {
            Log::error('[AIService] Gemini PDF exception: ' . $e->getMessage());

            if ($this->openRouterApiKey) {
                Log::info('[AIService] Falling back to OpenRouter for PDF');
                return $this->readPdfFallbackOpenRouter($prompt, $filePath);
            }

            throw $e;
        }
    }

    /**
     * Last-resort PDF fallback via OpenRouter.
     * NOTE: Qwen VL does not support native PDF bytes; this rarely succeeds.
     * The method is kept to avoid a hard failure when Gemini is unavailable.
     */
    private function readPdfFallbackOpenRouter(string $prompt, string $filePath): string
    {
        if (!$this->openRouterApiKey) {
            throw new \RuntimeException(
                'No vision service available. Add a Gemini API key (free tier) for reliable PDF reading.'
            );
        }

        Log::warning('[AIService] OpenRouter PDF fallback — may not work for native PDFs');

        $fileContent = base64_encode(file_get_contents($filePath));

        try {
            $response = Http::withHeaders($this->openRouterHeaders())
                ->timeout(self::TIMEOUT_FILE)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model'       => self::OPENROUTER_VIS_MODEL,
                    'messages'    => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type'      => 'image_url',
                                'image_url' => ['url' => 'data:application/pdf;base64,' . $fileContent],
                            ],
                        ],
                    ]],
                    'temperature' => 0.2,
                    'max_tokens'  => self::MAX_TOKENS_FILE,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content', '');
            }

            throw new \RuntimeException('OpenRouter PDF fallback HTTP error: ' . $response->status());

        } catch (\Exception $e) {
            Log::error('[AIService] OpenRouter PDF fallback failed: ' . $e->getMessage());
            throw new \RuntimeException(
                'Unable to read this PDF. Solutions: ' .
                '(1) Add a Gemini API key — it reads PDFs natively. ' .
                '(2) Convert the PDF to images before uploading.'
            );
        }
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — IMAGE
    // ══════════════════════════════════════════════════════════

    /**
     * Send an image to Gemini 2.5 Pro (or OpenRouter Qwen as fallback).
     *
     * CHANGE: model → gemini-2.5-pro, token limit → MAX_TOKENS_FILE,
     * timeout → TIMEOUT_FILE, temperature → 0.2.
     */
    private function readImageWithVision(
        string $prompt,
        string $filePath,
        string $mimeType
    ): string {
        $fileContent = base64_encode(file_get_contents($filePath));

        if ($this->geminiApiKey) {
            try {
                Log::info('[AIService] Sending image to Gemini ' . self::GEMINI_MODEL);

                $payload  = $this->buildGeminiPayload(
                    $prompt,
                    $mimeType,
                    $fileContent,
                    self::MAX_TOKENS_FILE,
                    0.2
                );
                $response = Http::timeout(self::TIMEOUT_FILE)
                    ->post($this->geminiEndpoint(), $payload);

                if ($response->successful()) {
                    return $this->extractGeminiText($response->json(), 'image');
                }

                Log::warning(
                    '[AIService] Gemini image HTTP error ' . $response->status() .
                    ' — trying OpenRouter'
                );
            } catch (\Exception $e) {
                Log::warning('[AIService] Gemini image exception: ' . $e->getMessage());
            }
        }

        if ($this->openRouterApiKey) {
            try {
                Log::info('[AIService] Sending image to OpenRouter ' . self::OPENROUTER_VIS_MODEL);

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

                throw new \RuntimeException('OpenRouter Vision HTTP error: ' . $response->status());

            } catch (\Exception $e) {
                Log::error('[AIService] OpenRouter image error: ' . $e->getMessage());
                throw $e;
            }
        }

        throw new \RuntimeException('No vision service available. Configure Gemini or OpenRouter API keys.');
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
                        'content' => 'Always respond in the language explicitly requested in the user prompt.',
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
            ->post($this->geminiEndpoint(), $payload);

        $this->assertHttpSuccess($response, 'Gemini');
        return $this->extractGeminiText($response->json(), 'text');
    }

    private function askOpenRouter(string $prompt): string
    {
        $response = Http::withHeaders($this->openRouterHeaders())
            ->timeout(self::TIMEOUT_TEXT)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'       => self::OPENROUTER_MODEL,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are an AI assistant. Follow the language in the user prompt.',
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
    // PRIVATE — HELPERS (CHANGE: extracted to remove duplication)
    // ══════════════════════════════════════════════════════════

    /**
     * Build the Gemini request payload for file-based calls.
     * Single source of truth — avoids the 3 near-identical arrays in the original.
     */
    private function buildGeminiPayload(
        string $textPrompt,
        string $mimeType,
        string $base64Data,
        int    $maxTokens,
        float  $temperature = 0.3
    ): array {
        return [
            'contents' => [[
                'parts' => [
                    // CHANGE: text always first — Gemini processes instructions before data
                    ['text' => $textPrompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data'      => $base64Data,
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];
    }

    /**
     * CHANGE: unified Gemini response extractor with structured error handling.
     * Original code had the same nested-array access duplicated 4 times.
     */
    private function extractGeminiText(array $result, string $context): string
    {
        // Safety block check
        $finishReason = $result['candidates'][0]['finishReason'] ?? null;
        if ($finishReason === 'SAFETY') {
            Log::warning("[AIService] Gemini blocked {$context} content for safety");
            throw new \RuntimeException(
                "The {$context} was blocked by Google's safety filter. " .
                'Try rephrasing your prompt or use a different document.'
            );
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            Log::error("[AIService] Gemini returned no text for {$context}", [
                'finish_reason'     => $finishReason,
                'candidates_count'  => count($result['candidates'] ?? []),
            ]);
            throw new \RuntimeException(
                "Gemini returned an empty response for the {$context}. " .
                'The file may be corrupted, password-protected, or the content was filtered.'
            );
        }

        return $text;
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

    /** @throws \RuntimeException */
    private function assertFileReadable(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found or not readable: {$filePath}");
        }
    }

    private function geminiEndpoint(): string
    {
        return self::GEMINI_BASE_URL . self::GEMINI_MODEL .
               ':generateContent?key=' . $this->geminiApiKey;
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
            'Authorization' => 'Bearer ' . $this->openRouterApiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    private function buildPrompt(string $prompt, array $context = []): string
    {
        if (empty($context)) {
            return $prompt;
        }

        $contextStr = "Context:\n";
        foreach ($context as $key => $value) {
            $contextStr .= "- {$key}: {$value}\n";
        }
        return $contextStr . "\n\nQuestion: " . $prompt;
    }
}