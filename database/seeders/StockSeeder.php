<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Market;
use App\Models\Stock;
use Illuminate\Database\Seeder;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->stocks() as $stock) {
            Stock::query()->updateOrCreate(
                ['market' => $stock['market']->value, 'symbol' => $stock['symbol']],
                [
                    'name' => $stock['name'],
                    'exchange' => $stock['market']->label(),
                    'currency' => $stock['market']->currency(),
                    'sector' => $stock['sector'] ?? null,
                    'aliases' => $stock['aliases'],
                    'keywords' => $stock['keywords'] ?? [],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stocks(): array
    {
        return [
            // ---------------- BIST ----------------
            ['market' => Market::BIST, 'symbol' => 'THYAO', 'name' => 'Türk Hava Yolları', 'sector' => 'Airlines',
                'aliases' => ['THYAO', 'Türk Hava Yolları', 'Turkish Airlines', 'THY']],
            ['market' => Market::BIST, 'symbol' => 'ASELS', 'name' => 'Aselsan Elektronik', 'sector' => 'Defense',
                'aliases' => ['ASELS', 'Aselsan', 'Aselsan Elektronik']],
            ['market' => Market::BIST, 'symbol' => 'GARAN', 'name' => 'Garanti BBVA', 'sector' => 'Banking',
                'aliases' => ['GARAN', 'Garanti', 'Garanti Bankası', 'Garanti BBVA']],
            ['market' => Market::BIST, 'symbol' => 'AKBNK', 'name' => 'Akbank', 'sector' => 'Banking',
                'aliases' => ['AKBNK', 'Akbank']],
            ['market' => Market::BIST, 'symbol' => 'BIMAS', 'name' => 'BİM Birleşik Mağazalar', 'sector' => 'Retail',
                'aliases' => ['BIMAS', 'BİM', 'BIM', 'Birleşik Mağazalar']],
            ['market' => Market::BIST, 'symbol' => 'EREGL', 'name' => 'Ereğli Demir Çelik', 'sector' => 'Steel',
                'aliases' => ['EREGL', 'Erdemir', 'Ereğli', 'Eregli Demir Celik']],
            ['market' => Market::BIST, 'symbol' => 'KCHOL', 'name' => 'Koç Holding', 'sector' => 'Conglomerate',
                'aliases' => ['KCHOL', 'Koç Holding', 'Koc Holding']],
            ['market' => Market::BIST, 'symbol' => 'SAHOL', 'name' => 'Sabancı Holding', 'sector' => 'Conglomerate',
                'aliases' => ['SAHOL', 'Sabancı', 'Sabanci Holding', 'Sabancı Holding']],
            ['market' => Market::BIST, 'symbol' => 'SISE', 'name' => 'Şişecam', 'sector' => 'Industrials',
                'aliases' => ['SISE', 'Şişecam', 'Sisecam']],
            ['market' => Market::BIST, 'symbol' => 'TUPRS', 'name' => 'Tüpraş', 'sector' => 'Energy',
                'aliases' => ['TUPRS', 'Tüpraş', 'Tupras']],

            // ---------------- NASDAQ ----------------
            ['market' => Market::NASDAQ, 'symbol' => 'AAPL', 'name' => 'Apple Inc.', 'sector' => 'Technology',
                'aliases' => ['AAPL', 'Apple']],
            ['market' => Market::NASDAQ, 'symbol' => 'MSFT', 'name' => 'Microsoft Corporation', 'sector' => 'Technology',
                'aliases' => ['MSFT', 'Microsoft']],
            ['market' => Market::NASDAQ, 'symbol' => 'GOOGL', 'name' => 'Alphabet Inc.', 'sector' => 'Technology',
                'aliases' => ['GOOGL', 'Google', 'Alphabet']],
            ['market' => Market::NASDAQ, 'symbol' => 'AMZN', 'name' => 'Amazon.com Inc.', 'sector' => 'Consumer',
                'aliases' => ['AMZN', 'Amazon']],
            ['market' => Market::NASDAQ, 'symbol' => 'NVDA', 'name' => 'NVIDIA Corporation', 'sector' => 'Semiconductors',
                'aliases' => ['NVDA', 'Nvidia']],
            ['market' => Market::NASDAQ, 'symbol' => 'META', 'name' => 'Meta Platforms Inc.', 'sector' => 'Technology',
                'aliases' => ['META', 'Meta', 'Facebook']],
            ['market' => Market::NASDAQ, 'symbol' => 'TSLA', 'name' => 'Tesla Inc.', 'sector' => 'Automotive',
                'aliases' => ['TSLA', 'Tesla']],
            ['market' => Market::NASDAQ, 'symbol' => 'AMD', 'name' => 'Advanced Micro Devices', 'sector' => 'Semiconductors',
                'aliases' => ['AMD', 'Advanced Micro Devices']],
            ['market' => Market::NASDAQ, 'symbol' => 'NFLX', 'name' => 'Netflix Inc.', 'sector' => 'Media',
                'aliases' => ['NFLX', 'Netflix']],
            ['market' => Market::NASDAQ, 'symbol' => 'INTC', 'name' => 'Intel Corporation', 'sector' => 'Semiconductors',
                'aliases' => ['INTC', 'Intel']],
        ];
    }
}
