<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
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
            'amount' => 'required|numeric|min:0',
            'date_operation' => 'required|date',
            'type_transaction' => 'required|string|in:expense,income',
            'category_id' => 'nullable|integer|exists:categories,id',
            'detail_id' => 'nullable|integer|exists:details,id',
            'detail_description' => 'nullable|string|max:255',
            'is_frequent' => 'required|boolean',
        ];
    }
}
