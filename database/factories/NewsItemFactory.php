<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Market;
use App\Enums\Sentiment;
use App\Models\NewsItem;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NewsItem>
 */
class NewsItemFactory extends Factory
{
    protected $model = NewsItem::class;

    public function definition(): array
    {
        $title = Str::ucfirst(fake()->sentence(8));
        $url = fake()->url().'/'.fake()->unique()->slug();
        $publishedAt = fake()->dateTimeBetween('-7 days', 'now');
        $score = fake()->randomFloat(4, -1, 1);

        return [
            'source_id' => NewsSource::factory(),
            'title' => $title,
            'summary' => fake()->paragraph(),
            'content' => fake()->paragraphs(3, true),
            'url' => $url,
            'image_url' => null,
            'published_at' => $publishedAt,
            'market' => fake()->randomElement(Market::cases()),
            'sentiment' => Sentiment::fromScore($score),
            'sentiment_score' => $score,
            'importance_score' => fake()->numberBetween(0, 100),
            'is_matched' => false,
            'hash' => NewsItem::makeHash($title, $url),
        ];
    }

    public function forMarket(Market $market): static
    {
        return $this->state(fn () => ['market' => $market]);
    }
}
