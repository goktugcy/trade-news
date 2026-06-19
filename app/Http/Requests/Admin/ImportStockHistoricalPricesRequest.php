<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Timeframe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class ImportStockHistoricalPricesRequest extends FormRequest
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
            'file' => ['required', File::types(['csv', 'txt'])->max(20 * 1024)],
            'timeframe' => ['required', Rule::in(array_column(Timeframe::cases(), 'value'))],
        ];
    }
}
