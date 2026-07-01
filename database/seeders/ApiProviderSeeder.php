<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\NewsSource;
use Illuminate\Database\Seeder;

class ApiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['key' => 'synthetic', 'name' => 'Synthetic Generator', 'type' => ProviderType::MarketData,
                'base_url' => null, 'priority' => 10, 'status' => ProviderStatus::Operational, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['quotes', 'candles'], 'auto_sync_stocks' => false],
            ['key' => 'finnhub', 'name' => 'Finnhub', 'type' => ProviderType::MarketData,
                'base_url' => 'https://finnhub.io/api/v1', 'priority' => 20, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
                'markets' => ['NASDAQ'], 'capabilities' => ['quotes', 'candles'], 'auto_sync_stocks' => false],
            ['key' => 'twelvedata', 'name' => 'Twelve Data', 'type' => ProviderType::MarketData,
                'base_url' => 'https://api.twelvedata.com', 'priority' => 30, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
                'markets' => ['NASDAQ'], 'capabilities' => ['quotes', 'candles'], 'auto_sync_stocks' => false],
            ['key' => 'fmp', 'name' => 'Financial Modeling Prep', 'type' => ProviderType::MarketData,
                'base_url' => 'https://financialmodelingprep.com/stable', 'priority' => 40, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 1440, 'fetch_limit' => 50,
                'markets' => ['NASDAQ'], 'capabilities' => ['list', 'profiles', 'quotes'], 'auto_sync_stocks' => true],
            ['key' => 'synthetic-news', 'name' => 'Synthetic News Wire', 'type' => ProviderType::News,
                'base_url' => null, 'priority' => 10, 'status' => ProviderStatus::Operational, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['news'], 'auto_sync_stocks' => false],
            ['key' => 'finnhub-news', 'name' => 'Finnhub News', 'type' => ProviderType::News,
                'base_url' => 'https://finnhub.io/api/v1', 'priority' => 20, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 5, 'fetch_limit' => 50,
                'markets' => ['NASDAQ'], 'capabilities' => ['news'], 'auto_sync_stocks' => false],
            ['key' => 'rss', 'name' => 'RSS Feeds', 'type' => ProviderType::News,
                'base_url' => null, 'priority' => 15, 'status' => ProviderStatus::Operational, 'refresh_interval_minutes' => 5, 'fetch_limit' => 120,
                'markets' => null, 'capabilities' => ['news'], 'auto_sync_stocks' => false],
            ['key' => 'openai', 'name' => 'OpenAI', 'type' => ProviderType::Ai,
                'base_url' => 'https://api.openai.com/v1', 'priority' => 100, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['summaries', 'analysis', 'translation'], 'auto_sync_stocks' => false, 'is_active' => false],
            ['key' => 'anthropic', 'name' => 'Anthropic', 'type' => ProviderType::Ai,
                'base_url' => 'https://api.anthropic.com', 'priority' => 110, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['summaries', 'analysis', 'translation'], 'auto_sync_stocks' => false, 'is_active' => false],
            ['key' => 'gemini', 'name' => 'Google Gemini', 'type' => ProviderType::Ai,
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'priority' => 120, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['summaries', 'analysis', 'translation'], 'auto_sync_stocks' => false, 'is_active' => false],
            ['key' => 'grok', 'name' => 'Grok / xAI', 'type' => ProviderType::Ai,
                'base_url' => 'https://api.x.ai/v1', 'priority' => 130, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['summaries', 'analysis', 'translation'], 'auto_sync_stocks' => false, 'is_active' => false],
            ['key' => 'huggingface', 'name' => 'Hugging Face', 'type' => ProviderType::Ai,
                'base_url' => null, 'priority' => 90, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['summaries', 'sentiment', 'entities', 'embeddings', 'reranking', 'analysis', 'translation'], 'auto_sync_stocks' => false, 'is_active' => false],
            ['key' => 'deepl', 'name' => 'DeepL', 'type' => ProviderType::Ai,
                'base_url' => 'https://api-free.deepl.com/v2', 'priority' => 95, 'status' => ProviderStatus::Unknown, 'refresh_interval_minutes' => 30, 'fetch_limit' => 50,
                'markets' => null, 'capabilities' => ['translation'], 'auto_sync_stocks' => false, 'is_active' => false],
        ];

        foreach ($providers as $provider) {
            $existing = ApiProvider::query()->where('key', $provider['key'])->first();

            if ($existing instanceof ApiProvider) {
                $update = $provider;
                unset($update['is_active']);

                $existing->update($update);

                continue;
            }

            ApiProvider::query()->create($provider + ['is_active' => true]);
        }

        $this->seedRssSources();
    }

    /**
     * Register a news_sources row per configured RSS feed so each origin is
     * tracked (and toggleable in the admin panel) even after duplicate merging.
     */
    private function seedRssSources(): void
    {
        /** @var array<int, array<string, mixed>> $feeds */
        $feeds = (array) config('tradenews.news.providers.rss.feeds', []);

        foreach ($feeds as $feed) {
            $attributes = [
                'name' => $feed['name'],
                'provider' => 'rss',
                'market' => $feed['market'] ?? null,
                'feed_url' => $feed['url'] ?? null,
                'homepage_url' => $feed['homepage_url'] ?? null,
            ];

            $existing = NewsSource::query()->where('key', $feed['key'])->first();

            if ($existing instanceof NewsSource) {
                $existing->update($attributes);

                continue;
            }

            NewsSource::query()->create($attributes + [
                'key' => $feed['key'],
                'is_active' => true,
            ]);
        }
    }
}
