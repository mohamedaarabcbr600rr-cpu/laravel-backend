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

        if ($this->groqApiKey) {
            try {
                Log::info('Tentative avec Groq...');
                return $this->askGroq($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('Groq failed: ' . $e->getMessage());
            }
        }

        if ($this->openRouterApiKey) {
            try {
                Log::info('Tentative avec OpenRouter...');
                return $this->askOpenRouter($fullPrompt);
            } catch (\Exception $e) {
                Log::warning('OpenRouter failed: ' . $e->getMessage());
            }
        }

        if ($this->geminiApiKey) {
            try {
                Log::info('Tentative avec Gemini...');
                return $this->askGemini($fullPrompt);
            } catch (\Exception $e) {
                Log::error('Gemini failed: ' . $e->getMessage());
            }
        }

        throw new \Exception('Aucun service IA disponible');
    }

    /**
     * 🔥 Lire PDF avec Gemini Vision (supporte arabe, images, etc.)
     */
    public function askGeminiWithFile($prompt, $filePath)
    {
        if (!$this->geminiApiKey) {
            throw new \Exception('Gemini API key not configured');
        }

        $fileContent = base64_encode(file_get_contents($filePath));
        $mimeType = 'application/pdf';

        $response = Http::timeout(180)->post(
"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $this->geminiApiKey,
            [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "inline_data" => [
                                    "mime_type" => $mimeType,
                                    "data" => $fileContent
                                ]
                            ],
                            [
                                "text" => $prompt
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

        if (!$response->successful()) {
            Log::error('Gemini Vision error: ' . $response->body());
            throw new \Exception("Gemini Vision API error: " . $response->status());
        }

        $result = $response->json();
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? "No response.";
    }

    /**
     * 🚀 GROQ
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
                    "content" => "You must ALWAYS follow the exact language requested in the user prompt. Never change language."
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
     * 🔀 OpenRouter
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
     * 🧠 Gemini texte
     */
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

        $result = $response->json();
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? "No response.";
    }

    /**
     * 🏗️ Construire le prompt
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
     * 🔥 Version avec mémoire
     */
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
}