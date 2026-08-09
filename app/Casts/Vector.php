<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Translates between a PHP `array<float>` and pgvector's text form, `[1,2,3]`.
 *
 * Without it, writing an embedding does not work at all: `details.embedding` is
 * `vector(768)` and the only writer, `GenerateEmbeddingForDetail`, handed
 * Eloquent a raw PHP array. PDO cannot bind an array, so the value never
 * reached the column in a shape PostgreSQL could read. `CategorizationService`
 * already built the `[1,2,3]` string by hand — but only to *query* with, never
 * to store — so the formatting existed in the codebase and simply was not on
 * the write path.
 *
 * Living on the model rather than in the job means every writer gets it, which
 * is the point: the defect was one writer forgetting a detail the reader knew.
 *
 * The set side is declared `mixed` on purpose: the runtime guard below exists
 * precisely for callers that ignore the contract, and narrowing the generic
 * would make PHPStan treat that guard as dead code.
 *
 * @implements CastsAttributes<array<float>|null, mixed>
 */
final class Vector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $v): float => (float) $v, $value);
        }

        // pgvector prints `[1,2,3]`, which is also valid JSON.
        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_map(static fn (mixed $v): float => (float) $v, $decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Already in pgvector's text form — a raw query result written straight
        // back, or a caller that formatted it itself.
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "El atributo [{$key}] espera un array de floats o una cadena de pgvector."
            );
        }

        return '['.implode(',', array_map(static fn (mixed $v): float => (float) $v, $value)).']';
    }
}
