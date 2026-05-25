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
        // Ajouter le contexte si présent
        $fullPrompt = $this->buildPrompt($prompt, $context);

        // Essayer Groq d'abord (le plus rapide)
        if ($this->groqApiKey) {
            try {
                Log::info('Tentative avec Groq...');
                return $this->askGroq($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('Groq failed: ' . $e->getMessage());
            }
        }

        // Fallback sur OpenRouter
        if ($this->openRouterApiKey) {
            try {
                Log::info('Tentative avec OpenRouter...');
                return $this->askOpenRouter($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('OpenRouter failed: ' . $e->getMessage());
            }
        }

        // Fallback final sur Gemini
        if ($this->geminiApiKey) {
            try {
                Log::info('Tentative avec Gemini...');
                return $this->askGemini($fullPrompt);
            } catch (\Exception $e) {
                Log::error('Gemini failed: ' . $e->getMessage());
            }
        }

        // Si toutes les IA échouent
        throw new \Exception('Aucun service IA disponible');
    }

    /**
     * 🚀 GROQ (principal - le plus rapide)
     */
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
                "content" => "You must ALWAYS follow the exact language requested in the user prompt. Never change language." .
                 " 
                IMPORTANT: Always follow the language specified in the user's prompt."
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => 0.7,
        "max_tokens" => 2000
    ]);

    if (!$response->successful()) {
        throw new \Exception("Groq API error: " . $response->status());
    }

    $result = $response->json();
    return $result['choices'][0]['message']['content'] ?? "No response";
}

    /**
     * 🔀 OpenRouter (backup - accès à plusieurs modèles)
     */
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

        $result = $response->json();
        return $result['choices'][0]['message']['content'] ?? "No response.";
    }

    /**
     * 🧠 Gemini (fallback final)
     */
    private function askGemini($prompt)
    {
        $response = Http::timeout(120)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $this->geminiApiKey,
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

        $result = $response->json();
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? "No response.";
    }

    /**
     * 🏗️ Construire le prompt avec contexte
     */
    private function buildPrompt($prompt, $context = [])
    {
        if (empty($context)) {
            return $prompt;
        }

        $contextStr = "Contexte:\n";
        foreach ($context as $key => $value) {
            $contextStr .= "- $key: $value\n";
        }

        return $contextStr . "\n\nQuestion: " . $prompt;
    }

    /**
     * 🔥 Version avec mémoire (conversation)
     */
    public function askWithHistory($messages)
    {
        // Pour Groq
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

        // Fallback: utiliser la méthode normale
        $lastMessage = end($messages);
        return $this->ask($lastMessage['content']);
    }
}