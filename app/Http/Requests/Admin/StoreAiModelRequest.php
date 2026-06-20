<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiRuntime;
use App\Enums\AiTask;
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
                    ->where('task', $this->input('task'))
                    ->ignore($modelId),
            ],
            'task' => ['nullable', Rule::enum(AiTask::class)],
            'runtime' => ['nullable', Rule::enum(AiRuntime::class)],
            'endpoint_url' => ['nullable', 'url', 'max:1024'],
            'is_active' => ['boolean'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'task' => $this->input('task') === '' ? null : $this->input('task'),
            'runtime' => $this->input('runtime') === '' ? null : $this->input('runtime'),
            'endpoint_url' => $this->input('endpoint_url') === '' ? null : $this->input('endpoint_url'),
            'temperature' => $this->input('temperature') === '' ? null : $this->input('temperature'),
        ]);
    }
}
