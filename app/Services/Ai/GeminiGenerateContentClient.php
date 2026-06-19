<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Support\Facades\Http;

class GeminiGenerateContentClient implements AiProviderClientInterface
{
    use MeasuresAiRequests;

    public function complete(AiModel $model, string $input, string $instructions): AiCompletionResult
    {
        $provider = $model->provider;
        $apiKey = trim((string) $provider->api_key);

        if ($apiKey === '') {
            return new AiCompletionResult(false, error: 'API key is not configured.');
        }

        $startedAt = $this->startedAt();

        try {
            $payload = [
                'systemInstruction' => [
                    'parts' => [['text' => $instructions]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $input]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => $model->max_output_tokens,
                ],
            ];

            if ($model->temperature !== null) {
                $payload['generationConfig']['temperature'] = $model->temperature;
            }

            $response = Http::baseUrl(rtrim($provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/'))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->post('/models/'.$this->modelPath($model->model).':generateContent', $payload);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                $message = $response->json('error.message');
                $message = is_string($message) && $message !== '' ? ": {$message}" : '';

                return new AiCompletionResult(false, latencyMs: $latency, error: "HTTP {$response->status()}{$message}");
            }

            $text = trim((string) $response->json('candidates.0.content.parts.0.text'));

            return new AiCompletionResult($text !== '', $text !== '' ? $text : null, $latency, $text === '' ? 'Empty AI response.' : null);
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    private function modelPath(string $model): string
    {
        return rawurlencode(str($model)->after('models/')->toString());
    }
}
