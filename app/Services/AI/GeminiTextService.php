<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiTextService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
    }

    public function parseText(string $text): ?ParsedReceiptDTO
    {
        $url = "{$this->baseUrl}?key={$this->apiKey}";

        $systemPrompt = '
            Eres un asistente financiero avanzado. Lee la narración o el comando de texto del usuario sobre un gasto o ingreso y extrae los datos en un JSON válido.
            - Si el usuario dice que gastó, pagó, compró o yapeó, es un "expense". El "origin" es el "Usuario" y "destination" es la entidad (tienda, empresa, negocio o persona a la que se le paga).
            - Si el usuario dice que recibió, cobró o le pagaron (ej: "me pagaron", "me yapearon"), es un "income". El "destination" es el "Usuario" y "origin" es la entidad que le envía el dinero. Deduce su nombre o perfil (ej: "Cliente", "Alumno", "Familiar"). NUNCA respondas literalmente la palabra "contraparte".
            - IMPORTANTE: Si el mensaje es ambiguo, le falta el monto, no describe una transacción financiera, o es un saludo u otro tema, asume "is_valid_transaction" en false.
            Estructura estricta:
            {
              "is_valid_transaction": (boolean, false si no es una operación financiera válida o falta monto, true si es válida),
              "amount": (decimal, ej: 2.50. Siempre numérico positivo),
              "destination": (string),
              "origin": (string),
              "date_operation": (string, formato "YYYY-MM-DD HH:mm:ss", deduce según la fecha actual si dice "hoy" o "ayer", si no dice nada usa hoy),
              "type_transaction": ("income" o "expense"),
              "message": (string, motivo adicional mencionado, ejemplo: almuerzo, cine, etc. Si no aplica, pon "")
            }
        ';

        $finalPrompt = $systemPrompt . "\n\nFecha actual de referencia: " . Carbon::now()->toDateTimeString() . "\n\nMensaje enviado por el usuario: \"" . $text . "\"";

        $response = Http::post($url, [
            'contents' => [
                ['parts' => [['text' => $finalPrompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ]);

        if (!$response->successful()) {
            Log::error("❌ Gemini Error: " . $response->body());
            return null;
        }

        $data = $response->json();
        $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$jsonString) {
            return null;
        }

        return ParsedReceiptDTO::fromGemini(json_decode($jsonString, true));
    }
}
