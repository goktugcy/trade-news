<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNotification>
 */
class UserNotificationFactory extends Factory
{
    protected $model = UserNotification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(NotificationCategory::cases()),
            'type' => 'generic',
            'title' => fake()->sentence(5),
            'body' => fake()->sentence(10),
            'data' => [],
            'action_url' => null,
            'read_at' => null,
            'created_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
