<?php

namespace App\Http\Requests\ParetoClassification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateParetoClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'percentage' => 'required|numeric|between:0,100',
        ];
    }
}
