<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\MealEntryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMealEntryStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(MealEntryStatus::class)],
        ];
    }
}
