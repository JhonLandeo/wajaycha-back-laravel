<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMealPlanSettingsRequest extends FormRequest
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
            'household_size' => ['required', 'integer', 'between:1,20'],
            'meal_types' => ['required', 'array', 'min:1'],
            'meal_types.*' => ['string', 'distinct', Rule::in(MealType::values())],
            'plan_weekdays' => ['required', 'array', 'min:1'],
            'plan_weekdays.*' => ['integer', 'distinct', 'between:1,7'],
            'excluded_terms' => ['sometimes', 'array', 'max:20'],
            'excluded_terms.*' => ['string', 'distinct', 'max:60'],
            'max_prep_minutes' => ['nullable', 'integer', 'between:1,240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'household_size.between' => 'Indica entre 1 y 20 personas.',
            'meal_types.required' => 'Elige al menos una comida.',
            'plan_weekdays.required' => 'Elige al menos un día para planificar.',
            'plan_weekdays.*.between' => 'Los días deben estar entre lunes y domingo.',
        ];
    }
}
