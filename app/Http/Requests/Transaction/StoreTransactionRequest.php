<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
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
            'yape_id' => 'nullable|integer|exists:transaction_yapes,id',
        ];
    }
}
