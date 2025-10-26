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
     * @return array|null
     */
    public function generate(string $text): ?array
    {
        try {
            // 1. Selecciona el modelo de embedding
            $model = Gemini::embeddingModel('models/embedding-001');

            // --- ESTA ES LA CORRECCIÓN ---
            // 2. Llama a embedContent(), no embedText()
            $response = $model->embedContent($text);

            // 3. El vector está en la propiedad "values"
            // (Asegurándonos de que la respuesta es válida)
            if (isset($response->embedding->values)) {
                return $response->embedding->values;
            }

            Log::warning('Respuesta de Gemini no contenía un embedding válido.');
            return null;
        } catch (\Exception $e) {
            Log::error('Error al generar embedding con Gemini: ' . $e->getMessage());
            return null;
        }
    }
}
