<?php

namespace App\Http\Controllers;

use App\Models\StudyMaterial;
use App\Models\StudyPlan;
use App\Models\FocusTask;
use App\Models\FocusSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AIStudyController extends Controller
{
    /**
     * 1. Upload document + extraction AI
     */
    public function upload(Request $request)
    {
        $request->validate([
            'subject' => 'required|string',
            'title'   => 'required|string',
            'file'    => 'nullable|file|mimes:pdf,txt|max:10240',
            'text'    => 'nullable|string'
        ]);

        $filePath      = null;
        $extractedText = $request->text;

        if ($request->hasFile('file')) {
            $filePath      = $request->file('file')->store('study_materials', 'public');
            $extractedText = $this->extractTextFromFile($request->file('file'));
        }

        // IMPROVED: reject upload if we ended up with no usable text at all
        if (empty(trim($extractedText ?? ''))) {
            return response()->json(['message' => 'Could not extract text from the file.'], 422);
        }

        $material = StudyMaterial::create([
            'user_id'        => auth()->id(),
            'subject'        => $request->subject,
            'title'          => $request->title,
            'file_path'      => $filePath,
            'extracted_text' => $extractedText,
        ]);

        return response()->json([
            'message'  => 'Document uploaded successfully',
            'material' => $material,
        ]);
    }

    /**
     * 2. Générer plan d'étude avec AI (Grok API)
     */
    public function generatePlan($materialId)
    {
        $material = StudyMaterial::where('user_id', auth()->id())
            ->findOrFail($materialId);

        $planData = $this->callAIToGeneratePlan($material);

        $studyPlan = StudyPlan::create([
            'study_material_id' => $material->id,
            'plan_data'         => $planData,
        ]);

        $tasks = [];
        foreach ($planData['tasks'] as $task) {
            $tasks[] = FocusTask::create([
                'study_plan_id' => $studyPlan->id,
                'description'   => $task['description'],
                'duration'      => $task['duration'],
                'order_index'   => $task['order'],
                // IMPROVED: store the per-task AI tip so the frontend can display it immediately
                'ai_tip'        => $task['tip'] ?? null,
                'status'        => 'pending',
            ]);
        }

        return response()->json([
            'plan'    => $studyPlan,
            'tasks'   => $tasks,
            // IMPROVED: also return the plan summary for StudyPlanView
            'summary' => $planData['summary'] ?? null,
            'ai_used' => env('GROK_API_KEY') ? 'grok' : 'local',
        ]);
    }

    /**
     * 3. Démarrer une session focus
     */
    public function startSession(Request $request)
    {
        $request->validate([
            'study_material_id' => 'required|exists:study_materials,id',
        ]);

        $session = FocusSession::create([
            'user_id'           => auth()->id(),
            'study_material_id' => $request->study_material_id,
            'started_at'        => now(),
            'focus_score'       => 0,
        ]);

        return response()->json([
            'session' => $session,
            'message' => 'Focus session started',
        ]);
    }

    /**
     * 4. Récupérer la tâche courante
     */
    public function currentTask($sessionId)
    {
        $session = FocusSession::where('user_id', auth()->id())
            ->findOrFail($sessionId);

        $studyPlan = StudyPlan::where('study_material_id', $session->study_material_id)
            ->firstOrFail();

        $currentTask = FocusTask::where('study_plan_id', $studyPlan->id)
            ->where('status', 'pending')
            ->orderBy('order_index')
            ->first();

        $nextTask = FocusTask::where('study_plan_id', $studyPlan->id)
            ->where('status', 'pending')
            ->where('order_index', '>', $currentTask->order_index ?? 0)
            ->first();

        // IMPROVED: use the tip that was already generated at plan-creation time;
        // only call the API again if it wasn't stored (e.g. old records).
        $aiTip = $currentTask->ai_tip ?? $this->generateAITip($currentTask, $session->studyMaterial);

        return response()->json([
            'current_task' => $currentTask,
            'has_next_task' => !is_null($nextTask),
            'ai_tip'        => $aiTip,
            'progress'      => [
                'completed' => FocusTask::where('study_plan_id', $studyPlan->id)->where('status', 'completed')->count(),
                'total'     => FocusTask::where('study_plan_id', $studyPlan->id)->count(),
            ],
        ]);
    }

    /**
     * 5. Terminer une tâche
     */
    public function completeTask(Request $request)
    {
        $request->validate([
            'task_id' => 'required|exists:focus_tasks,id',
        ]);

        $task = FocusTask::findOrFail($request->task_id);
        $task->update(['status' => 'completed']);

        $allTasks     = FocusTask::where('study_plan_id', $task->study_plan_id)->get();
        $allCompleted = $allTasks->every(fn($t) => $t->status === 'completed');

        return response()->json([
            'message'       => 'Task completed',
            'all_completed' => $allCompleted,
        ]);
    }

    /**
     * 6. Générer mini review avec AI
     */
    public function generateReview($sessionId)
    {
        $session  = FocusSession::where('user_id', auth()->id())->findOrFail($sessionId);
        $material = StudyMaterial::find($session->study_material_id);

        $questions = $this->callAIToGenerateQuestions($material);

        return response()->json([
            'session_id' => $session->id,
            'subject'    => $material->subject,
            'title'      => $material->title,
            'questions'  => $questions,
            'ai_used'    => env('GROK_API_KEY') ? 'grok' : 'local',
        ]);
    }

    /**
     * 7. Finaliser session avec score
     *
     * IMPROVED: accepts student answers and grades them with AI instead of a random score.
     */
    public function finalizeSession(Request $request, $sessionId)
    {
        $request->validate([
            'focus_score' => 'nullable|integer|min:0|max:100',
            'weak_points' => 'nullable|array',
            // new optional fields
            'answers'     => 'nullable|array',
            'questions'   => 'nullable|array',
        ]);

        $session = FocusSession::where('user_id', auth()->id())->findOrFail($sessionId);

        // IMPROVED: if raw answers are provided, grade them with AI
        $score      = $request->focus_score ?? 0;
        $weakPoints = $request->weak_points ?? [];
        $strengths  = [];

        if ($request->filled('answers') && $request->filled('questions')) {
            $material    = StudyMaterial::find($session->study_material_id);
            $gradeResult = $this->callAIToGradeAnswers(
                $material,
                $request->questions,
                $request->answers
            );
            $score      = $gradeResult['score'];
            $weakPoints = $gradeResult['weak_points'];
            $strengths  = $gradeResult['strengths'];
        }

        $session->update([
            'ended_at'    => now(),
            'focus_score' => $score,
            'weak_points' => $weakPoints,
        ]);

        return response()->json([
            'message'   => 'Session finalized',
            'session'   => $session,
            'score'     => $score,
            'strengths' => $strengths,
            'weak_points' => $weakPoints,
        ]);
    }

    /**
     * 8. Récupérer historique des sessions
     */
    public function history()
    {
        $sessions = FocusSession::where('user_id', auth()->id())
            ->with('studyMaterial')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($sessions);
    }

    // ==================== MÉTHODES PRIVÉES POUR AI ====================

    /**
     * 🔥 Appel à l'API Grok (xAI) pour générer un plan d'étude
     *
     * IMPROVED:
     *  - prompt now asks for a "summary" field and a per-task "tip"
     *  - sends up to 12 000 chars of content (was 8 000)
     *  - temp lowered to 0.5 for more focused output
     */
    private function callAIToGeneratePlan($material)
    {
        $apiKey = env('GROK_API_KEY');
        $useAI  = $apiKey && env('USE_AI_API', true);

        if ($useAI) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(60)->post('https://api.x.ai/v1/chat/completions', [
                    'model'       => 'grok-beta',
                    'temperature' => 0.5,
                    'max_tokens'  => 2500,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => "Tu es un expert en pédagogie spécialisé dans la création de plans d'étude personnalisés.
Analyse le contenu fourni et crée un plan d'étude détaillé et adapté au contenu réel.
Réponds UNIQUEMENT au format JSON suivant, sans aucun texte avant ou après:
{
    \"summary\": \"Résumé du contenu en 2-3 phrases\",
    \"tasks\": [
        {
            \"description\": \"Description précise basée sur le vrai contenu\",
            \"duration\": 20,
            \"order\": 1,
            \"tip\": \"Conseil d'étude court et pratique pour cette tâche\"
        }
    ]
}
Règles:
- Génère entre 4 et 6 tâches
- Durées entre 10 et 35 minutes
- Les descriptions DOIVENT être spécifiques au contenu fourni, pas génériques
- Chaque tip est unique et adapté à la tâche",
                        ],
                        [
                            'role'    => 'user',
                            'content' => "Matière: " . $material->subject
                                . "\nTitre: " . $material->title
                                . "\n\nContenu:\n" . substr($material->extracted_text, 0, 12000),
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $content = $response->json()['choices'][0]['message']['content'];
                    Log::info('Grok plan response', ['preview' => substr($content, 0, 200)]);

                    preg_match('/\{[\s\S]*\}/', $content, $matches);
                    if (isset($matches[0])) {
                        $planData = json_decode($matches[0], true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($planData['tasks'])) {
                            return $planData;
                        }
                    }
                } else {
                    Log::error('Grok plan error', ['status' => $response->status(), 'body' => $response->body()]);
                }
            } catch (\Exception $e) {
                Log::error('Grok plan exception: ' . $e->getMessage());
            }
        }

        return $this->generateLocalPlan($material);
    }

    /**
     * 🔥 Appel à l'API Grok pour générer des questions de révision
     *
     * IMPROVED:
     *  - questions now include a "hint" field for the student
     *  - difficulty is explicitly labeled (easy / medium / hard)
     *  - sends up to 6 000 chars (was 4 000)
     */
    private function callAIToGenerateQuestions($material)
    {
        $apiKey = env('GROK_API_KEY');
        $useAI  = $apiKey && env('USE_AI_API', true);

        if ($useAI) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(60)->post('https://api.x.ai/v1/chat/completions', [
                    'model'       => 'grok-beta',
                    'temperature' => 0.7,
                    'max_tokens'  => 1200,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => "Tu es un professeur expert qui crée des questions de révision pertinentes.
Génère exactement 3 questions basées sur le vrai contenu fourni.
Réponds UNIQUEMENT au format JSON suivant, sans aucun texte avant ou après:
[
    {\"question\": \"Question précise sur le contenu\", \"hint\": \"Indice court\", \"difficulty\": \"easy\"},
    {\"question\": \"...\", \"hint\": \"...\", \"difficulty\": \"medium\"},
    {\"question\": \"...\", \"hint\": \"...\", \"difficulty\": \"hard\"}
]
Les questions doivent couvrir les points clés du contenu, de difficulté progressive.",
                        ],
                        [
                            'role'    => 'user',
                            'content' => "Matière: " . $material->subject
                                . "\nTitre: " . $material->title
                                . "\n\nContenu:\n" . substr($material->extracted_text, 0, 6000),
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $content = $response->json()['choices'][0]['message']['content'];
                    Log::info('Grok questions response', ['preview' => substr($content, 0, 200)]);

                    preg_match('/\[[\s\S]*\]/', $content, $matches);
                    if (isset($matches[0])) {
                        $questions = json_decode($matches[0], true);
                        if (json_last_error() === JSON_ERROR_NONE && count($questions) >= 2) {
                            return $questions;
                        }
                    }
                } else {
                    Log::error('Grok questions error', ['status' => $response->status(), 'body' => $response->body()]);
                }
            } catch (\Exception $e) {
                Log::error('Grok questions exception: ' . $e->getMessage());
            }
        }

        // FALLBACK
        return [
            ['question' => 'Quel est le concept principal de ce chapitre?',          'hint' => 'Pense au thème central',         'difficulty' => 'easy'],
            ['question' => 'Peux-tu expliquer ce sujet avec tes propres mots?',       'hint' => 'Résume les points clés',         'difficulty' => 'medium'],
            ['question' => 'Quelle est l\'application pratique de ce que tu as appris?', 'hint' => 'Donne un exemple concret',    'difficulty' => 'hard'],
        ];
    }

    /**
     * 🔥 NEW — Grade student answers with AI and return score + feedback
     */
    private function callAIToGradeAnswers($material, array $questions, array $answers)
    {
        $apiKey = env('GROK_API_KEY');

        $qa = '';
        foreach ($questions as $i => $q) {
            $answer = $answers[$i] ?? '(pas de réponse)';
            $qa .= "Q" . ($i + 1) . ": " . (is_array($q) ? $q['question'] : $q) . "\n";
            $qa .= "Réponse: " . $answer . "\n\n";
        }

        if ($apiKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(60)->post('https://api.x.ai/v1/chat/completions', [
                    'model'       => 'grok-beta',
                    'temperature' => 0.4,
                    'max_tokens'  => 800,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => "Tu es un professeur bienveillant qui corrige des réponses d'élèves.
Évalue les réponses par rapport au contenu du cours.
Réponds UNIQUEMENT au format JSON suivant:
{
    \"score\": 85,
    \"strengths\": [\"Point fort 1\", \"Point fort 2\"],
    \"weak_points\": [\"Point à améliorer 1\", \"Point à améliorer 2\"],
    \"encouragement\": \"Message d'encouragement personnalisé\"
}
Score sur 100. Sois juste et constructif.",
                        ],
                        [
                            'role'    => 'user',
                            'content' => "Matière: " . $material->subject
                                . "\nTitre: " . $material->title
                                . "\n\nContenu de référence:\n" . substr($material->extracted_text, 0, 3000)
                                . "\n\nRéponses de l'élève:\n" . $qa,
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $content = $response->json()['choices'][0]['message']['content'];
                    preg_match('/\{[\s\S]*\}/', $content, $matches);
                    if (isset($matches[0])) {
                        $result = json_decode($matches[0], true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($result['score'])) {
                            return $result;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Grok grading exception: ' . $e->getMessage());
            }
        }

        // FALLBACK: simple heuristic — reward non-empty answers
        $answered   = count(array_filter($answers, fn($a) => strlen(trim($a)) > 20));
        $total      = max(count($questions), 1);
        $score      = (int) round(($answered / $total) * 100);

        return [
            'score'         => $score,
            'strengths'     => $score >= 60 ? ['Bonne participation', 'Efforts visibles'] : ['Tu as essayé'],
            'weak_points'   => $score < 80  ? ['Approfondir les concepts clés', 'Relire le contenu'] : [],
            'encouragement' => $score >= 80 ? 'Excellent travail, continue comme ça !' : 'Continue à pratiquer, tu vas progresser !',
        ];
    }

    /**
     * 🔥 Générer un AI Tip intelligent (avec Grok si disponible)
     */
    private function generateAITip($task, $material)
    {
        $apiKey = env('GROK_API_KEY');

        if ($apiKey && $task) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(30)->post('https://api.x.ai/v1/chat/completions', [
                    'model'       => 'grok-beta',
                    'temperature' => 0.6,
                    'max_tokens'  => 100,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => "Tu es un coach pédagogique. Donne un conseil d'étude COURT et PRATIQUE (maximum 120 caractères) adapté à la tâche.",
                        ],
                        [
                            'role'    => 'user',
                            'content' => "Matière: {$material->subject}\nTâche: {$task->description}\n\nConseil:",
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $tip = trim($response->json()['choices'][0]['message']['content']);
                    if (strlen($tip) > 10) {
                        return substr($tip, 0, 150);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Grok tip exception: ' . $e->getMessage());
            }
        }

        $defaultTips = [
            '📚 Commence par comprendre la théorie avant les exercices.',
            '🧠 Utilise la technique Pomodoro : 25 min focus, 5 min pause.',
            '✍️ Prends des notes manuscrites pour mieux mémoriser.',
            '🔄 Relis ce que tu as appris après 10 minutes.',
            '🎯 Concentre-toi sur un seul concept à la fois.',
            '💪 Fais des pauses régulières pour rester concentré.',
            '📖 Explique le concept à voix haute comme si tu enseignais.',
            '🔁 La répétition espacée est la clé de la mémorisation.',
        ];

        return $defaultTips[array_rand($defaultTips)];
    }

    /**
     * Plan d'étude local (fallback)
     */
    private function generateLocalPlan($material)
    {
        $subject = $material->subject;

        $plans = [
            'maths' => [
                'summary' => 'Plan mathématiques : théorie, formules, exercices et correction.',
                'tasks'   => [
                    ['description' => '📖 Lire la théorie et les définitions',          'duration' => 20, 'order' => 1, 'tip' => 'Surligne les définitions clés.'],
                    ['description' => '📝 Étudier les formules et démonstrations',      'duration' => 15, 'order' => 2, 'tip' => 'Recopie les formules de mémoire.'],
                    ['description' => '✏️ Faire les exercices d\'application',          'duration' => 15, 'order' => 3, 'tip' => 'Essaie sans regarder le cours d\'abord.'],
                    ['description' => '🔍 Vérifier les réponses et corriger les erreurs','duration' => 10, 'order' => 4, 'tip' => 'Comprends chaque erreur avant de continuer.'],
                ],
            ],
            'physics' => [
                'summary' => 'Plan physique : concepts, lois, problèmes types et analyse.',
                'tasks'   => [
                    ['description' => '🔬 Comprendre les concepts physiques',  'duration' => 20, 'order' => 1, 'tip' => 'Fais un schéma pour chaque concept.'],
                    ['description' => '📐 Étudier les lois et équations',      'duration' => 15, 'order' => 2, 'tip' => 'Note les unités de chaque grandeur.'],
                    ['description' => '⚡ Résoudre les problèmes types',       'duration' => 15, 'order' => 3, 'tip' => 'Pose toujours les données connues d\'abord.'],
                    ['description' => '📊 Analyser les graphiques et résultats','duration' => 10, 'order' => 4, 'tip' => 'Identifie les tendances et anomalies.'],
                ],
            ],
            'svt' => [
                'summary' => 'Plan SVT : concepts biologiques, schémas, terminologie et résumé.',
                'tasks'   => [
                    ['description' => '🧬 Lire et comprendre les concepts biologiques', 'duration' => 20, 'order' => 1, 'tip' => 'Associe chaque concept à un exemple réel.'],
                    ['description' => '🔬 Étudier les schémas et illustrations',        'duration' => 15, 'order' => 2, 'tip' => 'Reproduis les schémas sans regarder.'],
                    ['description' => '📝 Apprendre la terminologie spécifique',        'duration' => 15, 'order' => 3, 'tip' => 'Crée des flashcards pour les termes clés.'],
                    ['description' => '🔄 Faire un résumé des points clés',             'duration' => 10, 'order' => 4, 'tip' => 'Résume en 5 phrases max.'],
                ],
            ],
            'programming' => [
                'summary' => 'Plan programmation : concepts, code, tests et mini-projet.',
                'tasks'   => [
                    ['description' => '💻 Lire la documentation et les concepts', 'duration' => 15, 'order' => 1, 'tip' => 'Note les fonctions/méthodes importantes.'],
                    ['description' => '🔧 Écrire le code d\'exemple',             'duration' => 20, 'order' => 2, 'tip' => 'Tape le code toi-même, ne copie pas.'],
                    ['description' => '🐛 Tester et déboguer',                    'duration' => 15, 'order' => 3, 'tip' => 'Lis les messages d\'erreur attentivement.'],
                    ['description' => '📦 Créer un petit projet pratique',        'duration' => 10, 'order' => 4, 'tip' => 'Applique ce que tu viens d\'apprendre.'],
                ],
            ],
            'default' => [
                'summary' => 'Plan général : lecture, prise de notes, révision et quiz.',
                'tasks'   => [
                    ['description' => '📖 Lire et comprendre le contenu', 'duration' => 20, 'order' => 1, 'tip' => 'Lis activement, pose des questions.'],
                    ['description' => '✍️ Prendre des notes importantes', 'duration' => 15, 'order' => 2, 'tip' => 'Reformule avec tes propres mots.'],
                    ['description' => '🔄 Réviser et résumer',            'duration' => 15, 'order' => 3, 'tip' => 'Ferme le cours et récite de mémoire.'],
                    ['description' => '✅ Faire un mini quiz',            'duration' => 10, 'order' => 4, 'tip' => 'Sois honnête sur ce que tu ne sais pas.'],
                ],
            ],
        ];

        return $plans[$subject] ?? $plans['default'];
    }

    /**
     * Extraire le texte d'un fichier (PDF, TXT)
     *
     * IMPROVED:
     *  - TXT: detects and converts encoding to UTF-8 so Arabic/French chars don't break
     *  - PDF: uses spatie/pdf-to-text when available; falls back gracefully with a clear message
     */
    private function extractTextFromFile($file)
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'txt') {
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) return 'Contenu non lisible.';

            // Detect encoding and convert to UTF-8
            $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'CP1256'], true);
            return $encoding && $encoding !== 'UTF-8'
                ? mb_convert_encoding($raw, 'UTF-8', $encoding)
                : $raw;
        }

        if ($extension === 'pdf') {
            // Use spatie/pdf-to-text if installed: composer require spatie/pdf-to-text
            if (class_exists(\Spatie\PdfToText\Pdf::class)) {
                try {
                    $text = \Spatie\PdfToText\Pdf::getText($file->getPathname());
                    if (!empty(trim($text))) return $text;
                } catch (\Exception $e) {
                    Log::warning('spatie/pdf-to-text failed: ' . $e->getMessage());
                }
            }

            // Fallback: store the file and inform AI it's a PDF (AI prompt will note this)
            return "📄 Fichier PDF: " . $file->getClientOriginalName()
                . "\n\n[Pour extraire le texte automatiquement, exécute: composer require spatie/pdf-to-text]";
        }

        return 'Format non supporté.';
    }
}