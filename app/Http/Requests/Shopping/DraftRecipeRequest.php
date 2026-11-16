<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class DraftRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'idea' => ['nullable', 'string', 'max:300'],
            'use_pantry' => ['sometimes', 'boolean'],
        ];
    }
}
