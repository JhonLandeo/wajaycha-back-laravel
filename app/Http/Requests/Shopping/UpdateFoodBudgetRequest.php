<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFoodBudgetRequest extends FormRequest
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
            'monthly_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'shopping_day' => ['required', 'integer', 'between:1,7'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('categories', 'id')->where(
                    static fn ($query) => $query->where('user_id', $userId)->where('type', 'expense')
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_amount.required' => 'Define el presupuesto mensual de comida.',
            'monthly_amount.min' => 'El presupuesto no puede ser negativo.',
            'category_ids.required' => 'Elige al menos una categoría de comida.',
            'category_ids.min' => 'Elige al menos una categoría de comida.',
            'category_ids.*.exists' => 'Alguna categoría no existe, no es de gasto o no te pertenece.',
            'shopping_day.between' => 'El día de compra debe estar entre lunes y domingo.',
        ];
    }
}
