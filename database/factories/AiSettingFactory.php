<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSetting>
 */
class AiSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enabled' => true,
            'active_ai_model_id' => AiModel::factory(),
        ];
    }
}
