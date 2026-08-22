<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one field a Google sign-in carries: the ID token the browser received.
 *
 * Validation here is deliberately shallow — length and type, nothing else. The
 * only opinion worth having about this string is whether Google signed it, and
 * that is a cryptographic question, not a `rules()` one. Anything this class
 * could check ahead of {@see \App\Services\Auth\GoogleIdTokenVerifier} would be a
 * check an attacker can satisfy for free.
 *
 * The ceiling exists so a megabyte of garbage is refused before it reaches a
 * base64 decode. Real Google ID tokens run well under a kilobyte.
 */
class GoogleLoginRequest extends FormRequest
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
            'credential' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credential.required' => 'Falta la credencial de Google.',
        ];
    }

    public function credential(): string
    {
        /** @var string $credential */
        $credential = $this->validated()['credential'];

        return $credential;
    }
}
