<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;
use App\Models\QCMHistory;
use App\Services\AIService;

class AIController extends Controller
{
    /**
     * 🤖 AI Chat
     */
    public function askAI(Request $request, AIService $ai)
    {
        $request->validate(['message' => 'required|string|max:2000']);
        
        $lang = $this->detectLanguage(substr($request->message, 0, 1000));
        
        try {
            $user = auth()->user();
            $niveau = 'intermediaire';
            if ($user && $user->profile) {
                $niveau = $user->profile->niveau ?? 'intermediaire';
            }

            $prompt = "
You are an intelligent AI assistant.
RULE: Respond STRICTLY in this language: {$lang}
Student level: {$niveau}
Question: {$request->message}
";
            $reply = $ai->ask($prompt);
            return response()->json(['success' => true, 'reply' => $reply], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('AI Chat error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Service IA indisponible.'], 500);
        }
    }

    /**
     * 📄 Generate Summary
     */
    public function generateSummary(Request $request, AIService $ai)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
        ]);

        try {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());

            if ($ext === 'pdf') {
                // 1️⃣ Essayer d'extraire le texte avec smalot
                $text = '';
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($file->path());
                    $text = $pdf->getText();
                } catch (\Exception $e) {
                    \Log::warning('PDF parser failed: ' . $e->getMessage());
                }

                // 2️⃣ Si texte extrait → Groq (rapide)
                if (!empty(trim($text)) && strlen(trim($text)) > 100) {
                    $lang = $this->detectLanguage(substr($text, 0, 1000));
                    $prompt = "
You are a professional summarizer.
RULES:
- Respond ONLY in language: {$lang}
- Never translate
- Keep original language

TASK: Create a clear structured summary.
FORMAT:
1. Introduction
2. Key Points
3. Conclusion

TEXT:
" . substr($text, 0, 8000);
                    $summary = $ai->ask($prompt);

                // 3️⃣ Si texte vide (PDF scanné/images) → Gemini Vision
                } else {
                    \Log::info('PDF has no text, using Gemini Vision...');
                    $prompt = "
You are a professional summarizer.
IMPORTANT:
- Detect the language of the document automatically
- Respond ONLY in the SAME language as the document
- Arabic → Arabic, French → French, English → English
- Never translate

TASK: Create a clear structured summary.
FORMAT:
1. Introduction
2. Key Points
3. Conclusion
";
                    try {
                        $summary = $ai->askGeminiWithFile($prompt, $file->path());
                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'error' => 'PDF illisible. Essayez un PDF avec du texte sélectionnable.'
                        ], 400);
                    }
                }

                $lang = $this->detectLanguage(substr($summary, 0, 500));
                return response()->json([
                    'success' => true,
                    'language_detected' => $lang,
                    'summary' => $summary
                ], 200, [], JSON_UNESCAPED_UNICODE);
            }

            // ✅ TXT / DOC / DOCX
            $text = $this->extractTextFromFile($file);
            if (empty(trim($text))) {
                return response()->json(['success' => false, 'error' => 'Fichier vide ou illisible'], 400);
            }

            $lang = $this->detectLanguage(substr($text, 0, 1000));
            $prompt = "
You are a professional summarizer.
RULES:
- Respond ONLY in language: {$lang}
- Never translate

TASK: Create a structured summary.
FORMAT:
1. Introduction
2. Key Points
3. Conclusion

TEXT:
" . substr($text, 0, 8000);

            $summary = $ai->ask($prompt);
            return response()->json([
                'success' => true,
                'language_detected' => $lang,
                'summary' => $summary
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('Summary error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Erreur lors de la génération du résumé'], 500);
        }
    }

    /**
     * ❓ Generate QCM
     */
    public function generateQCM(Request $request, AIService $ai)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,txt,doc,docx|max:20480',
        ]);

        try {
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            $response = '';

            if ($ext === 'pdf') {
                // 1️⃣ Essayer d'extraire le texte avec smalot
                $text = '';
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($file->path());
                    $text = $pdf->getText();
                } catch (\Exception $e) {
                    \Log::warning('PDF parser failed: ' . $e->getMessage());
                }

                // 2️⃣ Si texte extrait → Groq (rapide et fiable)
                if (!empty(trim($text)) && strlen(trim($text)) > 100) {
                    $lang = $this->detectLanguage(substr($text, 0, 1000));
                    $prompt = "
You are an AI exam generator.
CRITICAL RULES:
- ALL content MUST be in language: {$lang}
- NEVER use another language
- Return ONLY valid JSON, no explanation

TASK: Generate 20 multiple choice questions.

FORMAT EXACTLY:
{
  \"title\": \"...\",
  \"questions\": [
    {
      \"question\": \"...\",
      \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"],
      \"correct\": \"A\"
    }
  ]
}

TEXT:
" . substr($text, 0, 8000);
                    $response = $ai->ask($prompt);

                // 3️⃣ Si PDF scanné/images → Gemini Vision
                } else {
                    \Log::info('PDF has no text, using Gemini Vision...');
                    $prompt = "
You are an AI exam generator.
CRITICAL RULES:
- Detect the language of the document automatically
- Generate ALL questions in the SAME language
- Arabic PDF → Arabic questions
- French PDF → French questions
- English PDF → English questions
- Return ONLY valid JSON, nothing else

FORMAT EXACTLY:
{
  \"title\": \"...\",
  \"questions\": [
    {
      \"question\": \"...\",
      \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"],
      \"correct\": \"A\"
    }
  ]
}
";
                    try {
                        $response = $ai->askGeminiWithFile($prompt, $file->path());
                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'error' => 'PDF illisible. Essayez un PDF avec du texte sélectionnable.'
                        ], 400);
                    }
                }

            } else {
                // ✅ TXT / DOC / DOCX
                $text = $this->extractTextFromFile($file);
                if (empty(trim($text))) {
                    return response()->json(['success' => false, 'error' => 'Fichier vide ou illisible'], 400);
                }

                $lang = $this->detectLanguage(substr($text, 0, 1000));
                $prompt = "
You are an AI exam generator.
RULES:
- ALL content MUST be in language: {$lang}
- Return ONLY valid JSON

FORMAT EXACTLY:
{
  \"title\": \"...\",
  \"questions\": [
    {
      \"question\": \"...\",
      \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"],
      \"correct\": \"A\"
    }
  ]
}

TEXT:
" . substr($text, 0, 8000);
                $response = $ai->ask($prompt);
            }

            // ✅ Clean JSON
            $jsonStr = $this->extractJSON($response);
            $jsonStr = preg_replace('/,\s*}/', '}', $jsonStr);
            $jsonStr = preg_replace('/,\s*]/', ']', $jsonStr);
            $qcm = json_decode($jsonStr, true);

            if (!$qcm || !isset($qcm['questions']) || !is_array($qcm['questions'])) {
                \Log::error('Bad QCM format: ' . $response);
                return response()->json([
                    'success' => false,
                    'error' => 'Format invalide, réessayez',
                    'debug' => $response
                ], 500);
            }

            $lang = $this->detectLanguage($qcm['questions'][0]['question'] ?? '');
            return response()->json([
                'success' => true,
                'language_detected' => $lang,
                'qcm' => $qcm
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('QCM error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Erreur lors de la génération du QCM'], 500);
        }
    }

    /**
     * 🧠 Save Score
     */
    public function saveScore(Request $request)
    {
        $request->validate([
            'score' => 'required|integer',
            'total_questions' => 'required|integer'
        ]);

        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

        $profile = $user->profile;
        if (!$profile) return response()->json(['error' => 'Profile not found'], 404);

        QCMHistory::create([
            'user_id' => $user->id,
            'score' => $request->score,
            'total_questions' => $request->total_questions
        ]);

        $total = $profile->total_qcm ?? 0;
        $avg = $profile->score_moyen ?? 0;
        $newTotal = $total + 1;
        $newAvg = (($avg * $total) + $request->score) / $newTotal;
        $niveau = $newAvg < 50 ? 'debutant' : ($newAvg < 75 ? 'intermediaire' : 'avance');

        $profile->update([
            'total_qcm' => $newTotal,
            'score_moyen' => round($newAvg, 2),
            'niveau' => $niveau,
            'points_faibles' => json_encode($request->input('wrong_answers', []))
        ]);

        return response()->json(['success' => true, 'niveau' => $niveau, 'score_moyen' => round($newAvg, 2)]);
    }

    /**
     * 📜 QCM History
     */
    public function history()
    {
        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);
        return response()->json($user->qcmHistories()->latest()->get());
    }

    /**
     * 🤖 AI Coach
     */
    public function aiCoach(Request $request, AIService $ai)
    {
        try {
            $user = auth()->user();
            if (!$user) return response()->json(['coach' => '⚠️ Connectez-vous'], 401);

            $profile = $user->profile;
            if (!$profile) return response()->json(['coach' => '⚠️ Profil introuvable']);

            $avg = $profile->score_moyen ?? 0;
            $niveau = $profile->niveau ?? 'intermediaire';
            $weakPoints = json_decode($profile->points_faibles ?? "[]", true);
            $weakPointsStr = !empty($weakPoints) ? implode(', ', array_slice($weakPoints, 0, 5)) : 'Aucun';

            $prompt = "
You are a student AI coach. Respond in French.
Student data:
- Level: {$niveau}
- Average score: {$avg}%
- Weak points: {$weakPointsStr}

Give:
- Personalized advice
- Simple study plan
- Motivation message
";
            $coach = $ai->ask($prompt);
            return response()->json(['coach' => $coach]);

        } catch (\Exception $e) {
            \Log::error('AI Coach error: ' . $e->getMessage());
            return response()->json(['coach' => '💪 Continuez à pratiquer régulièrement !'], 500);
        }
    }

    /**
     * 📄 Extract text from non-PDF files
     */
    private function extractTextFromFile($file)
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'txt') {
            return file_get_contents($file->path());
        }

        if (in_array($ext, ['doc', 'docx'])) {
            $phpWord = IOFactory::load($file->path());
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $el) {
                    if (method_exists($el, 'getText')) {
                        $text .= $el->getText() . "\n";
                    }
                }
            }
            return $text;
        }

        throw new \Exception("Format non supporté");
    }

    /**
     * 🧹 Extract JSON
     */
    private function extractJSON($text)
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }

    /**
     * 🌍 Detect Language
     */
    private function detectLanguage($text)
    {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) return 'ar';
        if (preg_match('/\b(le|la|les|un|une|des|est|avec|pour|dans|bonjour)\b/u', strtolower($text))) return 'fr';
        if (preg_match('/\b(el|la|los|las|es|con|para|en|hola)\b/u', strtolower($text))) return 'es';
        if (preg_match('/\b(der|die|das|ist|mit|für|und|hallo)\b/u', strtolower($text))) return 'de';
        return 'en';
    }

    /**
     * 🃏 Flashcards
     */
    public function generateFlashcards(Request $request)
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon']);
    }

    /**
     * 📚 Explain Concept
     */
    public function explainConcept(Request $request)
    {
        return response()->json(['success' => false, 'message' => 'Feature coming soon']);
    }
}