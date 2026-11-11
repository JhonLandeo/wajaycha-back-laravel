<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use Illuminate\Validation\Rule;

class UpdateRecipeRequest extends StoreRecipeRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $userId = (int) $this->user()?->id;
        $recipeId = (int) $this->route('recipe');

        $rules['name'] = [
            'required',
            'string',
            'max:120',
            Rule::unique('recipes', 'name')
                ->where(static fn ($query) => $query->where('user_id', $userId))
                ->ignore($recipeId),
        ];

        return $rules;
    }
}
