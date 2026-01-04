<?php
namespace App\Services;

use Gemini\Laravel\Facades\Gemini;
use Illuminate\Support\Facades\Log;

class ClassificationService
{
    /**
     * Clasifica un detalle para ver si es útil para el aprendizaje de IA.
     */
    public function isDetailUsefulForLearning(string $description): bool
    {
        try {
           $prompt = $this->createPrompt($description);

            $model = Gemini::generativeModel('gemini-2.5-flash-lite');
            $response = $model->generateContent($prompt);
            
            $result = strtolower(trim($response->text()));

            return $result === 'util';

        } catch (\Exception $e) {
            Log::error('Error en ClassificationService: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea un prompt de "zero-shot" para la clasificación.
     */
    private function createPrompt(string $description): string
    {
        return <<<PROMPT
        Eres un asistente de finanzas. Tu trabajo es clasificar una descripción de transacción para decidir si es "UTIL" o "BASURA" para entrenar una IA.

        "UTIL" significa que la descripción es un comercio, un servicio o un lugar (ej. "Bodega Don Pepe", "Netflix", "Taxi al Centro").
        "BASURA" significa que la descripción es un pago genérico, un nombre de persona o un código (ej. "Yape a Juan Perez", "Plin a 912345678", "Pago YAPE 191953").

        Responde con una sola palabra: UTIL o BASURA.

        Descripción: "Bodega Don Pepe"
        Respuesta: UTIL

        Descripción: "Yape a Maria Lopez"
        Respuesta: BASURA

        Descripción: "Plin a 987654321"
        Respuesta: BASURA

        Descripción: "KFC Benavides"
        Respuesta: UTIL

        Descripción: "$description"
        Respuesta:
        PROMPT;
    }
}