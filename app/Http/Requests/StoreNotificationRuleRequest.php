<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Market;
use App\Enums\NotificationInterval;
use App\Enums\Sentiment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'interval_minutes' => ['required', 'integer', Rule::in(array_column(NotificationInterval::cases(), 'value'))],
            'markets' => ['nullable', 'array'],
            'markets.*' => [Rule::in(array_column(Market::cases(), 'value'))],
            'sentiments' => ['nullable', 'array'],
            'sentiments.*' => [Rule::in(array_column(Sentiment::cases(), 'value'))],
            'only_watchlist' => ['boolean'],
            'min_importance' => ['integer', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'only_watchlist' => $this->boolean('only_watchlist'),
            'is_active' => $this->boolean('is_active'),
            // Empty arrays mean "all" — store as null.
            'markets' => $this->filled('markets') ? $this->input('markets') : null,
            'sentiments' => $this->filled('sentiments') ? $this->input('sentiments') : null,
        ]);
    }
}
