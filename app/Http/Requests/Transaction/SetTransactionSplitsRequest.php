<?php

declare(strict_types=1);

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La escritura del reparto.
 *
 * `min:2` porque una sola línea no es un reparto: para volver a una categoría
 * existe el endpoint de borrado. La suma se valida en la Action, donde ya se
 * conoce el monto real de la transacción y se puede comparar en centavos.
 */
class SetTransactionSplitsRequest extends FormRequest
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
            'splits' => ['required', 'array', 'min:2', 'max:50'],
            'splits.*.category_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('categories', 'id')->where('user_id', $this->user()?->id),
            ],
            'splits.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'splits.required' => 'Indica cómo se reparte el movimiento.',
            'splits.array' => 'El reparto no tiene el formato esperado.',
            'splits.min' => 'El reparto debe tener al menos dos categorías.',
            'splits.max' => 'El reparto admite como máximo 50 categorías.',
            'splits.*.category_id.required' => 'Cada línea del reparto necesita una categoría.',
            'splits.*.category_id.distinct' => 'No repitas una categoría en el mismo reparto.',
            'splits.*.category_id.exists' => 'Alguna categoría no existe o no te pertenece.',
            'splits.*.amount.required' => 'Cada línea del reparto necesita un monto.',
            'splits.*.amount.gt' => 'Cada monto del reparto debe ser mayor que cero.',
            'splits.*.amount.decimal' => 'Cada monto admite como máximo dos decimales.',
        ];
    }
}
