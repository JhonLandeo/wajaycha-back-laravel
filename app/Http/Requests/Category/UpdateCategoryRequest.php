<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'pareto_classification_id' => 'required|exists:pareto_classifications,id',
            'monthly_budget' => 'required|numeric|min:0',
            'type' => 'required|in:income,expense',
        ];
    }
}
