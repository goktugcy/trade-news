<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for Hugging Face dedicated (Inference Endpoints) deployments.
 *
 * - openai_chat runtime → {endpoint}/v1/chat/completions (OpenAI-compatible)
 * - hf_* pipeline runtimes → POST {endpoint} with {"inputs": ..., "parameters": ...}
 *
 * The provider API key is sent as a Bearer token.
 */
class HuggingFaceEndpointClient implements AiProviderClientInterface
{
    use MeasuresAiRequests;

    /**
     * Chat completion (openai_chat runtime). Used for summary + stock analysis.
     */
    public function complete(AiModel $model, string $input, string $instructions): AiCompletionResult
    {
        $endpoint = $model->resolvedEndpoint();
        $auth = $this->guard($model, $endpoint);

        if ($auth !== null) {
            return $auth;
        }

        $startedAt = $this->startedAt();

        try {
            $payload = [
                'model' => $model->model,
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $input],
                ],
                'max_tokens' => $model->max_output_tokens,
                'stream' => false,
            ];

            if ($model->temperature !== null) {
                $payload['temperature'] = $model->temperature;
            }

            $response = $this->request($model)
                ->post($this->chatCompletionsUrl((string) $endpoint), $payload);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                return new AiCompletionResult(false, latencyMs: $latency, error: $this->errorMessage($response->status(), $response->json('error.message') ?? $response->json('error')));
            }

            $text = trim((string) $response->json('choices.0.message.content'));

            return new AiCompletionResult($text !== '', $text !== '' ? $text : null, $latency, $text === '' ? 'Empty AI response.' : null);
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Text classification (hf_text_classification). Returns normalized scores.
     */
    public function classify(AiModel $model, string $input): AiCompletionResult
    {
        return $this->pipeline($model, ['inputs' => $input], function (array $data): array {
            // HF returns [[{label,score}...]] or [{label,score}...].
            $rows = (isset($data[0]) && is_array($data[0]) && ! array_key_exists('label', $data[0])) ? $data[0] : $data;

            $scores = [];
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['label'])) {
                    $scores[] = ['label' => (string) $row['label'], 'score' => (float) ($row['score'] ?? 0.0)];
                }
            }

            return ['scores' => $scores];
        });
    }

    /**
     * Token classification / NER (hf_token_classification). Returns entities.
     */
    public function tokenClassify(AiModel $model, string $input): AiCompletionResult
    {
        return $this->pipeline($model, ['inputs' => $input], function (array $data): array {
            $rows = (isset($data[0]) && is_array($data[0]) && ! array_key_exists('word', $data[0]) && ! array_key_exists('entity_group', $data[0])) ? $data[0] : $data;

            $entities = [];
            foreach ($rows as $row) {
                if (is_array($row) && (isset($row['word']) || isset($row['entity_group']) || isset($row['entity']))) {
                    $entities[] = [
                        'entity_group' => (string) ($row['entity_group'] ?? $row['entity'] ?? ''),
                        'word' => (string) ($row['word'] ?? ''),
                        'score' => (float) ($row['score'] ?? 0.0),
                        'start' => $row['start'] ?? null,
                        'end' => $row['end'] ?? null,
                    ];
                }
            }

            return ['entities' => $entities];
        });
    }

    /**
     * Feature extraction / embeddings (hf_feature_extraction). Returns a vector.
     */
    public function featureExtract(AiModel $model, string $input): AiCompletionResult
    {
        return $this->pipeline($model, ['inputs' => $input], function (array $data): array {
            return ['embedding' => $this->flattenEmbedding($data)];
        });
    }

    /**
     * Reranking (hf_ranking). Hugging Face pipeline endpoints expect an inputs
     * envelope; most rerankers return [{index, score}].
     *
     * @param  array<int, string>  $documents
     */
    public function rank(AiModel $model, string $query, array $documents): AiCompletionResult
    {
        $endpoint = $model->resolvedEndpoint();
        $auth = $this->guard($model, $endpoint);

        if ($auth !== null) {
            return $auth;
        }

        $startedAt = $this->startedAt();

        try {
            $response = $this->request($model)->post((string) $endpoint, [
                'inputs' => [
                    'query' => $query,
                    'texts' => array_values($documents),
                ],
            ]);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                return new AiCompletionResult(false, latencyMs: $latency, error: $this->errorMessage($response->status(), $response->json('error')));
            }

            $rows = $response->json() ?? [];
            $scores = [];

            foreach ((is_array($rows) ? $rows : []) as $row) {
                if (is_array($row) && isset($row['index'])) {
                    $scores[] = ['label' => (string) $row['index'], 'score' => (float) ($row['score'] ?? 0.0)];
                }
            }

            return new AiCompletionResult(true, latencyMs: $latency, json: is_array($rows) ? $rows : null, scores: $scores);
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Shared POST {endpoint} {"inputs": ...} flow for HF pipeline runtimes.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(array<mixed>): array<string, mixed>  $parse
     */
    private function pipeline(AiModel $model, array $body, callable $parse): AiCompletionResult
    {
        $endpoint = $model->resolvedEndpoint();
        $auth = $this->guard($model, $endpoint);

        if ($auth !== null) {
            return $auth;
        }

        $startedAt = $this->startedAt();

        try {
            $response = $this->request($model)->post((string) $endpoint, $body + ['parameters' => (object) []]);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                return new AiCompletionResult(false, latencyMs: $latency, error: $this->errorMessage($response->status(), $response->json('error')));
            }

            $data = $response->json();

            if (! is_array($data)) {
                return new AiCompletionResult(false, latencyMs: $latency, error: 'Empty AI response.');
            }

            $parsed = $parse($data);

            return new AiCompletionResult(
                true,
                latencyMs: $latency,
                scores: $parsed['scores'] ?? null,
                entities: $parsed['entities'] ?? null,
                embedding: $parsed['embedding'] ?? null,
            );
        } catch (\Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    private function request(AiModel $model): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken(trim((string) $model->provider->api_key))
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    private function chatCompletionsUrl(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        if (str_ends_with($endpoint, '/v1/chat/completions')) {
            return $endpoint;
        }

        if (str_ends_with($endpoint, '/v1')) {
            return $endpoint.'/chat/completions';
        }

        return $endpoint.'/v1/chat/completions';
    }

    private function guard(AiModel $model, ?string $endpoint): ?AiCompletionResult
    {
        if (trim((string) $model->provider?->api_key) === '') {
            return new AiCompletionResult(false, error: 'API key is not configured.');
        }

        if ($endpoint === null || $endpoint === '') {
            return new AiCompletionResult(false, error: 'Endpoint URL is not configured.');
        }

        return null;
    }

    private function errorMessage(int $status, mixed $message): string
    {
        $message = is_string($message) && $message !== '' ? ": {$message}" : '';

        return "HTTP {$status}{$message}";
    }

    /**
     * Reduce HF feature-extraction output to a single 1-D vector. Token-level
     * outputs (array of vectors) are mean-pooled.
     *
     * @param  array<mixed>  $data
     * @return array<int, float>
     */
    private function flattenEmbedding(array $data): array
    {
        // {embedding: [...]} or {data: [{embedding: [...]}]} shapes.
        if (isset($data['embedding']) && is_array($data['embedding'])) {
            $data = $data['embedding'];
        } elseif (isset($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
            $data = $data['data'][0]['embedding'];
        }

        if ($data === [] || ! is_array($data[0] ?? null)) {
            return array_map('floatval', array_values(array_filter($data, 'is_numeric')));
        }

        // Token-level: array of vectors → mean pool.
        $sum = [];
        $count = 0;

        foreach ($data as $vector) {
            if (! is_array($vector)) {
                continue;
            }

            $count++;
            foreach (array_values($vector) as $i => $value) {
                $sum[$i] = ($sum[$i] ?? 0.0) + (float) $value;
            }
        }

        if ($count === 0) {
            return [];
        }

        return array_map(fn (float $v): float => $v / $count, $sum);
    }
}
