<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestMealAlternativeRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'meal' => ['required', Rule::enum(MealType::class)],
            'entries' => ['present', 'array', 'max:63'],
            'entries.*' => ['array'],
        ];
    }
}
