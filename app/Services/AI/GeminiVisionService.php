<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiVisionService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
    }

    public function parseReceipt(string $imageBytes, string $mimeType): ?ParsedReceiptDTO
    {
        $url = "{$this->baseUrl}?key={$this->apiKey}";
        $base64Image = base64_encode($imageBytes);

        $systemPrompt = '
            Eres un asistente financiero. Lee y extrae los datos del recibo o comprobante (Yape/Plin, etc.) de la imagen adjunta en un JSON válido.
            - Si dice "¡Yapeaste!" o "Pago a", origin es el "Usuario", destination es a quién le paga.
            - Si dice "Te yapeó", destination es el "Usuario", origin es quien paga. Deduce o extrae el nombre exacto de la persona o negocio. NUNCA uses la palabra literal "contraparte".
            - IMPORTANTE: Si evalúas la imagen y determinas que NO ES un comprobante de pago o transferencia financiera válida (por ejemplo, es un meme, un chat, una foto aleatoria), debes devolver el JSON con "is_valid_receipt" en false.
            Estructura estricta:
            {
              "is_valid_receipt": (boolean, false si no parece ser un comprobante de pago válido, true si sí lo es),
              "amount": (decimal, ej: 2.50),
              "destination": (string),
              "origin": (string),
              "date_operation": (string, formato "YYYY-MM-DD HH:mm:ss", deduce el año actual si no está),
              "type_transaction": ("income" o "expense"),
              "message": (string, lo que haya en motivo o mensaje. Si no hay, pon vacío "")
            }';

        $response = Http::post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $base64Image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => ['responseMimeType' => 'application/json']
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
