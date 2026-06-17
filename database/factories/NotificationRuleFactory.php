<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationInterval;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    protected $model = NotificationRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'My alerts',
            'interval_minutes' => fake()->randomElement(NotificationInterval::cases())->value,
            'markets' => null,
            'sentiments' => null,
            'only_watchlist' => true,
            'min_importance' => fake()->randomElement([0, 25, 50]),
            'is_active' => true,
            'last_dispatched_at' => null,
        ];
    }
}
