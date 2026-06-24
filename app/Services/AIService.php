<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    private $groqApiKey;
    private $openRouterApiKey;
    private $geminiApiKey;

    public function __construct()
    {
        $this->groqApiKey = env('GROQ_API_KEY');
        $this->openRouterApiKey = env('OPENROUTER_API_KEY');
        $this->geminiApiKey = env('GEMINI_API_KEY');
    }

    /**
     * 🔥 Méthode principale avec fallback automatique
     */
    public function ask($prompt, $context = [])
    {
        $fullPrompt = $this->buildPrompt($prompt, $context);

        // Groq en priorité pour le texte (rapide + gratuit)
        if ($this->groqApiKey) {
            try {
                Log::info('Groq (Llama 3.3 70B)...');
                return $this->askGroq($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('Groq failed: ' . $e->getMessage());
            }
        }

        // Gemini pour texte
        if ($this->geminiApiKey) {
            try {
                Log::info('Gemini 2.0 Flash (texte)...');
                return $this->askGemini($fullPrompt);
            } catch (\Exception $e) {
                Log::error('Gemini failed: ' . $e->getMessage());
            }
        }

        // OpenRouter fallback
        if ($this->openRouterApiKey) {
            try {
                Log::info('OpenRouter (Mistral)...');
                return $this->askOpenRouter($fullPrompt);
            } catch (\Exception $e) {
                Log::error('OpenRouter failed: ' . $e->getMessage());
            }
        }

        throw new \Exception('Aucun service IA disponible');
    }

    /**
     * 📄 Lire UN FICHIER (PDF/Image) - OPTIMISÉ PRODUCTION
     * 
     * ✅ Gemini en priorité : Lit PDFs natifs + images
     * ✅ OpenRouter Qwen en fallback pour images
     */
    public function askWithFile($prompt, $filePath)
    {
        $mimeType = mime_content_type($filePath);
        $fileSize = filesize($filePath);
        
        Log::info("askWithFile: type={$mimeType}, size={$fileSize} bytes");
        
        // 📄 PDF → GEMINI DIRECTEMENT (le SEUL qui lit les PDFs nativement)
        if ($mimeType === 'application/pdf') {
            return $this->readPdfWithGemini($prompt, $filePath, $fileSize);
        }
        
        // 🖼️ IMAGE → GEMINI ou OPENROUTER QWEN
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            return $this->readImageWithVision($prompt, $filePath, $mimeType);
        }
        
        throw new \Exception("Format non supporté: {$mimeType}. Utilisez PDF, JPG, PNG.");
    }

    /**
     * 📄 LIRE PDF AVEC GEMINI (NATIF - AUCUNE CONVERSION)
     */
    private function readPdfWithGemini($prompt, $filePath, $fileSize)
    {
        if (!$this->geminiApiKey) {
            // Si pas de Gemini, essayer avec OpenRouter (va échouer pour PDF natif)
            Log::warning('Gemini API key manquante, tentative OpenRouter...');
            return $this->readPdfFallbackOpenRouter($prompt, $filePath);
        }
        
        // Vérifier la taille du PDF
        if ($fileSize > 20 * 1024 * 1024) { // 20MB max pour Gemini
            Log::warning('PDF trop volumineux (>20MB), compression suggérée');
            // Tenter quand même
        }
        
        // Lire le PDF en base64
        $fileContent = base64_encode(file_get_contents($filePath));
        
        try {
            Log::info('Envoi PDF à Gemini 2.0 Flash...');
            
            $response = Http::timeout(180)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $this->geminiApiKey,
                [
                    "contents" => [
                        [
                            "parts" => [
                                [
                                    "text" => $prompt  // ⚠️ TOUJOURS le texte en premier
                                ],
                                [
                                    "inline_data" => [
                                        "mime_type" => "application/pdf",
                                        "data" => $fileContent
                                    ]
                                ]
                            ]
                        ]
                    ],
                    "generationConfig" => [
                        "temperature" => 0.3,
                        "maxOutputTokens" => 8192  // Assez pour 20 QCM
                    ]
                ]
            );
            
            if (!$response->successful()) {
                Log::error('Gemini PDF error: ' . $response->body());
                throw new \Exception("Gemini a refusé le PDF: " . $response->status());
            }
            
            $result = $response->json();
            
            // Vérifier la réponse
            if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                Log::error('Gemini PDF: réponse invalide', ['result' => $result]);
                
                // Vérifier si PDF bloqué par sécurité
                if (isset($result['candidates'][0]['finishReason']) && 
                    $result['candidates'][0]['finishReason'] === 'SAFETY') {
                    throw new \Exception("PDF bloqué par le filtre de sécurité Google");
                }
                
                throw new \Exception("Gemini n'a pas pu lire ce PDF (peut-être corrompu ou protégé)");
            }
            
            return $result['candidates'][0]['content']['parts'][0]['text'];
            
        } catch (\Exception $e) {
            Log::error('Gemini PDF exception: ' . $e->getMessage());
            
            // Tenter OpenRouter en dernier recours
            if ($this->openRouterApiKey) {
                Log::info('Fallback: OpenRouter pour PDF...');
                return $this->readPdfFallbackOpenRouter($prompt, $filePath);
            }
            
            throw $e;
        }
    }

    /**
     * 🔄 FALLBACK: OpenRouter pour PDF (conversion légère via Qwen)
     * Note: Qwen ne lit pas les PDFs natifs, on convertit via l'API elle-même
     */
    private function readPdfFallbackOpenRouter($prompt, $filePath)
    {
        if (!$this->openRouterApiKey) {
            throw new \Exception('Aucun service Vision disponible. Ajoutez une clé Gemini API (gratuit).');
        }
        
        // Qwen VL peut essayer de lire le PDF comme "image" brute
        // Solution: envoyer le PDF comme application/pdf (Qwen le rejette souvent)
        // Alternative: utiliser un service tiers gratuit de conversion PDF->Image
        
        Log::warning('Tentative OpenRouter pour PDF (peut échouer)...');
        
        $fileContent = base64_encode(file_get_contents($filePath));
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->openRouterApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(180)->post('https://openrouter.ai/api/v1/chat/completions', [
                "model" => "qwen/qwen2.5-vl-72b-instruct:free",
                "messages" => [
                    [
                        "role" => "user",
                        "content" => [
                            ["type" => "text", "text" => $prompt],
                            [
                                "type" => "image_url",
                                "image_url" => [
                                    "url" => "data:application/pdf;base64,{$fileContent}"
                                ]
                            ]
                        ]
                    ]
                ],
                "temperature" => 0.3,
                "max_tokens"  => 4000
            ]);
            
            if ($response->successful()) {
                $result = $response->json();
                return $result['choices'][0]['message']['content'] ?? "No response.";
            }
            
            throw new \Exception("OpenRouter ne supporte pas les PDFs directement");
            
        } catch (\Exception $e) {
            Log::error('OpenRouter PDF fallback failed: ' . $e->getMessage());
            throw new \Exception(
                "Impossible de lire ce PDF. " .
                "Solutions: 1) Ajoutez une clé Gemini API (gratuit, lit les PDFs nativement) " .
                "2) Convertissez le PDF en images manuellement"
            );
        }
    }

    /**
     * 🖼️ LIRE IMAGE (Gemini ou OpenRouter Qwen)
     */
    private function readImageWithVision($prompt, $filePath, $mimeType)
    {
        $fileContent = base64_encode(file_get_contents($filePath));
        
        // Essayer Gemini d'abord (meilleur pour la 3rbiya)
        if ($this->geminiApiKey) {
            try {
                Log::info('Envoi image à Gemini...');
                
                $response = Http::timeout(180)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $this->geminiApiKey,
                    [
                        "contents" => [
                            [
                                "parts" => [
                                    ["text" => $prompt],
                                    [
                                        "inline_data" => [
                                            "mime_type" => $mimeType,
                                            "data" => $fileContent
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        "generationConfig" => [
                            "temperature" => 0.3,
                            "maxOutputTokens" => 4000
                        ]
                    ]
                );
                
                if ($response->successful()) {
                    $result = $response->json();
                    return $result['candidates'][0]['content']['parts'][0]['text'] ?? "No response.";
                }
                
                Log::warning('Gemini image failed, trying OpenRouter...');
                
            } catch (\Exception $e) {
                Log::warning('Gemini image exception: ' . $e->getMessage());
            }
        }
        
        // Fallback: OpenRouter Qwen
        if ($this->openRouterApiKey) {
            try {
                Log::info('Envoi image à OpenRouter Qwen...');
                
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->openRouterApiKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(180)->post('https://openrouter.ai/api/v1/chat/completions', [
                    "model" => "qwen/qwen2.5-vl-72b-instruct:free",
                    "messages" => [
                        [
                            "role" => "user",
                            "content" => [
                                ["type" => "text", "text" => $prompt],
                                [
                                    "type" => "image_url",
                                    "image_url" => [
                                        "url" => "data:{$mimeType};base64,{$fileContent}"
                                    ]
                                ]
                            ]
                        ]
                    ],
                    "temperature" => 0.3,
                    "max_tokens"  => 4000
                ]);
                
                if ($response->successful()) {
                    $result = $response->json();
                    return $result['choices'][0]['message']['content'] ?? "No response.";
                }
                
                throw new \Exception("OpenRouter Vision error: " . $response->status());
                
            } catch (\Exception $e) {
                Log::error('OpenRouter image error: ' . $e->getMessage());
                throw $e;
            }
        }
        
        throw new \Exception('Aucun service Vision disponible');
    }

    // ==========================================
    // MÉTHODES EXISTANTES (INCHANGÉES)
    // ==========================================
    
    private function askGroq($prompt)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->groqApiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.groq.com/openai/v1/chat/completions', [
            "model" => "llama-3.3-70b-versatile",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "You must ALWAYS follow the exact language requested in the user prompt. Never change language."
                ],
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7,
            "max_tokens" => 2000
        ]);

        if (!$response->successful()) {
            throw new \Exception("Groq API error: " . $response->status());
        }

        return $response['choices'][0]['message']['content'] ?? "No response";
    }

    private function askOpenRouter($prompt)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->openRouterApiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://openrouter.ai/api/v1/chat/completions', [
            "model" => "mistralai/mistral-7b-instruct:free",
            "messages" => [
                ["role" => "system", "content" => "You are an AI assistant. Follow the language in the user prompt."],
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7,
            "max_tokens" => 2000
        ]);

        if (!$response->successful()) {
            throw new \Exception("OpenRouter API error: " . $response->status());
        }

        return $response['choices'][0]['message']['content'] ?? "No response.";
    }

    private function askGemini($prompt)
    {
        $response = Http::timeout(120)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $this->geminiApiKey,
            [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 0.7,
                    "maxOutputTokens" => 2000
                ]
            ]
        );

        if (!$response->successful()) {
            throw new \Exception("Gemini API error: " . $response->status());
        }

        return $response['candidates'][0]['content']['parts'][0]['text'] ?? "No response.";
    }

    private function buildPrompt($prompt, $context = [])
    {
        if (empty($context)) return $prompt;
        
        $contextStr = "Contexte:\n";
        foreach ($context as $key => $value) {
            $contextStr .= "- $key: $value\n";
        }
        return $contextStr . "\n\nQuestion: " . $prompt;
    }

    public function askWithHistory($messages)
    {
        if ($this->groqApiKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->groqApiKey,
                ])->timeout(120)->post('https://api.groq.com/openai/v1/chat/completions', [
                    "model" => "llama-3.3-70b-versatile",
                    "messages" => $messages,
                    "temperature" => 0.7
                ]);

                if ($response->successful()) {
                    return $response['choices'][0]['message']['content'];
                }
            } catch (\Exception $e) {
                Log::warning('Groq history failed: ' . $e->getMessage());
            }
        }

        $lastMessage = end($messages);
        return $this->ask($lastMessage['content']);
    }
    
    /**
     * 📦 Méthode publique pour lire PDF avec Gemini (utilisée dans le contrôleur si besoin)
     */
    public function askGeminiWithFile($prompt, $filePath)
    {
        return $this->readPdfWithGemini($prompt, $filePath, filesize($filePath));
    }
    
    /**
     * 📦 OpenRouter Vision pour fichiers simples (compatibilité avec l'ancien code)
     */
    public function askWithFileViaOpenRouter($prompt, $filePath, $mimeType = 'image/jpeg')
    {
        return $this->readImageWithVision($prompt, $filePath, $mimeType);
    }
}