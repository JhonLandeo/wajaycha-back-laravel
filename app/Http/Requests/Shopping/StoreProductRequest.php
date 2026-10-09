<?php

declare(strict_types=1);

namespace App\Http\Requests\Shopping;

use App\Enums\Unit;
use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', $this->noVisibleNameCollision()],
            'unit' => ['required', Rule::enum(Unit::class)],
        ];
    }

    /**
     * Rejects a name that collides case-insensitively with a product this
     * user can already see — the shared catalogue plus their own prior
     * additions — so nobody can shadow the global `Palta` with their own
     * `palta` (design.md D3).
     */
    private function noVisibleNameCollision(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $userId = $this->user()?->id;

            $collides = Product::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId))
                ->exists();

            if ($collides) {
                $fail('Ya existe un producto con ese nombre en tu catálogo.');
            }
        };
    }
}
