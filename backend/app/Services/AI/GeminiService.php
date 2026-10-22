<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiService implements AiProviderInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    protected string $model = 'gemini-3.8-flash';
    protected array $fallbackModels = [
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
    ];

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY', '');
        $this->model = env('GEMINI_MODEL', 'gemini-3.8-flash');
    }

    /**
     * Call the Gemini API with a given prompt.
     *
     * @param string      $prompt
     * @param string|null $userApiKey  Optional per-user key that overrides the .env key.
     * @return array|null The parsed JSON response or null on failure.
     */
    public function generateContent(string $prompt, ?string $userApiKey = null): ?array
    {
        $key = $userApiKey ?: $this->apiKey;

        if (empty($key)) {
            Log::error('Gemini API key is not configured.');
            throw new Exception('AI service is currently unavailable. Please add your Gemini API key in the API Keys settings page.');
        }

        $modelsToTry = array_values(array_unique(array_merge([$this->model], $this->fallbackModels)));
        $lastException = null;

        foreach ($modelsToTry as $currentModel) {
            $modelName = preg_replace('#^models/#', '', $currentModel);
            $url = "{$this->baseUrl}{$modelName}:generateContent?key={$key}";

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature'      => 0.7,
                    'responseMimeType' => 'application/json',
                ]
            ];

            try {
                $response = Http::timeout(30)->withoutVerifying()->post($url, $payload);

                if ($response->successful()) {
                    $data = $response->json();

                    // Extract the generated text (which should be JSON due to responseMimeType)
                    $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                    if ($textResponse) {
                        $parsed = json_decode($textResponse, true);
                        if (is_array($parsed)) {
                            return $parsed;
                        }
                    }
                } else {
                    $errorData = $response->json();
                    $errorMessage = $errorData['error']['message'] ?? 'Unknown Gemini API Error';
                    $status = $errorData['error']['status'] ?? '';

                    Log::warning("Gemini API warning with model {$modelName}", [
                        'status' => $response->status(),
                        'error'  => $errorMessage,
                    ]);

                    // If overloaded (503 UNAVAILABLE), rate limited (429), or model not found (404), fallback to next model
                    if (
                        $response->status() === 503 ||
                        $response->status() === 429 ||
                        $response->status() === 404 ||
                        $status === 'UNAVAILABLE' ||
                        str_contains(strtolower($errorMessage), 'high demand') ||
                        str_contains(strtolower($errorMessage), 'not found') ||
                        str_contains(strtolower($errorMessage), 'quota')
                    ) {
                        $lastException = new Exception($errorMessage);
                        continue;
                    }

                    throw new Exception($errorMessage);
                }
            } catch (Exception $e) {
                if ($lastException && $e->getMessage() === $lastException->getMessage()) {
                    continue;
                }
                Log::error("Gemini API Exception with model {$modelName}", ['message' => $e->getMessage()]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        throw new Exception("Failed to parse response from Gemini.");
    }
}
