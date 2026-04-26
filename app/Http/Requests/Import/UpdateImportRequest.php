<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\ImportStatus;
use Illuminate\Validation\Rules\Enum;

class UpdateImportRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'status' => ['sometimes', 'string', new Enum(ImportStatus::class)],
        ];
    }
}
