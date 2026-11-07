<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\OutboundHttp;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;

/**
 * The single door to OpenCode Go.
 *
 * OpenCode serves every model through three API families and translates
 * between them. This class uses the Gemini family because it is the one whose
 * request and response shape the capture pipeline already speaks: the same
 * `contents.parts[]` body and the same `candidates[0].content.parts[0].text`
 * reply. The only change from calling Google directly is the host and that the
 * credential travels in `Authorization: Bearer` instead of in `?key=`.
 */
final class OpenCodeGateway
{
    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, mixed>  $generationConfig
     */
    public function generate(array $contents, array $generationConfig = []): ?string
    {
        $apiKey = trim((string) config('services.opencode.api_key'));

        if ($apiKey === '') {
            // Sin clave no hay red que intentar. Decirlo aca evita un 401 por
            // cada movimiento y deja el motivo en el log en vez de en la cara
            // del usuario.
            Log::error('❌ OpenCode: OPENCODE_API_KEY no está configurada.');

            return null;
        }

        $url = rtrim((string) config('services.opencode.base_url'), '/')
            .'/google/v1beta/models/'.config('services.opencode.model').':generateContent';

        $response = OutboundHttp::to('inference')
            ->withToken($apiKey)
            ->withHeaders([
                'x-opencode-session' => (string) config('services.opencode.session_id'),
                'User-Agent' => (string) config('services.opencode.user_agent'),
            ])
            ->post($url, [
                'contents' => $contents,
                'generationConfig' => $generationConfig,
            ]);

        if (! $response->successful()) {
            // El cuerpo puede repetir la credencial en un mensaje de error del
            // gateway. Se sanea por valor, igual que el resto de las salidas.
            Log::error('❌ OpenCode Error: '.Redact::secrets($response->body()));

            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>|null
     */
    public function generateJson(array $contents, array $generationConfig = []): ?array
    {
        $text = $this->generate(
            $contents,
            $generationConfig + ['responseMimeType' => 'application/json'],
        );

        return $text === null ? null : $this->decodeJson($text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $text): ?array
    {
        $text = trim($text);

        // Algunos modelos envuelven el JSON en un bloque Markdown aunque se les
        // pida JSON puro. Quitarlo es mas barato que perder una captura por dos
        // backticks.
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

        $decoded = json_decode(trim($text), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Ultimo recurso: recortar desde la primera llave hasta la ultima. Un
        // preambulo corto no deberia costar un movimiento.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            Log::error('❌ OpenCode: la respuesta no contiene JSON válido.');

            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            Log::error('❌ OpenCode: la respuesta no contiene JSON válido.');

            return null;
        }

        return $decoded;
    }
}
