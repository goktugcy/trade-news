<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenAiResponsesClient implements AiProviderClientInterface
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
                'instructions' => $instructions,
                'input' => $input,
                'max_output_tokens' => $model->max_output_tokens,
                'store' => false,
            ];

            if ($model->temperature !== null) {
                $payload['temperature'] = $model->temperature;
            }

            $response = Http::baseUrl(rtrim($provider->base_url ?: $this->defaultBaseUrl(), '/'))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->post('/responses', $payload);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                return new AiCompletionResult(false, latencyMs: $latency, error: $this->errorMessage($response->status(), $response->json('error.message')));
            }

            $text = trim((string) ($response->json('output_text') ?: $this->messageText($response->json('output', []))));

            return new AiCompletionResult($text !== '', $text !== '' ? $text : null, $latency, $text === '' ? 'Empty AI response.' : null);
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    protected function errorMessage(int $status, mixed $message): string
    {
        $message = is_string($message) && $message !== '' ? ": {$message}" : '';

        return "HTTP {$status}{$message}";
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    /**
     * @param  array<int, mixed>  $output
     */
    protected function messageText(array $output): ?string
    {
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (Arr::wrap($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }

        return null;
    }
}
