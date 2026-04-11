<?php

namespace App\Http\Requests\TransactionYape;

use Illuminate\Foundation\Http\FormRequest;

class ImportYapeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file',
        ];
    }
}
