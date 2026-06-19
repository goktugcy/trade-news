<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\ApiProvider;
use App\Services\Ai\AiProviderClientFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiProviderRequest extends FormRequest
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
        $provider = $this->route('apiProvider');
        $providerId = $provider instanceof ApiProvider ? $provider->id : null;

        return [
            'key' => [
                'required',
                'string',
                'max:64',
                Rule::in(AiProviderClientFactory::SUPPORTED_PROVIDER_KEYS),
                Rule::unique('api_providers', 'key')->ignore($providerId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:8192'],
            'clear_api_key' => ['boolean'],
            'is_active' => ['boolean'],
            'auto_recovery' => ['boolean'],
            'priority' => ['integer', 'min:1', 'max:999'],
            'refresh_interval_minutes' => ['integer', 'min:1', 'max:1440'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->has('clear_api_key')) {
            $values['clear_api_key'] = $this->boolean('clear_api_key');
        }

        if ($this->has('is_active')) {
            $values['is_active'] = $this->boolean('is_active');
        }

        if ($this->has('auto_recovery')) {
            $values['auto_recovery'] = $this->boolean('auto_recovery');
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }
}
