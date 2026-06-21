<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

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
        } catch (Throwable $e) {
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
        $result = $this->pipeline($model, ['inputs' => $input], function (array $data): array {
            return ['embedding' => $this->flattenEmbedding($data)];
        });

        if (! $result->successful && $this->isSentenceSimilarityPayloadError($result->error)) {
            return $this->sentenceSimilarity($model, $input, [$input]);
        }

        return $result;
    }

    /**
     * Sentence similarity endpoints (common for smaller E5/SentenceTransformers
     * deployments) score one source sentence against candidate sentences.
     *
     * @param  array<int, string>  $sentences
     */
    public function sentenceSimilarity(AiModel $model, string $sourceSentence, array $sentences): AiCompletionResult
    {
        return $this->pipeline($model, [
            'inputs' => [
                'source_sentence' => $sourceSentence,
                'sentences' => array_values($sentences),
            ],
        ], function (array $data): array {
            return ['scores' => $this->similarityScores($data)];
        });
    }

    /**
     * Reranking (hf_ranking). Cross-encoder rerankers (e.g. BAAI/bge-reranker)
     * are served by the HF Inference API as a text-classification pipeline that
     * scores sentence pairs, so we send {query, document} pairs under "inputs"
     * and read each pair's relevance score. (A dedicated TEI /rerank endpoint
     * that prefers {query, texts} would 400 here; pairs work on both.)
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
            $pairs = array_map(
                fn (string $document): array => ['text' => $query, 'text_pair' => $document],
                array_values($documents),
            );

            $response = $this->request($model)->post((string) $endpoint, ['inputs' => $pairs]);

            $latency = $this->latencyMs($startedAt);

            if ($response->failed()) {
                return new AiCompletionResult(false, latencyMs: $latency, error: $this->errorMessage($response->status(), $response->json('error.message') ?? $response->json('error')));
            }

            $rows = $response->json();

            if (! is_array($rows)) {
                return new AiCompletionResult(false, latencyMs: $latency, error: 'Empty AI response.');
            }

            $scores = [];
            foreach (array_values($rows) as $index => $row) {
                $scores[] = ['label' => (string) $index, 'score' => $this->relevanceScore($row)];
            }

            usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return new AiCompletionResult(true, latencyMs: $latency, json: $rows, scores: $scores);
        } catch (Throwable $e) {
            return new AiCompletionResult(false, latencyMs: $this->latencyMs($startedAt), error: mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Extract a relevance score from one text-classification result, which may be
     * a single {label, score}, a list of label/score dicts, or a bare number.
     */
    private function relevanceScore(mixed $row): float
    {
        if (is_numeric($row)) {
            return (float) $row;
        }

        if (is_array($row) && isset($row['score'])) {
            return (float) $row['score'];
        }

        if (is_array($row)) {
            $best = 0.0;
            foreach ($row as $entry) {
                if (is_array($entry) && isset($entry['score'])) {
                    $best = max($best, (float) $entry['score']);
                }
            }

            return $best;
        }

        return 0.0;
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
        } catch (Throwable $e) {
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
            ->retry(2, 500, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if (! $exception instanceof RequestException) {
                    return false;
                }

                return $exception->response->status() === 429
                    || $exception->response->serverError();
            }, throw: false);
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

    private function isSentenceSimilarityPayloadError(?string $error): bool
    {
        if ($error === null) {
            return false;
        }

        return str_contains($error, 'SentenceSimilarityPipeline')
            || str_contains($error, 'missing 1 required positional argument: \'sentences\'')
            || str_contains($error, 'source_sentence');
    }

    /**
     * @param  array<mixed>  $data
     * @return array<int, array{label: string, score: float}>
     */
    private function similarityScores(array $data): array
    {
        if (isset($data['scores']) && is_array($data['scores'])) {
            $data = $data['scores'];
        }

        if (isset($data[0]) && is_array($data[0]) && array_key_exists('score', $data[0])) {
            return collect($data)
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(fn (array $row, int $index): array => [
                    'label' => (string) ($row['index'] ?? $row['label'] ?? $index),
                    'score' => (float) ($row['score'] ?? 0.0),
                ])
                ->values()
                ->all();
        }

        return collect($data)
            ->filter(fn (mixed $score): bool => is_numeric($score))
            ->map(fn (mixed $score, int $index): array => [
                'label' => (string) $index,
                'score' => (float) $score,
            ])
            ->values()
            ->all();
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
