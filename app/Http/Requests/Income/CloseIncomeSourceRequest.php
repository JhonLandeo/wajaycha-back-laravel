<?php

declare(strict_types=1);

namespace App\Http\Requests\Income;

use Illuminate\Foundation\Http\FormRequest;

class CloseIncomeSourceRequest extends FormRequest
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
            'effective_to' => ['required', 'date_format:Y-m'],
        ];
    }
}
