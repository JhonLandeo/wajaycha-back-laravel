<?php

namespace App\Http\Requests\CategoryRule;

use Illuminate\Foundation\Http\FormRequest;

class SyncRuleRequest extends FormRequest
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
            'detail_id' => 'required|integer|exists:details,id',
        ];
    }
}
