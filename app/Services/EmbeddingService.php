<?php

namespace App\Services;

use Gemini\Laravel\Facades\Gemini; // 1. Usar el Facade de Gemini
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private const MODEL = 'gemini-embedding-001';

    /**
     * `details.embedding` es `vector(768)`.
     *
     * `gemini-embedding-001` devuelve 3072 dimensiones si no se le pide otra
     * cosa, así que cada inserción fallaba con
     * `expected 768 dimensions, not 3072` y el job quedaba en failed_jobs. El
     * resultado no era degradación: 0 de 1977 details llegaron a tener
     * embedding, o sea que la categorización semántica nunca funcionó.
     *
     * Se pide 768 en vez de ampliar la columna porque los índices HNSW e
     * IVFFlat de pgvector no soportan más de 2000 dimensiones: una columna
     * `vector(3072)` no se puede indexar y toda búsqueda por similitud pasa a
     * ser un scan secuencial.
     */
    public const DIMENSIONS = 768;

    /**
     * Genera un embedding vectorial para un texto usando Gemini.
     *
     * @return array<float>|null
     */
    public function generate(string $text): ?array
    {
        try {
            $model = Gemini::embeddingModel(self::MODEL);
            // @phpstan-ignore-next-line
            $response = $model->embedContent($text, outputDimensionality: self::DIMENSIONS);

            $values = $response->embedding->values ?? [];

            // Un vector vacío no se puede guardar en `vector(768)`: normalizarlo
            // devolvería `[]` y el insert volvería a fallar con un error de
            // dimensión, que es exactamente el modo de falla del que se viene.
            if ($values === []) {
                return null;
            }

            return $this->normalise($values);
        } catch (\Exception $e) {
            Log::error('Error al generar embedding con Gemini: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Lleva el vector a norma 1.
     *
     * `gemini-embedding-001` sólo entrega normalizada su salida de 3072
     * dimensiones; las truncadas por MRL, como esta, vienen sin normalizar.
     *
     * Importa por `DetailRepository`, que arma el centroide de una categoría
     * con `avg('embedding')`. Promediar vectores de magnitud dispar corre el
     * centroide hacia los más largos en lugar de hacia la dirección común.
     * La distancia coseno (`<=>`) por sí sola no se ve afectada por la escala,
     * pero el promedio sí.
     *
     * @param  array<float>  $vector
     * @return array<float>|null
     */
    private function normalise(array $vector): ?array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm <= 0.0) {
            // Un vector de ceros no tiene dirección. `<=>` contra él devuelve
            // NaN, así que ordenar por similitud con ese detalle guardado da
            // resultados sin sentido: no guardar nada es preferible.
            return null;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
