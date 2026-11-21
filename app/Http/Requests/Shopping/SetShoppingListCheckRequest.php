<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetShoppingListCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(
                    static fn (Builder $query) => $query->where(
                        static fn (Builder $visibility) => $visibility
                            ->whereNull('user_id')
                            ->orWhere('user_id', $userId)
                    )
                ),
            ],
            'checked' => ['required', 'boolean'],
        ];
    }
}
