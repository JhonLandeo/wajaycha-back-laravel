<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class SwapMenuDaysRequest extends FormRequest
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
            'date_a' => ['required', 'date_format:Y-m-d', 'different:date_b'],
            'date_b' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_a.different' => 'Elige dos días distintos.',
        ];
    }
}
