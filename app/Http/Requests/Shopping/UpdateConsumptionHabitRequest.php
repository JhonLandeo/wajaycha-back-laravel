<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Full-replace semantics, like UpdatePantryItemRequest: the caller resends
 * the whole editable shape rather than a sparse patch.
 */
class UpdateConsumptionHabitRequest extends FormRequest
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
        $userId = $this->user()?->id;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
                ),
            ],
            'weekly_quantity' => ['required', 'numeric'],
            'unit' => ['required', 'string', $this->matchesProductUnit()],
        ];
    }

    /**
     * Same no-silent-conversion rule StoreConsumptionHabitRequest enforces on
     * create — an update must not be able to detach the unit from the
     * product it now claims to describe.
     */
    private function matchesProductUnit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $product = Product::query()->find($this->input('product_id'));

            if ($product !== null && $product->unit !== $value) {
                $fail('La unidad no coincide con la unidad canonica del producto.');
            }
        };
    }
}
