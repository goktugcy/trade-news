<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Market;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NewsSource>
 */
class NewsSourceFactory extends Factory
{
    protected $model = NewsSource::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Wire';

        return [
            'key' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999),
            'name' => $name,
            'provider' => 'synthetic-news',
            'market' => fake()->randomElement([Market::NASDAQ->value, null]),
            'language' => 'en',
            'feed_url' => fake()->url().'/rss',
            'homepage_url' => fake()->url(),
            'is_active' => true,
        ];
    }
}
