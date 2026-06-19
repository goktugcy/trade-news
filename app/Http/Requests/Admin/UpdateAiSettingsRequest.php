<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingsRequest extends FormRequest
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
        return [
            'enabled' => ['boolean'],
            'active_ai_model_id' => ['nullable', Rule::exists('ai_models', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'active_ai_model_id' => $this->input('active_ai_model_id') === '' ? null : $this->input('active_ai_model_id'),
        ]);
    }
}
