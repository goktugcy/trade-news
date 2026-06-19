<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Models\AiModel;
use App\Models\ApiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_provider_id' => ApiProvider::factory()->state([
                'key' => 'openai',
                'name' => 'OpenAI',
                'type' => ProviderType::Ai,
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'test-secret',
            ]),
            'name' => 'GPT Mini',
            'model' => 'gpt-4o-mini',
            'is_active' => true,
            'max_output_tokens' => 160,
            'temperature' => 0.3,
            'meta' => [],
        ];
    }
}
