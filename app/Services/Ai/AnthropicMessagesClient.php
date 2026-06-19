<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class AnthropicMessagesClient implements AiProviderClientInterface
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
                'model' => $model->model,
                'max_tokens' => $model->max_output_tokens,
                'system' => $instructions,
                'messages' => [
                    ['role' => 'user', 'content' => $input],
                ],
            ];

            if ($model->temperature !== null) {
                $payload['temperature'] = $model->temperature;
            }

            $response = Http::baseUrl(rtrim($provider->base_url ?: 'https://api.anthropic.com', '/'))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->post('/v1/messages', $payload);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                $message = $response->json('error.message');
                $message = is_string($message) && $message !== '' ? ": {$message}" : '';

                return new AiCompletionResult(false, latencyMs: $latency, error: "HTTP {$response->status()}{$message}");
            }

            $text = trim((string) $this->messageText($response->json('content', [])));

            return new AiCompletionResult($text !== '', $text !== '' ? $text : null, $latency, $text === '' ? 'Empty AI response.' : null);
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * @param  array<int, mixed>  $content
     */
    private function messageText(array $content): ?string
    {
        foreach (Arr::wrap($content) as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && isset($part['text']) && is_string($part['text'])) {
                return $part['text'];
            }
        }

        return null;
    }
}
