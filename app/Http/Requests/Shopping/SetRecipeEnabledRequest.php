<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class SetRecipeEnabledRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
