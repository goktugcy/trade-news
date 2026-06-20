<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiTaskSetting;
use App\Models\ApiProvider;
use Illuminate\Database\Seeder;

class AiTaskSeeder extends Seeder
{
    private const DEPRECATED_INFERENCE_API_PREFIX = 'https://api-inference.huggingface.co/models/';

    public function run(): void
    {
        // Ensure the global master switch row exists (disabled by default).
        AiSetting::current();

        $this->clearDeprecatedInferenceApiEndpoints();

        $provider = ApiProvider::query()->where('key', 'huggingface')->first();

        if ($provider instanceof ApiProvider) {
            foreach ($this->huggingFaceModels() as $row) {
                $model = AiModel::query()->firstOrNew([
                    'api_provider_id' => $provider->id,
                    'task' => $row['task']->value,
                    'model' => $row['model'],
                ]);

                $model->fill([
                    'name' => $row['name'],
                    'runtime' => $row['runtime']->value,
                    'max_output_tokens' => $row['max_output_tokens'] ?? 160,
                    'temperature' => $row['temperature'] ?? null,
                ]);

                // Dedicated endpoints must be provided by the admin. Clean up
                // older serverless Inference API defaults without touching
                // admin-entered *.endpoints.huggingface.cloud URLs.
                if (str_starts_with((string) $model->endpoint_url, self::DEPRECATED_INFERENCE_API_PREFIX)) {
                    $model->endpoint_url = null;
                }

                if (! $model->exists) {
                    $model->is_active = true;
                }

                $model->save();
            }
        }

        $deepLProvider = ApiProvider::query()->where('key', 'deepl')->first();

        if ($deepLProvider instanceof ApiProvider) {
            AiModel::query()->updateOrCreate([
                'api_provider_id' => $deepLProvider->id,
                'task' => AiTask::Translation->value,
                'model' => 'deepl-api',
            ], [
                'name' => 'DeepL Translate',
                'runtime' => AiRuntime::DeepLTranslation->value,
                'max_output_tokens' => 160,
                'temperature' => null,
                'endpoint_url' => null,
                'is_active' => true,
            ]);
        }

        // One settings row per task (disabled by default; admin opts in).
        foreach (AiTask::cases() as $task) {
            AiTaskSetting::query()->firstOrCreate(['task' => $task->value], ['enabled' => false]);
        }
    }

    private function clearDeprecatedInferenceApiEndpoints(): void
    {
        AiModel::query()
            ->where('endpoint_url', 'like', self::DEPRECATED_INFERENCE_API_PREFIX.'%')
            ->update(['endpoint_url' => null]);
    }

    /**
     * @return array<int, array{task: AiTask, name: string, model: string, runtime: AiRuntime, max_output_tokens?: int, temperature?: float}>
     */
    private function huggingFaceModels(): array
    {
        return [
            ['task' => AiTask::Summary, 'name' => 'Qwen3 8B (summary)', 'model' => 'Qwen/Qwen3-8B', 'runtime' => AiRuntime::OpenAiChat, 'max_output_tokens' => 300, 'temperature' => 0.3],
            ['task' => AiTask::StockAnalysis, 'name' => 'Qwen3 8B (analysis)', 'model' => 'Qwen/Qwen3-8B', 'runtime' => AiRuntime::OpenAiChat, 'max_output_tokens' => 700, 'temperature' => 0.2],
            ['task' => AiTask::Translation, 'name' => 'Qwen3 8B (translation)', 'model' => 'Qwen/Qwen3-8B', 'runtime' => AiRuntime::OpenAiChat, 'max_output_tokens' => 900, 'temperature' => 0.1],
            ['task' => AiTask::SentimentEn, 'name' => 'FinBERT (EN sentiment)', 'model' => 'ProsusAI/finbert', 'runtime' => AiRuntime::HfTextClassification],
            ['task' => AiTask::SentimentTr, 'name' => 'BERT TR sentiment', 'model' => 'saribasmetehan/bert-base-turkish-sentiment-analysis', 'runtime' => AiRuntime::HfTextClassification],
            ['task' => AiTask::EntityEn, 'name' => 'BERT base NER (EN)', 'model' => 'dslim/bert-base-NER', 'runtime' => AiRuntime::HfTokenClassification],
            ['task' => AiTask::EntityTr, 'name' => 'BERTurk NER (TR)', 'model' => 'busecarik/berturk-sunlp-ner-turkish', 'runtime' => AiRuntime::HfTokenClassification],
            ['task' => AiTask::Embedding, 'name' => 'Multilingual E5 large', 'model' => 'intfloat/multilingual-e5-large', 'runtime' => AiRuntime::HfFeatureExtraction],
            ['task' => AiTask::Reranker, 'name' => 'BGE reranker v2 m3', 'model' => 'BAAI/bge-reranker-v2-m3', 'runtime' => AiRuntime::HfRanking],
        ];
    }
}
