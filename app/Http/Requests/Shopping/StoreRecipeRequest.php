<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\Unit;
use App\Models\Product;
use App\Services\Shopping\UnitConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecipeRequest extends FormRequest
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
        $userId = (int) $this->user()?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('recipes', 'name')->where(
                    static fn ($query) => $query->where('user_id', $userId)
                ),
            ],
            'base_servings' => ['required', 'integer', 'between:1,20'],
            'meal_types' => ['required', 'array', 'min:1'],
            'meal_types.*' => ['string', 'distinct', Rule::in(['breakfast', 'lunch', 'dinner'])],
            'prep_minutes' => ['nullable', 'integer', 'between:1,240'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where(
                    static fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId)
                ),
            ],
            'ingredients.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'ingredients.*.unit' => ['required', 'string', Rule::in(Unit::values())],
            'ingredients.*.is_optional' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ingredients = $this->input('ingredients', []);
            if (! is_array($ingredients)) {
                return;
            }

            $userId = (int) $this->user()?->id;
            $productIds = collect($ingredients)
                ->pluck('product_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->where(static function ($query) use ($userId): void {
                    $query->whereNull('user_id')->orWhere('user_id', $userId);
                })
                ->get()
                ->keyBy('id');

            foreach ($ingredients as $index => $ingredient) {
                if (! is_array($ingredient)) {
                    continue;
                }

                $product = $products->get((int) ($ingredient['product_id'] ?? 0));
                if ($product === null) {
                    continue;
                }

                $unit = (string) ($ingredient['unit'] ?? '');
                $productUnit = (string) $product->getAttribute('unit');

                if ($unit !== '' && ! UnitConverter::compatible($unit, $productUnit)) {
                    $validator->errors()->add(
                        "ingredients.{$index}.unit",
                        "La unidad {$unit} no es compatible con la unidad del producto ({$productUnit})."
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la receta.',
            'name.unique' => 'Ya tienes una receta con ese nombre.',
            'meal_types.required' => 'Elige al menos una comida.',
            'meal_types.*.in' => 'Las comidas válidas son desayuno, almuerzo y cena.',
            'ingredients.required' => 'Agrega al menos un ingrediente.',
            'ingredients.*.product_id.exists' => 'Algún ingrediente no existe o no está en tu catálogo.',
            'ingredients.*.quantity.gt' => 'Las cantidades deben ser mayores a 0.',
            'ingredients.*.unit.in' => 'La unidad del ingrediente no es válida.',
        ];
    }
}
