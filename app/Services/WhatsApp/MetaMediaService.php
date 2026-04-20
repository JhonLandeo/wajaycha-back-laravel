<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaMediaService
{
    protected string $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.whatsapp.access_token');
    }

    /**
     * Downloads an image from Meta Graph API by media ID.
     * 
     * @param string $mediaId
     * @return array{bytes: string, mimeType: string}|null
     */
    public function downloadMedia(string $mediaId): ?array
    {
        Log::info("📥 WhatsApp: Descargando media {$mediaId} desde Meta...");

        $response = Http::withToken($this->accessToken)
            ->get("https://graph.facebook.com/v21.0/{$mediaId}");

        if (!$response->successful()) {
            Log::error("❌ WhatsApp: Error al obtener URL de media. Respuesta: " . $response->body());
            return null;
        }

        $mediaUrl = $response->json('url');
        $mimeType = $response->json('mime_type') ?? 'image/jpeg';

        if (!$mediaUrl) {
            Log::error("❌ WhatsApp: No se encontró URL en la respuesta de Meta.");
            return null;
        }

        $mediaBytes = Http::withToken($this->accessToken)->get($mediaUrl)->body();
        Log::info("⬇️ Media descargada exitosamente.");

        return [
            'bytes' => $mediaBytes,
            'mimeType' => $mimeType
        ];
    }
}
