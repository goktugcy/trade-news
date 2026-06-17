<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Market;
use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockRequest extends FormRequest
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
        $stock = $this->route('stock');
        $stockId = $stock instanceof Stock ? $stock->id : null;

        return [
            'symbol' => [
                'required', 'string', 'max:20',
                Rule::unique('stocks')->where('market', $this->input('market'))->ignore($stockId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'market' => ['required', Rule::in(array_column(Market::cases(), 'value'))],
            'exchange' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:8'],
            'sector' => ['nullable', 'string', 'max:100'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:100'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
