<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\DietProfile;
use App\Enums\KitchenEquipment;
use App\Enums\MealType;
use App\Enums\MenuStrategy;
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
            'menu_strategy' => ['sometimes', Rule::enum(MenuStrategy::class)],
            'available_equipment' => ['sometimes', 'nullable', 'array'],
            'available_equipment.*' => ['string', 'distinct', Rule::enum(KitchenEquipment::class)],
            'diet_profile' => ['sometimes', Rule::enum(DietProfile::class)],
            'new_recipes_per_week' => ['sometimes', 'integer', 'between:0,5'],
            'daily_calorie_target' => ['sometimes', 'nullable', 'integer', 'between:800,5000'],
            'dinner_carb_limit_g' => ['sometimes', 'nullable', 'integer', 'between:0,300'],
            'dinner_max_prep_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
            'dinner_calorie_limit' => ['sometimes', 'nullable', 'integer', 'between:200,2000'],
            'consider_pantry_in_ai_recipes' => ['sometimes', 'boolean'],
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
            'daily_calorie_target.between' => 'Indica un objetivo entre 800 y 5000 kcal.',
            'dinner_carb_limit_g.between' => 'Indica un límite entre 0 y 300 g de carbohidratos.',
            'dinner_max_prep_minutes.between' => 'Indica un tiempo entre 1 y 120 minutos para la cena.',
            'dinner_calorie_limit.between' => 'Indica un tope entre 200 y 2000 kcal para la cena.',
        ];
    }
}
