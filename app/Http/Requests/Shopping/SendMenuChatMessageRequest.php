<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class SendMenuChatMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Escribe qué quieres ajustar del menú.',
            'message.max' => 'El mensaje es demasiado largo.',
        ];
    }
}
