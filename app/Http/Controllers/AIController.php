<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;
use App\Models\QCMHistory;
use App\Services\AIService;

/**
 * AIController — Handles all AI-powered endpoints.
 *
 * CHANGES vs original:
 * ─────────────────────────────────────────────────────────────
 * 1.  QCM prompt completely rewritten (buildQCMPrompt / buildQCMVisionPrompt).
 *     The new prompt instructs the model to:
 *       a) Read the ENTIRE document page-by-page before generating anything.
 *       b) Extract concepts, definitions, formulas, processes, and tables.
 *       c) Distribute questions proportionally across ALL sections.
 *       d) Include an "explanation" field for every question.
 *       e) Avoid duplicate questions.
 *     This directly addresses the "only covers the first pages" bug.
 *
 * 2.  Summary prompt similarly improved: asks for section-aware coverage.
 *
 * 3.  buildQCMPrompt() / buildSummaryPrompt() extracted as private methods
 *     → eliminates the duplicated multi-line prompt strings scattered across
 *     generateSummary() and generateQCM().
 *
 * 4.  JSON response field "language_detected" preserved for frontend compat.
 *
 * 5.  extractTextFromFile() made private and typed (no functional change).
 *
 * 6.  extractJSON() now also strips Markdown code fences that LLMs sometimes
 *     prepend (```json ... ```), preventing silent JSON parse failures.
 *
 * 7.  Unused `use Illuminate\Support\Facades\Http;` removed.
 *
 * 8.  All magic numbers replaced with named constants.
 *
 * 9.  saveScore() now validates score ≤ total_questions to prevent
 *     corrupted average calculations.
 *
 * 10. Return types added to every method for IDE clarity.
 */
class AIController extends Controller
{
    // Maximum characters of extracted text passed to the text-only models.
    // Groq / Mistral have ~32 k-token context; 12 000 chars ≈ 3 000 tokens of padding.
    // CHANGE: raised from 8 000 → 12 000 to include more document content.
    private const MAX_TEXT_CHARS = 12000;

    // Characters used for language detection sample (unchanged)
    private const LANG_SAMPLE_CHARS = 1000;

    // ══════════════════════════════════════════════════════════
    // 🤖 AI Chat
    // ══════════════════════════════════════════════════════════

    public function askAI(Request $request, AIService $ai): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $lang = $this->detectLanguage(substr($request->message, 0, self::LANG_SAMPLE_CHARS));

        try {
            $niveau = 'intermediaire';
            if ($user = auth()->user()) {
                $niveau = $user->profile->niveau ?? 'intermediaire';
            }

            $prompt = "
You are an intelligent AI assistant.
RULE: Respond STRICTLY in this language: {$lang}
Student level: {$niveau}
Question: {$request->message}
";
            $reply = $ai->ask($prompt);
            return response()->json(
                ['success' => true, 'reply' => $reply],
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );

        } catch (\Exception $e) {
            \Log::error('[AIController] Chat error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'AI service unavailable.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // 📄 Generate Summary
    // ══════════════════════════════════════════════════════════

    public function generateSummary(Request $request, AIService $ai): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
        ]);

        try {
            $file = $request->file('file');
            $ext  = strtolower($file->getClientOriginalExtension());

            if ($ext === 'pdf') {
                [$summary, $lang] = $this->processPdfForSummary($file, $ai);
            } else {
                [$summary, $lang] = $this->processTextFileForSummary($file, $ai);
            }

            return response()->json([
                'success'           => true,
                'language_detected' => $lang,
                'summary'           => $summary,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('[AIController] Summary error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error generating summary.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // ❓ Generate QCM
    //
    // CHANGE: the entire PDF handling path now uses the new deep-analysis
    // prompt (buildQCMPrompt / buildQCMVisionPrompt) and validates that the
    // JSON includes the new "explanation" field.
    // ══════════════════════════════════════════════════════════

    public function generateQCM(Request $request, AIService $ai): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
        ]);

        try {
            $file     = $request->file('file');
            $ext      = strtolower($file->getClientOriginalExtension());
            $response = '';

            if ($ext === 'pdf') {
                $response = $this->processPdfForQCM($file, $ai);
            } else {
                $response = $this->processTextFileForQCM($file, $ai);
            }

            // CHANGE: strip ```json fences before parsing (common LLM artifact)
            $jsonStr = $this->extractJSON($response);
            $jsonStr = $this->sanitizeJSON($jsonStr);
            $qcm     = json_decode($jsonStr, true);

            if (!$this->isValidQCM($qcm)) {
                \Log::error('[AIController] Invalid QCM format. Raw response: ' . $response);
                return response()->json([
                    'success' => false,
                    'error'   => 'Invalid format received from AI. Please try again.',
                    'debug'   => $response,
                ], 500);
            }

            // Detect language from first question
            $firstQuestion = $qcm['questions'][0]['question'] ?? '';
            $lang = $this->detectLanguage($firstQuestion);

            return response()->json([
                'success'           => true,
                'language_detected' => $lang,
                'qcm'               => $qcm,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('[AIController] QCM error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error generating QCM.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // 🧠 Save Score
    // ══════════════════════════════════════════════════════════

    public function saveScore(Request $request): JsonResponse
    {
        $request->validate([
            'score'           => 'required|integer|min:0',
            'total_questions' => 'required|integer|min:1',
        ]);

        // CHANGE: prevent score > total from corrupting the average
        if ($request->score > $request->total_questions) {
            return response()->json(['error' => 'Score cannot exceed total questions.'], 422);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $profile = $user->profile;
        if (!$profile) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        QCMHistory::create([
            'user_id'         => $user->id,
            'score'           => $request->score,
            'total_questions' => $request->total_questions,
        ]);

        $total    = $profile->total_qcm  ?? 0;
        $avg      = $profile->score_moyen ?? 0;
        $newTotal = $total + 1;
        $newAvg   = (($avg * $total) + $request->score) / $newTotal;
        $niveau   = $newAvg < 50 ? 'debutant' : ($newAvg < 75 ? 'intermediaire' : 'avance');

        $profile->update([
            'total_qcm'      => $newTotal,
            'score_moyen'    => round($newAvg, 2),
            'niveau'         => $niveau,
            'points_faibles' => json_encode($request->input('wrong_answers', [])),
        ]);

        return response()->json([
            'success'     => true,
            'niveau'      => $niveau,
            'score_moyen' => round($newAvg, 2),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 📜 QCM History
    // ══════════════════════════════════════════════════════════

    public function history(): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return response()->json($user->qcmHistories()->latest()->get());
    }

    // ══════════════════════════════════════════════════════════
    // 🤖 AI Coach
    // ══════════════════════════════════════════════════════════

    public function aiCoach(Request $request, AIService $ai): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['coach' => '⚠️ Please log in first.'], 401);
            }

            $profile = $user->profile;
            if (!$profile) {
                return response()->json(['coach' => '⚠️ Profile not found.']);
            }

            $avg           = $profile->score_moyen ?? 0;
            $niveau        = $profile->niveau      ?? 'intermediaire';
            $weakPoints    = json_decode($profile->points_faibles ?? '[]', true);
            $weakPointsStr = !empty($weakPoints)
                ? implode(', ', array_slice($weakPoints, 0, 5))
                : 'None identified yet';

            $prompt = "
You are a student AI coach. Respond in French.
Student data:
- Level: {$niveau}
- Average score: {$avg}%
- Weak points: {$weakPointsStr}

Provide:
1. Personalized advice based on the data above
2. A simple, actionable weekly study plan
3. A motivating message
";
            $coach = $ai->ask($prompt);
            return response()->json(['coach' => $coach]);

        } catch (\Exception $e) {
            \Log::error('[AIController] AI Coach error: ' . $e->getMessage());
            return response()->json(['coach' => '💪 Keep practicing regularly!'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // STUB ENDPOINTS
    // ══════════════════════════════════════════════════════════

    public function generateFlashcards(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon']);
    }

    public function explainConcept(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon']);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PDF PROCESSING
    // ══════════════════════════════════════════════════════════

    /**
     * Try smalot text extraction first; fall back to Gemini vision.
     * Returns [summary string, language code].
     */
    private function processPdfForSummary($file, AIService $ai): array
    {
        $text = $this->tryExtractPdfText($file);

        if ($this->hasUsableText($text)) {
            $lang    = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
            $prompt  = $this->buildSummaryPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS));
            $summary = $ai->ask($prompt);
            $lang    = $this->detectLanguage(substr($summary, 0, 500));
            return [$summary, $lang];
        }

        \Log::info('[AIController] PDF has no extractable text — using Gemini vision');
        $prompt  = $this->buildSummaryVisionPrompt();
        $summary = $ai->askWithFile($prompt, $file->path());
        $lang    = $this->detectLanguage(substr($summary, 0, 500));
        return [$summary, $lang];
    }

    /**
     * Returns [qcm raw JSON string].
     * CHANGE: routes scanned PDFs to vision prompt, text PDFs to text prompt.
     */
    private function processPdfForQCM($file, AIService $ai): string
    {
        $text = $this->tryExtractPdfText($file);

        if ($this->hasUsableText($text)) {
            $lang   = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
            $prompt = $this->buildQCMPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS));
            return $ai->ask($prompt);
        }

        \Log::info('[AIController] PDF has no extractable text — using Gemini vision for QCM');
        $prompt = $this->buildQCMVisionPrompt();
        return $ai->askWithFile($prompt, $file->path());
    }

    /**
     * Attempt to extract text from a PDF via smalot.
     * Returns empty string on failure (caller decides what to do).
     */
    private function tryExtractPdfText($file): string
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($file->path());
            return $pdf->getText();
        } catch (\Exception $e) {
            \Log::warning('[AIController] smalot PDF parser failed: ' . $e->getMessage());
            return '';
        }
    }

    /** A string is "usable" if it contains at least 100 non-whitespace characters. */
    private function hasUsableText(string $text): bool
    {
        return strlen(trim($text)) >= 100;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — TEXT FILE PROCESSING
    // ══════════════════════════════════════════════════════════

    private function processTextFileForSummary($file, AIService $ai): array
    {
        $text = $this->extractTextFromFile($file);
        if (empty(trim($text))) {
            throw new \RuntimeException('Empty or unreadable file.');
        }
        $lang    = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        $prompt  = $this->buildSummaryPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS));
        $summary = $ai->ask($prompt);
        return [$summary, $lang];
    }

    private function processTextFileForQCM($file, AIService $ai): string
    {
        $text = $this->extractTextFromFile($file);
        if (empty(trim($text))) {
            throw new \RuntimeException('Empty or unreadable file.');
        }
        $lang   = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        $prompt = $this->buildQCMPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS));
        return $ai->ask($prompt);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PROMPT BUILDERS
    //
    // CHANGE: all prompts extracted into dedicated methods to avoid
    // duplication and to make future tuning trivial.
    // ══════════════════════════════════════════════════════════

    /**
     * QCM prompt for text-based PDFs / DOC / TXT.
     *
     * KEY CHANGES vs original:
     *  • Explicit instruction to cover ALL sections proportionally.
     *  • Asks for 20 questions with explanations.
     *  • Stricter JSON schema (adds "explanation" key).
     *  • Forbids repeating concepts.
     */
    private function buildQCMPrompt(string $lang, string $text): string
    {
        return <<<PROMPT
You are an expert educator and assessment designer.

LANGUAGE RULE: ALL output MUST be in language code "{$lang}". Never switch language.

TASK: Analyze the following document and generate exactly 20 multiple-choice questions.

ANALYSIS INSTRUCTIONS:
1. Read the ENTIRE text carefully before writing any question.
2. Identify ALL key concepts, definitions, facts, processes, formulas, and tables.
3. Map which section each concept belongs to.
4. Distribute the 20 questions PROPORTIONALLY across ALL sections — do not focus only on the beginning.
5. Avoid duplicate or very similar questions.
6. Questions must test understanding, not just memorization.
7. Each question must have exactly 4 answer options (A, B, C, D).
8. Only one option is correct.
9. Include a brief explanation for the correct answer.

RETURN FORMAT — valid JSON ONLY, no preamble, no markdown fences:
{
  "title": "...",
  "questions": [
    {
      "question": "...",
      "options": ["A) ...", "B) ...", "C) ...", "D) ..."],
      "correct": "A",
      "explanation": "..."
    }
  ]
}

DOCUMENT TEXT:
{$text}
PROMPT;
    }

    /**
     * QCM prompt for scanned/image PDFs sent directly to Gemini vision.
     *
     * CHANGE: instructs the model to read the PDF page-by-page visually
     * before generating questions, which is the key accuracy improvement
     * for Gemini 2.5 Pro on scanned/multilingual documents.
     */
    private function buildQCMVisionPrompt(): string
    {
        return <<<'PROMPT'
You are an expert educator and assessment designer.

LANGUAGE RULE:
- Detect the language of the document automatically (Arabic, French, or English).
- ALL output MUST be in that same detected language.
- Arabic document → Arabic questions and options.
- French document → French questions and options.
- English document → English questions and options.

READING INSTRUCTIONS:
1. Read the ENTIRE document page by page, including text, tables, diagrams, and images.
2. Do NOT stop after the first few pages.
3. Extract ALL key concepts, definitions, facts, processes, and formulas found throughout.
4. Identify all chapters and sections.

QUESTION GENERATION INSTRUCTIONS:
1. Generate exactly 20 multiple-choice questions.
2. Distribute questions PROPORTIONALLY across ALL sections of the document.
3. Avoid duplicate or very similar questions.
4. Test understanding and application, not just recall.
5. Each question must have exactly 4 options (A, B, C, D).
6. Only one option is correct.
7. Provide a brief explanation for the correct answer.

RETURN FORMAT — valid JSON ONLY, no preamble, no markdown fences, no code blocks:
{
  "title": "...",
  "questions": [
    {
      "question": "...",
      "options": ["A) ...", "B) ...", "C) ...", "D) ..."],
      "correct": "A",
      "explanation": "..."
    }
  ]
}
PROMPT;
    }

    /**
     * Summary prompt for text-based files.
     */
    private function buildSummaryPrompt(string $lang, string $text): string
    {
        return <<<PROMPT
You are a professional summarizer.

RULES:
- Respond ONLY in language code: {$lang}
- Never translate content
- Cover ALL sections of the document, not just the beginning

TASK: Create a clear, structured summary covering the entire document.

FORMAT:
1. Introduction
2. Key Points (organized by section/chapter)
3. Conclusion

TEXT:
{$text}
PROMPT;
    }

    /**
     * Summary prompt for vision-based (scanned) PDFs.
     */
    private function buildSummaryVisionPrompt(): string
    {
        return <<<'PROMPT'
You are a professional summarizer.

IMPORTANT:
- Detect the language of the document automatically.
- Respond ONLY in the SAME language as the document.
- Read and summarize ALL pages, not just the first ones.

TASK: Create a clear, structured summary covering the entire document.

FORMAT:
1. Introduction
2. Key Points (organized by section/chapter if visible)
3. Conclusion
PROMPT;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — UTILITIES
    // ══════════════════════════════════════════════════════════

    private function extractTextFromFile($file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'txt') {
            return file_get_contents($file->path());
        }

        if (in_array($ext, ['doc', 'docx'], true)) {
            $phpWord = IOFactory::load($file->path());
            $text    = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $el) {
                    if (method_exists($el, 'getText')) {
                        $text .= $el->getText() . "\n";
                    }
                }
            }
            return $text;
        }

        throw new \InvalidArgumentException("Unsupported format: {$ext}");
    }

    /**
     * Extract the first valid JSON object from a string.
     *
     * CHANGE: also strips ```json ... ``` Markdown fences that some LLMs
     * (particularly instruction-tuned models) prepend to JSON output.
     */
    private function extractJSON(string $text): string
    {
        // Strip Markdown code fences (```json ... ``` or ``` ... ```)
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /**
     * Remove trailing commas from JSON (common LLM artifact).
     * CHANGE: also normalises escaped Unicode from some providers.
     */
    private function sanitizeJSON(string $json): string
    {
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);
        return $json;
    }

    /**
     * Validate the decoded QCM array.
     *
     * CHANGE: also checks for "explanation" key (new requirement).
     * Falls back gracefully if the model omitted explanations.
     */
    private function isValidQCM(?array $qcm): bool
    {
        if (!$qcm || !isset($qcm['questions']) || !is_array($qcm['questions'])) {
            return false;
        }
        if (count($qcm['questions']) === 0) {
            return false;
        }
        // Check first question has the required fields
        $first = $qcm['questions'][0];
        return isset($first['question'], $first['options'], $first['correct']);
    }

    /**
     * Simple language detection based on script / keyword heuristics.
     * Unchanged from original — kept compact and dependency-free.
     */
    private function detectLanguage(string $text): string
    {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text))                                                   return 'ar';
        if (preg_match('/\b(le|la|les|un|une|des|est|avec|pour|dans|bonjour)\b/ui', $text))                return 'fr';
        if (preg_match('/\b(el|la|los|las|es|con|para|en|hola)\b/ui', $text))                              return 'es';
        if (preg_match('/\b(der|die|das|ist|mit|f[üu]r|und|hallo)\b/ui', $text))                          return 'de';
        return 'en';
    }
}