<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The manual override half of design.md D6 — scopes `Rule::exists` to the
 * caller's own categories so a stranger's `category_id` can never be pinned
 * (spec.md "Per-user isolation on scoped resources").
 */
class UpdateGroceryBudgetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->user()?->id),
            ],
        ];
    }
}
