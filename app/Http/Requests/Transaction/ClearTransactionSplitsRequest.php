<?php

declare(strict_types=1);

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Volver a una sola categoría (o a ninguna) borrando el reparto.
 *
 * `category_id` es opcional: sin él la transacción queda sin categorizar, que es
 * un estado válido. Si viene, tiene que ser una categoría del usuario.
 */
class ClearTransactionSplitsRequest extends FormRequest
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
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->user()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'Esa categoría no existe o no te pertenece.',
        ];
    }
}
