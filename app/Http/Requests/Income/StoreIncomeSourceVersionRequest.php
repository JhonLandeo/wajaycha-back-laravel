<?php

declare(strict_types=1);

namespace App\Http\Requests\Income;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomeSourceVersionRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'effective_from' => ['required', 'date_format:Y-m'],
        ];
    }
}
