<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertType;
use App\Models\Stock;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAlert>
 */
class StockAlertFactory extends Factory
{
    protected $model = StockAlert::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stock_id' => Stock::factory(),
            'type' => AlertType::PriceAbove,
            'threshold' => 100,
            'is_active' => true,
            'cooldown_minutes' => 60,
            'last_triggered_at' => null,
            'notify_in_app' => true,
            'notify_telegram' => false,
        ];
    }

    public function type(AlertType $type, ?float $threshold = null): static
    {
        return $this->state(fn () => ['type' => $type, 'threshold' => $threshold]);
    }
}
