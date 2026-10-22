<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GroqService implements AiProviderInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';
    protected string $model;
    protected array $fallbackModels = [
        'openai/gpt-oss-120b',
        'qwen/qwen3.8-27b',
        'openai/gpt-oss-20b',
        'llama-3.3-70b-versatile',
    ];

    public function __construct()
    {
        $this->apiKey = env('GROQ_API_KEY', '');
        $this->model = env('GROQ_MODEL', 'openai/gpt-oss-120b');
    }

    public function generateContent(string $prompt, ?string $userApiKey = null): ?array
    {
        $key = $userApiKey ?: $this->apiKey;

        if (empty($key)) {
            Log::error('Groq API key is not configured.');
            throw new Exception('AI service is currently unavailable. Please add your Groq API key in the API Keys settings page.');
        }

        // Build list of models to try: configured model first, followed by fallbacks
        $modelsToTry = array_values(array_unique(array_merge([$this->model], $this->fallbackModels)));
        $lastException = null;

        foreach ($modelsToTry as $model) {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ];

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $key,
                ])->timeout(45)->withoutVerifying()->post($this->baseUrl, $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $textResponse = $data['choices'][0]['message']['content'] ?? null;

                    if ($textResponse) {
                        $parsed = json_decode($textResponse, true);
                        if (is_array($parsed)) {
                            return $parsed;
                        }
                    }
                } else {
                    $errorData = $response->json();
                    $errorMessage = $errorData['error']['message'] ?? 'Unknown Groq API Error';

                    Log::warning("Groq API warning with model {$model}", [
                        'status' => $response->status(),
                        'error'  => $errorMessage,
                    ]);

                    // If model does not exist or account lacks access, fallback to next model
                    if ($response->status() === 404 || str_contains($errorMessage, 'does not exist') || str_contains($errorMessage, 'model_not_found')) {
                        $lastException = new Exception($errorMessage);
                        continue;
                    }

                    throw new Exception($errorMessage);
                }
            } catch (Exception $e) {
                if ($lastException && $e->getMessage() === $lastException->getMessage()) {
                    continue;
                }
                Log::error("Groq API Exception with model {$model}", ['message' => $e->getMessage()]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        throw new Exception("Failed to parse response from Groq.");
    }
}
