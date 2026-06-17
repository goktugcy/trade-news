<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiProvider>
 */
class ApiProviderFactory extends Factory
{
    protected $model = ApiProvider::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'key' => Str::slug($name),
            'name' => Str::title($name),
            'type' => fake()->randomElement(ProviderType::cases()),
            'status' => ProviderStatus::Operational,
            'is_active' => true,
            'base_url' => fake()->url(),
            'priority' => 100,
            'last_checked_at' => now(),
            'last_latency_ms' => fake()->numberBetween(50, 800),
            'last_error' => null,
            'meta' => [],
        ];
    }
}
