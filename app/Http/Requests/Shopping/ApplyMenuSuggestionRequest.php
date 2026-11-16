<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMenuSuggestionRequest extends FormRequest
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
            'week_start' => ['required', 'date_format:Y-m-d'],
            'entries' => ['present', 'array', 'max:63'],
            'entries.*' => ['array'],
        ];
    }
}
