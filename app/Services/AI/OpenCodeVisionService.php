<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\WhatsApp\ParsedReceiptDTO;

class OpenCodeVisionService
{
    public function __construct(private readonly OpenCodeGateway $gateway) {}

    public function parseReceipt(string $imageBytes, string $mimeType): ?ParsedReceiptDTO
    {
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

        $data = $this->gateway->generateJson([
            [
                'parts' => [
                    ['text' => $systemPrompt],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Image,
                        ],
                    ],
                ],
            ],
        ]);

        return $data === null ? null : ParsedReceiptDTO::fromModel($data);
    }
}
