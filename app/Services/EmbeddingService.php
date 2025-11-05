<?php

namespace App\Services;

use Gemini\Laravel\Facades\Gemini; // 1. Usar el Facade de Gemini
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    /**
     * Genera un embedding vectorial para un texto usando Gemini.
     *
     * @param string $text
     * @return array<float>|null
     */
    public function generate(string $text): ?array
    {
        try {
            $model = Gemini::embeddingModel('models/embedding-001');
            // @phpstan-ignore-next-line
            $response = $model->embedContent($text);

            if (isset($response->embedding->values)) {
                return $response->embedding->values;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error al generar embedding con Gemini: ' . $e->getMessage());
            return null;
        }
    }
}
