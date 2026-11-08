<?php

declare(strict_types=1);

namespace App\Http\Requests\Income;

use App\Enums\IncomeSourceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreIncomeSourceRequest extends FormRequest
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
        $userId = (int) Auth::id();

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('income_sources', 'name')->where(
                    static fn ($query) => $query->where('user_id', $userId)
                ),
            ],
            'kind' => ['required', Rule::enum(IncomeSourceKind::class)],
            // numeric(15,2): 13 digitos enteros como maximo.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'effective_from' => ['required', 'date_format:Y-m'],
        ];
    }
}
