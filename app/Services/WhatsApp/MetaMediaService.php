<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Support\OutboundHttp;
use Illuminate\Support\Facades\Log;

class MetaMediaService
{
    /**
     * Downloads an image from Meta Graph API by media ID.
     *
     * @return array{bytes: string, mimeType: string}|null
     */
    public function downloadMedia(string $mediaId): ?array
    {
        Log::info("📥 WhatsApp: Descargando media {$mediaId} desde Meta...");

        $accessToken = $this->accessToken();

        $response = OutboundHttp::to('meta')
            ->withToken($accessToken)
            ->get("https://graph.facebook.com/v21.0/{$mediaId}");

        if (! $response->successful()) {
            Log::error('❌ WhatsApp: Error al obtener URL de media. Respuesta: '.$response->body());

            return null;
        }

        $mediaUrl = $response->json('url');
        $mimeType = $response->json('mime_type') ?? 'image/jpeg';

        if (! $mediaUrl) {
            Log::error('❌ WhatsApp: No se encontró URL en la respuesta de Meta.');

            return null;
        }

        $download = OutboundHttp::to('meta')->withToken($accessToken)->get($mediaUrl);

        // Esta descarga no miraba su propio status: `->body()` sobre una
        // respuesta 500 devuelve el cuerpo del error, y ese texto seguia viaje
        // hasta Gemini como si fueran los bytes de una imagen. Una llamada
        // gastada para leer un mensaje de error de Meta.
        if (! $download->successful() || $download->body() === '') {
            Log::error("❌ WhatsApp: falló la descarga del media {$mediaId}.");

            return null;
        }

        Log::info('⬇️ Media descargada exitosamente.');

        return [
            'bytes' => $download->body(),
            'mimeType' => $mimeType,
        ];
    }

    /**
     * Read at call time, not at construction.
     *
     * Constructing a service must not require credentials: the container builds
     * every registered capture channel to answer for one of them, so a service
     * that demands its token to exist takes unrelated channels down with it when
     * the token is absent. `TelegramChannel::botToken()` already reads this way.
     */
    private function accessToken(): string
    {
        return (string) config('services.whatsapp.access_token');
    }
}
