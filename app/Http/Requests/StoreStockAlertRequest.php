<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAlertRequest extends FormRequest
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
        $thresholdTypes = array_values(array_filter(
            array_column(AlertType::cases(), 'value'),
            fn (string $value): bool => AlertType::from($value)->needsThreshold(),
        ));

        return [
            'stock_id' => ['required', 'integer', 'exists:stocks,id'],
            'type' => ['required', Rule::in(array_column(AlertType::cases(), 'value'))],
            'threshold' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), $thresholdTypes, true)),
                'nullable', 'numeric', 'min:0',
            ],
            'cooldown_minutes' => ['integer', 'min:0', 'max:1440'],
            'is_active' => ['boolean'],
            'notify_in_app' => ['boolean'],
            'notify_telegram' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'notify_in_app' => $this->boolean('notify_in_app', true),
            'notify_telegram' => $this->boolean('notify_telegram'),
            'cooldown_minutes' => (int) ($this->input('cooldown_minutes') ?: 60),
        ]);
    }
}
