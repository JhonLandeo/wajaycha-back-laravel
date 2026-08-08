<?php

namespace App\Http\Requests\Detail;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
            // Scoped to the caller: a bare `exists:categories,id` accepts any category in
            // the system, letting a user point their own Detail at someone else's.
            'last_used_category_id' => [
                'sometimes',
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', (int) Auth::id()),
            ],
        ];
    }
}
