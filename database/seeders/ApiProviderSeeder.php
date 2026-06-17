<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use Illuminate\Database\Seeder;

class ApiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['key' => 'synthetic', 'name' => 'Synthetic Generator', 'type' => ProviderType::MarketData,
                'base_url' => null, 'priority' => 10, 'status' => ProviderStatus::Operational],
            ['key' => 'finnhub', 'name' => 'Finnhub', 'type' => ProviderType::MarketData,
                'base_url' => 'https://finnhub.io/api/v1', 'priority' => 20, 'status' => ProviderStatus::Unknown],
            ['key' => 'twelvedata', 'name' => 'Twelve Data', 'type' => ProviderType::MarketData,
                'base_url' => 'https://api.twelvedata.com', 'priority' => 30, 'status' => ProviderStatus::Unknown],
            ['key' => 'synthetic-news', 'name' => 'Synthetic News Wire', 'type' => ProviderType::News,
                'base_url' => null, 'priority' => 10, 'status' => ProviderStatus::Operational],
            ['key' => 'finnhub-news', 'name' => 'Finnhub News', 'type' => ProviderType::News,
                'base_url' => 'https://finnhub.io/api/v1', 'priority' => 20, 'status' => ProviderStatus::Unknown],
            ['key' => 'kap', 'name' => 'KAP (Public Disclosures)', 'type' => ProviderType::News,
                'base_url' => 'https://www.kap.org.tr', 'priority' => 25, 'status' => ProviderStatus::Unknown],
        ];

        foreach ($providers as $provider) {
            ApiProvider::query()->updateOrCreate(
                ['key' => $provider['key']],
                $provider + ['is_active' => true],
            );
        }
    }
}
