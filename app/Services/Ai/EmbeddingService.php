<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTask;
use Illuminate\Support\Facades\Cache;

/**
 * Produces (and caches) embedding vectors via the configured Hugging Face
 * feature-extraction endpoint. Returns null when the task is disabled/unhealthy.
 */
class EmbeddingService
{
    public function __construct(private readonly AiTaskService $tasks) {}

    public function isEnabled(): bool
    {
        return $this->tasks->modelFor(AiTask::Embedding) !== null;
    }

    /**
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $model = $this->tasks->modelFor(AiTask::Embedding);
        $client = $model !== null ? $this->tasks->huggingFaceFor($model) : null;

        if ($model === null || $client === null) {
            return null;
        }

        $key = 'tn:embed:'.sha1($model->model.'|'.$text);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $client->featureExtract($model, $text);

        if ($result->successful && $result->scores !== null) {
            $this->tasks->recordSuccess($model, $result->latencyMs);

            return null;
        }

        if (! $result->successful || $result->embedding === null || $result->embedding === []) {
            $this->tasks->recordFailure($model, $result->error, $result->latencyMs);

            return null;
        }

        $this->tasks->recordSuccess($model, $result->latencyMs);
        Cache::put($key, $result->embedding, now()->addDay());

        return $result->embedding;
    }

    /**
     * Score one entity mention against candidate stock descriptions. Uses a
     * sentence-similarity endpoint when available; otherwise falls back to
     * cached embedding cosine similarity.
     *
     * @param  array<int, string>  $sentences
     * @return array<int, float>|null
     */
    public function similarity(string $sourceSentence, array $sentences): ?array
    {
        $sourceSentence = trim($sourceSentence);
        $sentences = array_values(array_filter(array_map('trim', $sentences)));

        if ($sourceSentence === '' || $sentences === []) {
            return null;
        }

        $model = $this->tasks->modelFor(AiTask::Embedding);
        $client = $model !== null ? $this->tasks->huggingFaceFor($model) : null;

        if ($model === null || $client === null) {
            return null;
        }

        $key = 'tn:sim:'.sha1($model->model.'|'.$sourceSentence.'|'.json_encode($sentences, JSON_UNESCAPED_UNICODE));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return array_map('floatval', $cached);
        }

        $result = $client->sentenceSimilarity($model, $sourceSentence, $sentences);

        if ($result->successful && $result->scores !== null) {
            $scores = array_fill(0, count($sentences), 0.0);

            foreach ($result->scores as $row) {
                $index = (int) $row['label'];

                if (array_key_exists($index, $scores)) {
                    $scores[$index] = (float) $row['score'];
                }
            }

            $this->tasks->recordSuccess($model, $result->latencyMs);
            Cache::put($key, $scores, now()->addDay());

            return $scores;
        }

        $source = $this->embed($sourceSentence);

        if ($source === null) {
            return null;
        }

        $scores = [];

        foreach ($sentences as $sentence) {
            $target = $this->embed($sentence);
            $scores[] = $target !== null ? self::cosine($source, $target) : 0.0;
        }

        Cache::put($key, $scores, now()->addDay());

        return $scores;
    }

    /**
     * Cosine similarity between two vectors (0 when either is empty/mismatched).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));

        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] ** 2;
            $nb += $b[$i] ** 2;
        }

        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
