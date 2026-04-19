<?php

namespace App\Http\Requests\Detail;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDetailRequest extends FormRequest
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
            'description' => 'sometimes|string|max:255',
            'last_used_category_id' => 'sometimes|nullable|exists:categories,id',
        ];
    }
}
