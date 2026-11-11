<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMealPlanEntryRequest extends FormRequest
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
        $userId = (int) $this->user()?->id;

        return [
            'plan_date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', Rule::enum(MealType::class)],
            'kind' => ['required', Rule::enum(MealEntryKind::class)],
            'status' => ['sometimes', Rule::enum(MealEntryStatus::class)],
            'recipe_id' => [
                'nullable',
                'integer',
                Rule::exists('recipes', 'id')->where(
                    static fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId)
                ),
            ],
            'servings' => ['required', 'integer', 'between:0,20'],
            'cook_servings' => ['nullable', 'integer', 'between:0,50'],
            'leftover_of_entry_id' => [
                'nullable',
                'integer',
                Rule::exists('meal_plan_entries', 'id')->where('user_id', $userId),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_date.required' => 'Falta la fecha del menú.',
            'meal_type.required' => 'Elige desayuno, almuerzo o cena.',
            'kind.required' => 'Elige el tipo de comida.',
            'servings.between' => 'Las porciones deben estar entre 0 y 20.',
            'leftover_of_entry_id.exists' => 'La comida de origen de las sobras no existe o no te pertenece.',
        ];
    }
}
