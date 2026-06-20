<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'timezone' => $this->timezoneRules(),
            'locale' => $this->localeRules(),
        ];
    }

    /**
     * Get the validation rules used to validate the user's preferred locale.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function localeRules(): array
    {
        return ['sometimes', 'required', 'string', Rule::in(['en', 'tr'])];
    }

    /**
     * Get the validation rules used to validate the user's preferred timezone.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function timezoneRules(): array
    {
        // "sometimes" so registration (no timezone field) still works and new
        // users fall back to the column default; the profile form always sends
        // it, where it must be a valid IANA identifier.
        return ['sometimes', 'required', 'string', 'timezone:all'];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
