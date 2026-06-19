<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ProviderType;
use App\Models\AiModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $model = $this->route('aiModel');
        $modelId = $model instanceof AiModel ? $model->id : null;

        return [
            'api_provider_id' => [
                'required',
                Rule::exists('api_providers', 'id')->where('type', ProviderType::Ai->value),
            ],
            'name' => ['required', 'string', 'max:120'],
            'model' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ai_models', 'model')
                    ->where('api_provider_id', $this->input('api_provider_id'))
                    ->ignore($modelId),
            ],
            'is_active' => ['boolean'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'temperature' => $this->input('temperature') === '' ? null : $this->input('temperature'),
        ]);
    }
}
