<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Market;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        $market = fake()->randomElement(Market::cases());
        $symbol = Str::upper(fake()->unique()->lexify('????'));
        $name = fake()->company();

        return [
            'symbol' => $symbol,
            'name' => $name,
            'market' => $market,
            'exchange' => $market->label(),
            'currency' => $market->currency(),
            'sector' => fake()->randomElement(['Technology', 'Finance', 'Energy', 'Industrials', 'Consumer']),
            'aliases' => [$symbol, $name],
            'keywords' => [],
            'is_active' => true,
        ];
    }

    public function bist(): static
    {
        return $this->state(fn () => [
            'market' => Market::BIST,
            'exchange' => 'Borsa İstanbul',
            'currency' => 'TRY',
        ]);
    }

    public function nasdaq(): static
    {
        return $this->state(fn () => [
            'market' => Market::NASDAQ,
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
        ]);
    }
}
