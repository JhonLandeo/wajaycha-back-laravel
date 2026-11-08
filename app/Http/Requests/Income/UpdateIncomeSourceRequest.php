<?php

declare(strict_types=1);

namespace App\Http\Requests\Income;

use App\Enums\IncomeSourceKind;
use App\Models\IncomeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateIncomeSourceRequest extends FormRequest
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
        $source = $this->route('income_source');
        $sourceId = $source instanceof IncomeSource ? $source->id : (int) $source;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('income_sources', 'name')
                    ->where(static fn ($query) => $query->where('user_id', (int) Auth::id()))
                    ->ignore($sourceId),
            ],
            'kind' => ['required', Rule::enum(IncomeSourceKind::class)],
        ];
    }
}
