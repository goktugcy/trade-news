<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Market;
use App\Models\NewsSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(['en', 'tr'])],
            'preferred_markets' => ['nullable', 'array'],
            'preferred_markets.*' => ['required', 'string', Rule::in(array_column(Market::cases(), 'value'))],
            'news_sources' => ['required', 'array'],
            'news_sources.*.id' => [
                'required',
                'integer',
                Rule::exists(NewsSource::class, 'id')->where('is_active', true),
            ],
            'news_sources.*.enabled' => ['required', 'boolean'],
        ];
    }
}
