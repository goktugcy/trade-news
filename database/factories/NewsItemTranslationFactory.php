<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NewsItem;
use App\Models\NewsItemTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsItemTranslation>
 */
class NewsItemTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'news_item_id' => NewsItem::factory(),
            'locale' => fake()->randomElement(['en', 'tr']),
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'generated_at' => now(),
            'provider' => 'deepl',
        ];
    }
}
