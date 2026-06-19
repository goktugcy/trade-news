<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Market;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class ImportStooqHistoricalPricesRequest extends FormRequest
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
            'file' => ['nullable', 'required_without:files', File::types(['txt', 'csv'])->max(200 * 1024)],
            'files' => ['nullable', 'required_without:file', 'array'],
            'files.*' => [File::types(['txt', 'csv'])->max(200 * 1024)],
            'fallback_market' => ['required', Rule::in(['ALL', ...array_column(Market::cases(), 'value')])],
        ];
    }
}
