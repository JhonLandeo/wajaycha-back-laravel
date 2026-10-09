<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\AcquisitionSource;
use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePantryItemRequest extends FormRequest
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
                // Same composed technique StoreCategoryRequest uses for
                // parent_id, scoped to what this user can SEE rather than to
                // what they own: the global catalogue plus their own
                // additions (design.md D3).
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
                ),
            ],
            'quantity' => ['required', 'numeric'],
            'unit' => ['required', 'string', $this->matchesProductUnit()],
            'acquisition_source' => ['required', Rule::enum(AcquisitionSource::class)],
            'acquired_on' => ['sometimes', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Rejects a unit that does not match the referenced product's canonical
     * unit exactly — never a silent conversion (spec.md "Pantry item
     * recording", design.md D4). Silently skips when the product itself is
     * missing or invisible: the `product_id` rule already fails that case on
     * its own.
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
