<?php

namespace App\Http\Requests\ParetoClassification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParetoClassificationRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pareto_classifications')
                    ->where('user_id', (int) $this->user()?->id)
                    ->ignore($this->route('pareto_classification')),
            ],
            'percentage' => 'required|numeric|between:0,100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una clasificación con ese nombre.',
        ];
    }
}
