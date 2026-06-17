<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'news_item_id' => null,
            'notification_rule_id' => null,
            'channel' => 'telegram',
            'status' => Notification::STATUS_SENT,
            'title' => fake()->sentence(6),
            'body' => fake()->paragraph(),
            'error' => null,
            'sent_at' => now(),
        ];
    }
}
