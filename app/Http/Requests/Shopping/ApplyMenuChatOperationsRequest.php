<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMenuChatOperationsRequest extends FormRequest
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
            'week_start' => ['required', 'date_format:Y-m-d'],
            'operations' => ['present', 'array', 'max:21'],
            'operations.*' => ['array'],
        ];
    }
}
