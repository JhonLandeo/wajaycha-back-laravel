<?php

declare(strict_types=1);

/**
 * `details.embedding` is `vector(768)` and `gemini-embedding-001` returns 3072
 * dimensions unless told otherwise, so every insert failed with
 * `expected 768 dimensions, not 3072`. Twenty-seven jobs in `failed_jobs`
 * between 2026-02-07 and 2026-04-06, and 0 of 1977 details ever got an
 * embedding — semantic categorisation was dead, not degraded.
 *
 * The request shape is asserted directly rather than inferred from a stored
 * vector, because the whole defect was in the request.
 */

use App\Services\EmbeddingService;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Responses\GenerativeModel\EmbedContentResponse;
use Illuminate\Support\Facades\Log;

/**
 * @param  array<float>  $values
 */
function embedResponse(array $values): EmbedContentResponse
{
    // `::from()` and not `::fake()`: the Fakeable trait merges the override into
    // a fixture key by key, so an empty `values` array falls back to the
    // fixture's own vector and the empty case cannot be expressed.
    return EmbedContentResponse::from(['embedding' => ['values' => $values]]);
}

/**
 * @return array<float>
 */
function unitlessVector(int $size, float $value = 3.0): array
{
    return array_fill(0, $size, $value);
}

it('le pide a Gemini exactamente las dimensiones que acepta la columna', function () {
    Gemini::fake([embedResponse(unitlessVector(EmbeddingService::DIMENSIONS))]);

    (new EmbeddingService)->generate('PANADERIA SAN MARTIN');

    // Leading backslash matters: `use ...Facades\Gemini` aliases the name, so
    // `Gemini\Resources\...` would resolve under the facade's namespace.
    // The recorded call is positional — ['X', taskType, title, dimensions].
    Gemini::assertSent(\Gemini\Resources\EmbeddingModel::class, null, function (string $method, array $parameters): bool {
        return $method === 'embedContent'
            && $parameters[3] === EmbeddingService::DIMENSIONS;
    });
});

it('la constante de dimensiones coincide con la columna vector(768)', function () {
    // Guards the pair that broke. Widening the column instead is not an option:
    // pgvector's HNSW and IVFFlat indexes stop at 2000 dimensions.
    expect(EmbeddingService::DIMENSIONS)->toBe(768);
});

it('devuelve un vector del tamaño de la columna', function () {
    Gemini::fake([embedResponse(unitlessVector(EmbeddingService::DIMENSIONS))]);

    $vector = (new EmbeddingService)->generate('BODEGA LA ESQUINA');

    expect($vector)->toHaveCount(768);
});

it('normaliza el vector a norma 1', function () {
    // MRL-truncated output arrives unnormalised. `DetailRepository` averages
    // these into a category centroid, and an average of vectors with different
    // magnitudes leans toward the longest one.
    Gemini::fake([embedResponse(unitlessVector(EmbeddingService::DIMENSIONS, 3.0))]);

    $vector = (new EmbeddingService)->generate('GRIFO PRIMAX');

    $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

    expect($norm)->toEqualWithDelta(1.0, 0.000001);
});

it('conserva la direccion al normalizar', function () {
    $raw = array_fill(0, EmbeddingService::DIMENSIONS, 0.0);
    $raw[0] = 4.0;
    $raw[1] = 3.0;

    Gemini::fake([embedResponse($raw)]);

    $vector = (new EmbeddingService)->generate('FARMACIA INKA');

    // 3-4-5 triangle: the direction survives, only the length changes.
    expect($vector[0])->toEqualWithDelta(0.8, 0.000001);
    expect($vector[1])->toEqualWithDelta(0.6, 0.000001);
});

it('devuelve null cuando el vector viene vacio en vez de romper', function () {
    Gemini::fake([embedResponse([])]);

    expect((new EmbeddingService)->generate('X'))->toBeNull();
});

it('devuelve null ante un vector de ceros en vez de guardarlo', function () {
    // pgvector devuelve NaN al medir distancia coseno contra un vector nulo, de
    // modo que un detalle guardado así envenena cualquier orden por similitud.
    Gemini::fake([embedResponse(array_fill(0, EmbeddingService::DIMENSIONS, 0.0))]);

    expect((new EmbeddingService)->generate('X'))->toBeNull();
});

it('no deja escapar el error de Gemini', function () {
    Gemini::fake([new \RuntimeException('quota exceeded')]);

    expect((new EmbeddingService)->generate('X'))->toBeNull();
});

/**
 * The service returns null on failure, so this log line is the only record that
 * an embedding was ever attempted. Both halves asserted, and in order.
 */
it('registra el motivo cuando Gemini falla', function () {
    Log::spy();
    Gemini::fake([new \RuntimeException('quota exceeded')]);

    (new EmbeddingService)->generate('X');

    Log::shouldHaveReceived('error')->withArgs(function (string $message): bool {
        $prefix = strpos($message, 'embedding');
        $reason = strpos($message, 'quota exceeded');

        return $prefix !== false && $reason !== false && $prefix < $reason;
    });
});
