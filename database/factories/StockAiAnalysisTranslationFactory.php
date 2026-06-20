<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockSignal;
use App\Models\Stock;
use App\Models\StockAiAnalysis;
use App\Models\StockAiAnalysisTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAiAnalysisTranslation>
 */
class StockAiAnalysisTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_ai_analysis_id' => StockAiAnalysis::query()->create([
                'stock_id' => Stock::factory()->create()->id,
                'signal' => StockSignal::Neutral,
                'confidence' => 50,
                'summary' => fake()->paragraph(),
                'drivers' => [fake()->sentence()],
                'risks' => [fake()->sentence()],
                'generated_at' => now(),
            ]),
            'locale' => fake()->randomElement(['en', 'tr']),
            'summary' => fake()->paragraph(),
            'drivers' => [fake()->sentence()],
            'risks' => [fake()->sentence()],
            'disclaimer' => fake()->sentence(),
            'generated_at' => now(),
            'provider' => 'deepl',
        ];
    }
}
