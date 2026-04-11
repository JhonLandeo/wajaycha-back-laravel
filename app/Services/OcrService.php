<?php

namespace App\Services;

use Aws\Textract\TextractClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OcrService
{
    protected TextractClient $client;

    public function __construct()
    {
        $this->client = new TextractClient([
            'region'  => config('services.aws.region', 'us-east-1'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws.key'),
                'secret' => config('services.aws.secret'),
            ],
        ]);
    }

    /**
     * Envía la imagen a AWS Textract y devuelve el texto plano.
     */
    public function getTextFromImage(string $imageBytes): string|null
    {
        try {
            $result = $this->client->analyzeDocument([
                'Document' => [
                    'Bytes' => $imageBytes, // Le pasamos el binario de la imagen
                ],
                'FeatureTypes' => ['FORMS'], // Esto ayuda a detectar pares clave-valor
            ]);

            /** @var array<int, array<string, mixed>> $blocks */
            $blocks = $result['Blocks'];

            // Unimos todas las líneas de texto detectadas en un solo bloque
            $rawText = collect($blocks)
                ->where('BlockType', 'LINE')
                ->pluck('Text')
                ->implode("\n");

            return $rawText;
        } catch (\Exception $e) {
            Log::error("Error en AWS Textract: " . $e->getMessage());
            return null;
        }
    }
}
