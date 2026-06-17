<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramIntegration>
 */
class TelegramIntegrationFactory extends Factory
{
    protected $model = TelegramIntegration::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_id' => null,
            'telegram_username' => null,
            'connection_code' => Str::upper(Str::random(8)),
            'code_expires_at' => now()->addMinutes(30),
            'is_enabled' => false,
            'connected_at' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'chat_id' => (string) fake()->numberBetween(100000000, 999999999),
            'telegram_username' => fake()->userName(),
            'connection_code' => null,
            'code_expires_at' => null,
            'is_enabled' => true,
            'connected_at' => now(),
        ]);
    }
}
