<?php

namespace App\Http\Requests\CategoryRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SyncRuleRequest extends FormRequest
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
            // Scoped to the caller: a bare `exists:details,id` accepts any Detail in the
            // system, letting a user attach someone else's counterparty to their own rule.
            'detail_id' => [
                'required',
                'integer',
                Rule::exists('details', 'id')->where('user_id', (int) Auth::id()),
            ],
        ];
    }
}
