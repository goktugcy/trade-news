<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Timeframe;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockPrice>
 */
class StockPriceFactory extends Factory
{
    protected $model = StockPrice::class;

    public function definition(): array
    {
        $open = fake()->randomFloat(2, 10, 500);
        $close = $open * fake()->randomFloat(4, 0.97, 1.03);
        $high = max($open, $close) * fake()->randomFloat(4, 1.0, 1.02);
        $low = min($open, $close) * fake()->randomFloat(4, 0.98, 1.0);

        return [
            'stock_id' => Stock::factory(),
            'timeframe' => Timeframe::FiveMinutes,
            'open' => round($open, 4),
            'high' => round($high, 4),
            'low' => round($low, 4),
            'close' => round($close, 4),
            'volume' => fake()->numberBetween(1_000, 5_000_000),
            'price_at' => fake()->dateTimeBetween('-2 days', 'now'),
        ];
    }

    public function timeframe(Timeframe $tf): static
    {
        return $this->state(fn () => ['timeframe' => $tf]);
    }
}
