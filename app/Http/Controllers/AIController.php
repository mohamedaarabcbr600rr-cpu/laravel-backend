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
     * 🤖 AI Chat (adaptive avec multi-IA)
     */
    public function askAI(Request $request, AIService $ai)
    {
        $request->validate([
            'message' => 'required|string|max:2000'
        ]);
$lang = $this->detectLanguage(substr($request->message, 0, 1000));
        try {
            $user = auth()->user();
            
            // Si user non connecté, niveau par défaut
            $niveau = 'intermediaire';
            if ($user && $user->profile) {
                $niveau = $user->profile->niveau ?? 'intermediaire';
            }
        
           $prompt = "
Tu es un assistant IA intelligent.

RÈGLE IMPORTANTE:
- Réponds STRICTEMENT dans la langue suivante: {$lang}
- Ne change jamais de langue

Niveau étudiant: {$niveau}

Instructions:
- ar → réponds en arabe
- en → answer in English
- fr → réponds en français

Question:
{$request->message}
";

            // ✅ Utiliser le service multi-IA
            $reply = $ai->ask($prompt);

            return response()->json([
    'success' => true,
    'reply' => $reply
], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            \Log::error('AI Chat error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Service IA temporairement indisponible. Veuillez réessayer.'
            ], 500);
        }
    }

/*Méthode de détection de langue (simple)*/ 
    private function detectLanguage($text)
{
    $text = strtolower($text);

    // Arabic
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return 'ar';
    }

    // French
    if (preg_match('/\b(le|la|les|un|une|des|est|avec|pour|dans|bonjour)\b/u', $text)) {
        return 'fr';
    }

    // English
    if (preg_match('/\b(the|and|is|are|hello|hi|what|how|this)\b/i', $text)) {
        return 'en';
    }

    // Default
    return 'en';
}

   /**
 * 📄 Generate Summary
 */
public function generateSummary(Request $request, AIService $ai)
{
    $request->validate([
        'file' => 'required|file|mimes:pdf,txt,doc,docx|max:10240',
    ]);

    try {
        // 1️⃣ Extract text
        $text = $this->extractTextFromFile($request->file('file'));

        if (empty(trim($text))) {
            return response()->json([
                'success' => false,
                'error' => 'Le fichier est vide ou illisible'
            ], 400);
        }

        // 2️⃣ Detect language (first 1000 chars only for speed)
        $lang = $this->detectLanguage(substr($text, 0, 1000));

        // 3️⃣ Normalize language (extra safety)
        if (!in_array($lang, ['ar', 'fr', 'en'])) {
            $lang = 'en';
        }

        // 4️⃣ STRONG PROMPT (very important 🔥)
        $prompt = "
You are a professional AI summarizer.

🚨 VERY IMPORTANT RULES:
- You MUST write ONLY in this language: {$lang}
- NEVER translate the text
- NEVER use another language
- If the text is Arabic → answer Arabic
- If French → answer French
- If English → answer English

TASK:
Create a clear and structured summary.

FORMAT:
1. Introduction
2. Key Points
3. Conclusion

TEXT:
" . substr($text, 0, 8000);

        // 5️⃣ Call AI
        $summary = $ai->ask($prompt);

        // 6️⃣ Extra safety (force encoding)
        $summary = mb_convert_encoding($summary, 'UTF-8', 'UTF-8');

        return response()->json([
            'success' => true,
            'language_detected' => $lang,
            'summary' => $summary
        ], 200, [], JSON_UNESCAPED_UNICODE);

    } catch (\Exception $e) {
        \Log::error('Summary error: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'error' => 'Erreur lors de la génération du résumé'
        ], 500);
    }
}

    /**
     * ❓ Generate QCM
     */
    public function generateQCM(Request $request, AIService $ai)
{
    $request->validate([
        'file' => 'required|file|mimes:pdf,txt,doc,docx|max:10240',
    ]);

    try {
        // 1️⃣ Extract text
        $text = $this->extractTextFromFile($request->file('file'));

        if (empty(trim($text))) {
            return response()->json([
                'success' => false,
                'error' => 'Fichier vide ou illisible'
            ], 400);
        }

        // 2️⃣ Detect language
        $lang = $this->detectLanguage(substr($text, 0, 1000));

        if (!in_array($lang, ['ar', 'fr', 'en'])) {
            $lang = 'en';
        }

        // 3️⃣ 🔥 STRONG PROMPT
        $prompt = "
You are an AI exam generator.

🚨 VERY IMPORTANT RULES:
- ALL content MUST be in this language: {$lang}
- NEVER use another language
- NEVER translate
- NO mixing languages
- Return ONLY valid JSON (no explanation, no text outside JSON)

TASK:
Generate 20 multiple choice questions (MCQ).

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

        // 4️⃣ Call AI
        $response = $ai->ask($prompt);

        // 5️⃣ Clean JSON (important 🔥)
        $jsonStr = $this->extractJSON($response);

        // محاولة إصلاح JSON إذا كان فيه أخطاء بسيطة
        $jsonStr = preg_replace('/,\s*}/', '}', $jsonStr);
        $jsonStr = preg_replace('/,\s*]/', ']', $jsonStr);

        $qcm = json_decode($jsonStr, true);

        // 6️⃣ Validation قوية
        if (!$qcm || !isset($qcm['questions']) || !is_array($qcm['questions'])) {
            \Log::error('Bad QCM format: ' . $response);

            return response()->json([
                'success' => false,
                'error' => 'AI response invalid',
                'debug' => $response // باش تشوف المشكل
            ], 500);
        }

        // 7️⃣ Success
        return response()->json([
            'success' => true,
            'language_detected' => $lang,
            'qcm' => $qcm
        ], 200, [], JSON_UNESCAPED_UNICODE);

    } catch (\Exception $e) {
        \Log::error('QCM error: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'error' => 'Erreur lors de la génération du QCM'
        ], 500);
    }
}
    /**
     * 🧠 Save Score + Update Level
     */
    public function saveScore(Request $request)
    {
        $request->validate([
            'score' => 'required|integer',
            'total_questions' => 'required|integer'
        ]);

        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized - Veuillez vous connecter'
            ], 401);
        }

        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'error' => 'Profile not found'
            ], 404);
        }

        // Sauvegarde du résultat
        QCMHistory::create([
            'user_id' => $user->id,
            'score' => $request->score,
            'total_questions' => $request->total_questions
        ]);

        $total = $profile->total_qcm ?? 0;
        $avg = $profile->score_moyen ?? 0;

        $newTotal = $total + 1;
        $newAvg = (($avg * $total) + $request->score) / $newTotal;

        if ($newAvg < 50) {
            $niveau = 'debutant';
        } elseif ($newAvg < 75) {
            $niveau = 'intermediaire';
        } else {
            $niveau = 'avance';
        }

        $profile->update([
            'total_qcm' => $newTotal,
            'score_moyen' => round($newAvg, 2),
            'niveau' => $niveau,
            'points_faibles' => json_encode($request->input('wrong_answers', []))
        ]);

        return response()->json([
            'success' => true,
            'niveau' => $niveau,
            'score_moyen' => round($newAvg, 2)
        ]);
    }

    /**
     * 📜 Get QCM History for connected user
     */
    public function history()
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }
        
        $history = $user->qcmHistories()->latest()->get();
        
        return response()->json($history);
    }

    /**
     * 🤖 AI Coach (personalized)
     */
    public function aiCoach(Request $request, AIService $ai)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'coach' => '⚠️ Vous devez être connecté pour utiliser AI Coach'
                ], 401);
            }

            $profile = $user->profile;

            if (!$profile) {
                return response()->json([
                    'coach' => '⚠️ Votre profil n\'est pas encore enregistré'
                ]);
            }

            $history = $user->qcmHistories()->latest()->take(5)->get();

            $scores = $history->pluck('score')->toArray();
            $avg = $profile->score_moyen ?? 0;
            $niveau = $profile->niveau ?? 'intermediaire';
            $weakPoints = json_decode($profile->points_faibles ?? "[]", true);
            
            $weakPointsStr = !empty($weakPoints) ? implode(', ', array_slice($weakPoints, 0, 5)) : 'Aucun point faible identifié';

           $lang = $this->detectLanguage(implode(' ', $scores) . ' ' . $weakPointsStr);

$prompt = "
Langue: {$lang}

Tu es un coach IA.

Donne:
- conseil personnalisé
- plan simple
- motivation

Réponds dans la langue {$lang}.
";

            // ✅ Utiliser le service multi-IA
            $coach = $ai->ask($prompt);

            return response()->json([
                'coach' => $coach
            ]);

        } catch (\Exception $e) {
            \Log::error('AI Coach error: ' . $e->getMessage());
            
            return response()->json([
                'coach' => '💪 Conseil du moment : Continuez à pratiquer régulièrement, même 15 minutes par jour font une grande différence !'
            ], 500);
        }
    }

    /**
     * 📄 Extract text from file
     */
    private function extractTextFromFile($file)
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->path());
            return $pdf->getText();
        }

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
     * 🧹 Extract JSON from text
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
     * 🃏 Generate Flashcards (placeholder)
     */
    public function generateFlashcards(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Feature coming soon'
        ]);
    }

    /**
     * 📚 Explain Concept (placeholder)
     */
    public function explainConcept(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Feature coming soon'
        ]);
    }
}