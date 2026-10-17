<?php

declare(strict_types=1);

namespace App\Http\Requests\PaydayPlan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The four values a user declares in their payday plan. All required: the system
 * never proposes or defaults a value (PS-1). The day stops at 28 so that every
 * month, February included, has a payday.
 */
class UpdatePaydayPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'payday_day' => ['required', 'integer', 'between:1,28'],
            // numeric(15,2) in the database: 13 integer digits at most.
            'declared_salary' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'savings_percent' => ['required', 'integer', 'between:1,100'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
