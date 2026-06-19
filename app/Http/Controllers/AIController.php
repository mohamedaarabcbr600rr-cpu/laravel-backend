<?php

namespace App\Http\Controllers;

use App\Models\QCMHistory;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

/**
 * AIController — AI-powered endpoints for Studmo.
 *
 * Endpoint map:
 *  POST /ai/ask          → askAI()
 *  POST /ai/summary      → generateSummary()
 *  POST /ai/qcm          → generateQCM()
 *  POST /ai/score        → saveScore()
 *  GET  /ai/history      → history()
 *  GET  /ai/coach        → aiCoach()
 *
 * PDF routing strategy (applied in both summary and QCM):
 *
 *  ┌────────────────────────────────────────────────────────────────────────┐
 *  │ Step 1 — Attempt smalot text extraction                                │
 *  │ Step 2 — Quality check: extracted text reliable?                       │
 *  │          Criteria: ≥ MIN_TEXT_CHARS non-whitespace                     │
 *  │          AND Arabic script detected OR no garbled characters           │
 *  │ Step 3a (text OK)  → truncate to MAX_TEXT_CHARS, ask via text prompt  │
 *  │ Step 3b (text bad) → send native PDF bytes to Gemini 2.5 Pro           │
 *  │                       (handles scanned, image-only, mixed, Arabic RTL) │
 *  └────────────────────────────────────────────────────────────────────────┘
 *
 * This hybrid approach:
 *  - Uses fast/cheap text path when text is clean and extractable
 *  - Automatically falls back to Gemini native PDF for:
 *      • Scanned PDFs (no selectable text)
 *      • Arabic PDFs where smalot produces garbled output
 *      • Mixed text+image PDFs
 *      • PDFs with embedded tables that smalot cannot reconstruct
 */
class AIController extends Controller
{
    // ──────────────────────────────────────────────────────────
    // CONSTANTS
    // ──────────────────────────────────────────────────────────

    /**
     * Maximum characters of smalot-extracted text forwarded to text-only models.
     * Groq LLaMA 3.3 70B has ~32 k-token context; 12 000 chars ≈ 3 k tokens,
     * leaving room for the prompt instructions and the response.
     */
    private const MAX_TEXT_CHARS = 12000;

    /**
     * Minimum non-whitespace characters for extracted text to be considered usable.
     *
     * Why 200 (not 100)?
     *  Smalot sometimes extracts partial metadata or a cover-page copyright notice
     *  from scanned PDFs. 100 chars passes that threshold; 200 is safer.
     */
    private const MIN_USABLE_TEXT_CHARS = 200;

    /**
     * Ratio of printable-to-total characters below which text is considered
     * garbled (e.g. smalot output on Arabic PDFs with Type3 fonts).
     * 0.70 = 70% printable characters required.
     */
    private const MIN_PRINTABLE_RATIO = 0.70;

    /** Sample size for language detection heuristic */
    private const LANG_SAMPLE_CHARS = 800;

    // ══════════════════════════════════════════════════════════
    // 🤖 Text Chat
    // ══════════════════════════════════════════════════════════

    public function askAI(Request $request, AIService $ai): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:3000']);

        $lang = $this->detectLanguage(substr($request->message, 0, self::LANG_SAMPLE_CHARS));

        try {
            $niveau = 'intermediaire';
            if ($user = auth()->user()) {
                $niveau = $user->profile?->niveau ?? 'intermediaire';
            }

            $prompt = <<<PROMPT
You are an intelligent educational AI assistant for university students.
CRITICAL RULE: Respond STRICTLY and EXCLUSIVELY in this language code: {$lang}
- If lang is "ar" → respond in Arabic (RTL, formal Modern Standard Arabic)
- If lang is "fr" → respond in French
- If lang is "en" → respond in English
Student level: {$niveau}

Question: {$request->message}
PROMPT;

            $reply = $ai->ask($prompt);

            return response()->json(
                ['success' => true, 'reply' => $reply],
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );

        } catch (\Throwable $e) {
            Log::error('[AIController] Chat error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'AI service temporarily unavailable.'], 503);
        }
    }

    // ══════════════════════════════════════════════════════════
    // 📄 Summary Generation
    // ══════════════════════════════════════════════════════════

    public function generateSummary(Request $request, AIService $ai): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
        ]);

        try {
            $file = $request->file('file');
            $ext  = strtolower($file->getClientOriginalExtension());

            [$summary, $lang] = match ($ext) {
                'pdf'          => $this->processPdfForSummary($file, $ai),
                'txt'          => $this->processTextFileForSummary($file, $ai),
                'doc', 'docx'  => $this->processWordFileForSummary($file, $ai),
                default        => throw new \InvalidArgumentException("Unsupported extension: {$ext}"),
            };

            return response()->json([
                'success'           => true,
                'language_detected' => $lang,
                'summary'           => $summary,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            Log::error('[AIController] Summary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => 'Error generating summary. Please try again.',
            ], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // ❓ QCM Generation
    // ══════════════════════════════════════════════════════════

    public function generateQCM(Request $request, AIService $ai): JsonResponse
    {
        $request->validate([
            'file'           => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
            'num_questions'  => 'nullable|integer|min:5|max:30',
        ]);

        // Allow caller to request between 5 and 30 questions; default 20
        $numQuestions = (int) ($request->input('num_questions', 20));

        try {
            $file = $request->file('file');
            $ext  = strtolower($file->getClientOriginalExtension());

            $rawResponse = match ($ext) {
                'pdf'         => $this->processPdfForQCM($file, $ai, $numQuestions),
                'txt'         => $this->processTextFileForQCM($file, $ai, $numQuestions),
                'doc', 'docx' => $this->processWordFileForQCM($file, $ai, $numQuestions),
                default       => throw new \InvalidArgumentException("Unsupported extension: {$ext}"),
            };

            $jsonStr = $this->extractJSON($rawResponse);
            $jsonStr = $this->sanitizeJSON($jsonStr);
            $qcm     = json_decode($jsonStr, true);

            if (!$this->isValidQCM($qcm)) {
                Log::error('[AIController] QCM validation failed. Raw AI response: ' . substr($rawResponse, 0, 1000));
                return response()->json([
                    'success' => false,
                    'error'   => 'The AI returned an invalid format. Please try again.',
                    'debug'   => config('app.debug') ? $rawResponse : null,
                ], 500);
            }

            // Normalise missing "explanation" keys so frontend never breaks
            foreach ($qcm['questions'] as &$q) {
                $q['explanation'] = $q['explanation'] ?? '';
            }
            unset($q);

            $lang = $this->detectLanguage($qcm['questions'][0]['question'] ?? '');

            return response()->json([
                'success'           => true,
                'language_detected' => $lang,
                'qcm'               => $qcm,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            Log::error('[AIController] QCM error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => 'Error generating QCM. Please try again.',
            ], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // 🧠 Save Score
    // ══════════════════════════════════════════════════════════

    public function saveScore(Request $request): JsonResponse
    {
        $request->validate([
            'score'           => 'required|integer|min:0',
            'total_questions' => 'required|integer|min:1|max:100',
            'wrong_answers'   => 'nullable|array',
        ]);

        if ($request->score > $request->total_questions) {
            return response()->json(['error' => 'Score cannot exceed total_questions.'], 422);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $profile = $user->profile;
        if (!$profile) {
            return response()->json(['error' => 'Profile not found.'], 404);
        }

        QCMHistory::create([
            'user_id'         => $user->id,
            'score'           => $request->score,
            'total_questions' => $request->total_questions,
        ]);

        // Incremental running-average update (numerically stable)
        $prevTotal = (int)  ($profile->total_qcm   ?? 0);
        $prevAvg   = (float)($profile->score_moyen ?? 0.0);
        $newTotal  = $prevTotal + 1;

        // Convert raw score to percentage for consistent averaging
        $scorePct  = ($request->score / $request->total_questions) * 100;
        $newAvg    = (($prevAvg * $prevTotal) + $scorePct) / $newTotal;

        $niveau = match (true) {
            $newAvg < 40  => 'debutant',
            $newAvg < 70  => 'intermediaire',
            default       => 'avance',
        };

        $wrongAnswers = array_slice((array) $request->input('wrong_answers', []), 0, 20);

        $profile->update([
            'total_qcm'      => $newTotal,
            'score_moyen'    => round($newAvg, 2),
            'niveau'         => $niveau,
            'points_faibles' => json_encode($wrongAnswers, JSON_UNESCAPED_UNICODE),
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
    // 🎯 AI Coach
    // ══════════════════════════════════════════════════════════

    public function aiCoach(Request $request, AIService $ai): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['coach' => '⚠️ Please log in first.'], 401);
        }

        $profile = $user->profile;
        if (!$profile) {
            return response()->json(['coach' => '⚠️ Profile not found.'], 404);
        }

        try {
            $avg        = round((float)($profile->score_moyen ?? 0), 1);
            $niveau     = $profile->niveau ?? 'intermediaire';
            $weakRaw    = json_decode($profile->points_faibles ?? '[]', true);
            $weakPoints = is_array($weakRaw) ? array_slice($weakRaw, 0, 5) : [];
            $weakStr    = !empty($weakPoints) ? implode(', ', $weakPoints) : 'None identified yet';

            $prompt = <<<PROMPT
You are a motivating AI study coach. Respond in French.

Student profile:
- Level: {$niveau}
- Average score: {$avg}%
- Weak areas: {$weakStr}

Provide a structured coaching message with these three sections:
1. 📊 Analysis — 2–3 sentences on their current performance
2. 📅 Weekly Study Plan — 5 concrete daily actions tailored to their weak areas
3. 💪 Motivation — 2 sentences to encourage them to keep going

Keep the tone friendly, direct, and actionable.
PROMPT;

            $coach = $ai->ask($prompt);
            return response()->json(['coach' => $coach]);

        } catch (\Throwable $e) {
            Log::error('[AIController] AI Coach error: ' . $e->getMessage());
            return response()->json(['coach' => '💪 Keep practising regularly — consistency is the key!'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════
    // STUB ENDPOINTS
    // ══════════════════════════════════════════════════════════

    public function generateFlashcards(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon.']);
    }

    public function explainConcept(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon.']);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PDF PIPELINE
    // ══════════════════════════════════════════════════════════

    /**
     * Summary: try smalot extraction → quality check → route appropriately.
     * Returns [summary, language_code].
     */
    private function processPdfForSummary($file, AIService $ai): array
    {
        [$text, $quality] = $this->extractAndAssessPdfText($file);

        if ($quality === 'good') {
            Log::info('[AIController] PDF summary: using extracted text path');
            $lang    = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
            $prompt  = $this->buildSummaryPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS));
            $summary = $ai->ask($prompt);
            $lang    = $this->detectLanguage(substr($summary, 0, 400));
            return [$summary, $lang];
        }

        Log::info("[AIController] PDF summary: text quality='{$quality}' → Gemini native PDF");
        $prompt  = $this->buildSummaryVisionPrompt();
        $summary = $ai->askWithFile($prompt, $file->getRealPath());
        $lang    = $this->detectLanguage(substr($summary, 0, 400));
        return [$summary, $lang];
    }

    /**
     * QCM: same routing logic as summary.
     */
    private function processPdfForQCM($file, AIService $ai, int $numQuestions): string
    {
        [$text, $quality] = $this->extractAndAssessPdfText($file);

        if ($quality === 'good') {
            Log::info('[AIController] PDF QCM: using extracted text path');
            $lang   = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
            $prompt = $this->buildQCMPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS), $numQuestions);
            return $ai->ask($prompt);
        }

        Log::info("[AIController] PDF QCM: text quality='{$quality}' → Gemini native PDF");
        $prompt = $this->buildQCMVisionPrompt($numQuestions);
        return $ai->askWithFile($prompt, $file->getRealPath());
    }

    /**
     * Run smalot extraction and assess the quality of the result.
     *
     * Returns: [string $text, string $quality]
     *   quality = 'good'   → reliable, use text path
     *   quality = 'garbled' → too many non-printable chars (Arabic Type3, CID fonts, etc.)
     *   quality = 'sparse'  → too short to be useful (scanned PDF)
     *   quality = 'failed'  → smalot threw an exception
     *
     * Why do we need the garbled check?
     *   smalot succeeds silently on Arabic PDFs using Type3/CID fonts but returns
     *   replacement characters (▯▯▯) or Unicode private-use codepoints.
     *   strlen() ≥ 200 passes but the "text" is useless. The printable-ratio check
     *   catches this before we waste a Groq API call on garbage input.
     */
    private function extractAndAssessPdfText($file): array
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($file->getRealPath());
            $text   = $pdf->getText();
        } catch (\Throwable $e) {
            Log::warning('[AIController] smalot parse failed: ' . $e->getMessage());
            return ['', 'failed'];
        }

        $trimmed = trim($text);

        if (strlen($trimmed) < self::MIN_USABLE_TEXT_CHARS) {
            return [$text, 'sparse'];
        }

        // Printable character ratio check
        // Count characters that are printable ASCII, Arabic Unicode block, or common Latin accents
        $printable = preg_match_all(
            '/[\p{L}\p{N}\p{P}\p{Z}\p{Arabic}]/u',
            $trimmed,
            $matches
        );
        $ratio     = $printable / max(mb_strlen($trimmed), 1);

        if ($ratio < self::MIN_PRINTABLE_RATIO) {
            Log::info(sprintf(
                '[AIController] PDF text garbled (printable ratio %.2f < %.2f) — routing to Gemini',
                $ratio,
                self::MIN_PRINTABLE_RATIO
            ));
            return [$text, 'garbled'];
        }

        return [$text, 'good'];
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — WORD / TXT PROCESSING
    // ══════════════════════════════════════════════════════════

    private function processTextFileForSummary($file, AIService $ai): array
    {
        $text = $this->readTextFile($file);
        $lang = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        return [$ai->ask($this->buildSummaryPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS))), $lang];
    }

    private function processTextFileForQCM($file, AIService $ai, int $numQuestions): string
    {
        $text = $this->readTextFile($file);
        $lang = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        return $ai->ask($this->buildQCMPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS), $numQuestions));
    }

    private function processWordFileForSummary($file, AIService $ai): array
    {
        $text = $this->readWordFile($file);
        $lang = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        return [$ai->ask($this->buildSummaryPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS))), $lang];
    }

    private function processWordFileForQCM($file, AIService $ai, int $numQuestions): string
    {
        $text = $this->readWordFile($file);
        $lang = $this->detectLanguage(substr($text, 0, self::LANG_SAMPLE_CHARS));
        return $ai->ask($this->buildQCMPrompt($lang, substr($text, 0, self::MAX_TEXT_CHARS), $numQuestions));
    }

    private function readTextFile($file): string
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false || trim($content) === '') {
            throw new \RuntimeException('The uploaded text file is empty or unreadable.');
        }
        return $content;
    }

    private function readWordFile($file): string
    {
        $phpWord = IOFactory::load($file->getRealPath());
        $text    = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
                // Handle tables
                if (method_exists($element, 'getRows')) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            foreach ($cell->getElements() as $cellEl) {
                                if (method_exists($cellEl, 'getText')) {
                                    $text .= $cellEl->getText() . "\t";
                                }
                            }
                        }
                        $text .= "\n";
                    }
                }
            }
        }
        if (trim($text) === '') {
            throw new \RuntimeException('Could not extract text from the Word document.');
        }
        return $text;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — PROMPT BUILDERS
    // ══════════════════════════════════════════════════════════

    /**
     * QCM prompt for text-based files (smalot-extracted text or TXT/DOCX).
     *
     * Critical design choices:
     *  1. Language is explicitly locked — models will not drift to English mid-response.
     *  2. Section mapping step forces the model to build a mental outline first,
     *     which directly reduces first-page bias.
     *  3. "explanation" is required — helps students learn, not just test.
     *  4. Strict JSON schema is repeated twice (above and below the document text)
     *     because large context windows can cause instruction drift.
     *  5. Arabic: both "A)" prefix format and RTL rendering notes are included.
     */
    private function buildQCMPrompt(string $lang, string $text, int $numQuestions = 20): string
    {
        $langLabel = match ($lang) {
            'ar' => 'Arabic (Modern Standard Arabic, RTL)',
            'fr' => 'French',
            'es' => 'Spanish',
            'de' => 'German',
            default => 'English',
        };

        $arabicNote = $lang === 'ar'
            ? "\nARABIC RULES:\n- All questions, options, and explanations MUST be in Arabic.\n- Use Modern Standard Arabic (فصحى), not dialects.\n- Option labels: أ) ب) ج) د) instead of A) B) C) D).\n- The \"correct\" field still uses: \"أ\", \"ب\", \"ج\", or \"د\".\n"
            : '';

        return <<<PROMPT
You are an expert educator and assessment designer.

═══════════════════════════════════════
LANGUAGE LOCK: {$langLabel}
ALL output — title, questions, options, explanations — MUST be in {$langLabel}.
DO NOT switch to any other language under any circumstances.
═══════════════════════════════════════
{$arabicNote}
ANALYSIS PHASE (do this before writing questions):
1. Read the ENTIRE document text provided below.
2. Identify every chapter, section, and sub-section.
3. List the key concepts, definitions, formulas, processes, tables, and facts per section.
4. Note which sections are longest/most concept-dense.
5. Plan question distribution: allocate questions proportionally to section length and density.

GENERATION RULES:
- Generate exactly {$numQuestions} multiple-choice questions.
- Distribute questions across ALL sections (do NOT cluster on the first pages).
- No two questions may test the same concept.
- Each question tests UNDERSTANDING or APPLICATION, not pure recall of a single word.
- Each question has exactly 4 options.
- Exactly one option is correct.
- Distractors must be plausible (not obviously wrong).
- Include a brief explanation (1–2 sentences) for why the correct answer is right.

═══════════════════════════════════════
OUTPUT: valid JSON ONLY — no markdown fences, no preamble, no trailing text.
═══════════════════════════════════════
{
  "title": "<document title or descriptive name>",
  "questions": [
    {
      "question": "<full question text>",
      "options": ["A) <option>", "B) <option>", "C) <option>", "D) <option>"],
      "correct": "A",
      "explanation": "<why A is correct>"
    }
  ]
}

DOCUMENT TEXT:
───────────────────────────────────────
{$text}
───────────────────────────────────────

Remember: Output ONLY the JSON object. No text before or after it.
PROMPT;
    }

    /**
     * QCM prompt for native PDF sent directly to Gemini 2.5 Pro.
     *
     * This version instructs Gemini to use its multimodal capabilities:
     *  - Read every page visually (not just text layer)
     *  - Interpret tables, diagrams, figures, and captions
     *  - Process Arabic RTL content correctly
     *
     * The prompt is written in English because the instruction layer should
     * always be in a language Gemini follows with highest fidelity — the
     * model is told to detect and match the document language for output.
     */
    private function buildQCMVisionPrompt(int $numQuestions = 20): string
    {
        return <<<PROMPT
You are an expert educator and assessment designer. A document has been provided for you to analyze.

═══════════════════════════════════════
DOCUMENT READING INSTRUCTIONS
═══════════════════════════════════════
1. Read the ENTIRE document from page 1 to the LAST page — do not stop early.
2. Process EVERY element:
   - Body text (in any language, including Arabic RTL)
   - Section headings and chapter titles
   - Tables: read all rows and columns
   - Figures and diagrams: interpret what they show
   - Captions and footnotes
   - Mathematical formulas and equations
   - Bullet lists and numbered lists
3. Detect the document language automatically.
   - Arabic document → ALL output in Arabic (Modern Standard Arabic, فصحى)
   - French document → ALL output in French
   - English document → ALL output in English
   - Mixed → use the dominant language

═══════════════════════════════════════
ANALYSIS PHASE (internal — do not output this)
═══════════════════════════════════════
- Map all chapters and sections with their page ranges.
- List key concepts per section.
- Plan proportional question distribution across ALL sections.

═══════════════════════════════════════
QUESTION GENERATION RULES
═══════════════════════════════════════
- Generate exactly {$numQuestions} questions.
- Distribute proportionally — early pages should NOT dominate.
- No duplicate concepts.
- Test understanding and application, not rote recall.
- 4 options per question (A/B/C/D, or أ/ب/ج/د for Arabic).
- One correct answer.
- Plausible distractors.
- One-sentence explanation per question.

═══════════════════════════════════════
OUTPUT FORMAT
═══════════════════════════════════════
Return ONLY a valid JSON object. No markdown, no code fences, no preamble.

{
  "title": "<document title>",
  "questions": [
    {
      "question": "<question text in document language>",
      "options": ["A) ...", "B) ...", "C) ...", "D) ..."],
      "correct": "A",
      "explanation": "<explanation in document language>"
    }
  ]
}

For Arabic documents use option labels: "أ) ...", "ب) ...", "ج) ...", "د) ..."
and "correct" values: "أ", "ب", "ج", or "د".
PROMPT;
    }

    /**
     * Summary prompt for text-based files.
     */
    private function buildSummaryPrompt(string $lang, string $text): string
    {
        $langLabel = match ($lang) {
            'ar' => 'Arabic (Modern Standard Arabic)',
            'fr' => 'French',
            'es' => 'Spanish',
            'de' => 'German',
            default => 'English',
        };

        $arabicNote = $lang === 'ar'
            ? "\n- Write in Modern Standard Arabic (فصحى), right-to-left.\n- Do NOT translate technical terms — keep them in Arabic.\n"
            : '';

        return <<<PROMPT
You are a professional academic summarizer.

LANGUAGE: Respond ONLY in {$langLabel}. Do not translate or switch languages.
{$arabicNote}
RULES:
- Cover ALL sections of the document, not just the beginning.
- Preserve technical terms as they appear in the source.
- Do not add information not present in the document.

TASK: Write a structured academic summary.

FORMAT:
## Introduction
<2–3 sentences on the document's topic and purpose>

## Key Points
<Organized by section/chapter — include ALL major sections>
- Section 1: ...
- Section 2: ...
(continue for every section)

## Conclusion
<2–3 sentences synthesizing the main takeaways>

DOCUMENT TEXT:
{$text}
PROMPT;
    }

    /**
     * Summary prompt for vision-based (scanned/image) PDFs sent to Gemini.
     */
    private function buildSummaryVisionPrompt(): string
    {
        return <<<'PROMPT'
You are a professional academic summarizer. A document has been provided.

READING INSTRUCTIONS:
1. Read ALL pages from beginning to end — do not skip any.
2. Process text, tables, figures, diagrams, and captions.
3. Detect the document language automatically.
   - Arabic → respond in Arabic (Modern Standard Arabic, فصحى)
   - French → respond in French
   - English → respond in English

TASK: Write a structured academic summary covering the entire document.

FORMAT:
## Introduction
<2–3 sentences on the document's topic and purpose>

## Key Points
<Organized by section/chapter — include ALL major sections you identified>

## Conclusion
<2–3 sentences synthesizing the main takeaways>

Do NOT add information not present in the document.
PROMPT;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — JSON UTILITIES
    // ══════════════════════════════════════════════════════════

    /**
     * Extract the outermost JSON object from a mixed text/JSON string.
     *
     * Handles:
     *  - ```json ... ``` fences (Groq, Mistral, Qwen often add these)
     *  - Leading/trailing prose ("Here is the QCM: {...}")
     *  - Nested objects (finds the first { and last } at the top level)
     */
    private function extractJSON(string $text): string
    {
        // Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*/mi', '', $text);
        $text = preg_replace('/\s*```\s*$/mi', '', $text);
        $text = trim($text);

        // Find outermost JSON object
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /**
     * Clean common JSON artifacts emitted by LLMs.
     *
     *  - Trailing commas before } or ] (JSON spec violation)
     *  - Ellipsis or "..." placeholders in option arrays
     *  - Non-breaking spaces (U+00A0) that break json_decode on some PHP builds
     */
    private function sanitizeJSON(string $json): string
    {
        // Remove trailing commas
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);

        // Normalise non-breaking spaces to regular spaces
        $json = str_replace("\u{00A0}", ' ', $json);

        // Remove BOM if present
        $json = ltrim($json, "\xEF\xBB\xBF");

        return $json;
    }

    /**
     * Validate decoded QCM array.
     *
     * Required structure:
     *  {
     *    "title": string,
     *    "questions": [
     *      { "question": string, "options": array(4), "correct": string }
     *    ]
     *  }
     *
     * "explanation" is optional — normalised to '' by the caller if missing.
     */
    private function isValidQCM(?array $qcm): bool
    {
        if (!is_array($qcm)) {
            return false;
        }
        if (!isset($qcm['questions']) || !is_array($qcm['questions'])) {
            return false;
        }
        if (count($qcm['questions']) < 1) {
            return false;
        }

        // Validate at least the first 3 questions (full validation is too expensive)
        $sample = array_slice($qcm['questions'], 0, min(3, count($qcm['questions'])));
        foreach ($sample as $q) {
            if (!isset($q['question'], $q['options'], $q['correct'])) {
                return false;
            }
            if (!is_array($q['options']) || count($q['options']) < 2) {
                return false;
            }
            if (!is_string($q['correct']) || $q['correct'] === '') {
                return false;
            }
        }

        return true;
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE — LANGUAGE DETECTION
    // ══════════════════════════════════════════════════════════

    /**
     * Lightweight script/keyword-based language detection.
     *
     * Priority order prevents false-positives:
     *  1. Arabic Unicode block (definitive script check — no keyword ambiguity)
     *  2. French function words (common, short, distinctive)
     *  3. Spanish function words
     *  4. German function words
     *  5. Default: English
     *
     * Note: We sample the first LANG_SAMPLE_CHARS chars to keep this O(n) with small n.
     */
    private function detectLanguage(string $text): string
    {
        $sample = mb_substr($text, 0, self::LANG_SAMPLE_CHARS);

        // Arabic: any character in the Arabic Unicode block (U+0600–U+06FF)
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $sample)) {
            return 'ar';
        }

        // French: function words that are not shared with Spanish/English
        if (preg_match('/\b(le|la|les|un|une|des|est|sont|avec|pour|dans|sur|bonjour|merci|mais|donc)\b/iu', $sample)) {
            return 'fr';
        }

        // Spanish
        if (preg_match('/\b(el|la|los|las|un|una|es|con|para|en|hola|pero|también)\b/iu', $sample)) {
            return 'es';
        }

        // German
        if (preg_match('/\b(der|die|das|ein|ist|mit|für|und|oder|nicht|hallo|danke)\b/iu', $sample)) {
            return 'de';
        }

        return 'en';
    }
}