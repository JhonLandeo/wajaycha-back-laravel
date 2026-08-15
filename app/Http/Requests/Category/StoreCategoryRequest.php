<?php

namespace App\Http\Requests\Category;

use App\Enums\BudgetPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'pareto_classification_id' => 'required_if:type,expense|nullable|exists:pareto_classifications,id',
            'monthly_budget' => 'required|numeric|min:0',
            // Optional so every client that predates the column keeps working;
            // absent means 'monthly', which is what those clients already meant.
            'budget_period' => ['sometimes', Rule::enum(BudgetPeriod::class)],
            'type' => 'required|in:income,expense,transfer',
        ];
    }
}
