<?php

namespace App\Services;

use App\Support\OutboundHttp;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function sendTextMessage(string $phoneNumber, string $text): void
    {
        $token = config('services.whatsapp.access_token');
        $phoneId = config('services.whatsapp.phone_id');

        // Perfil de escritura: un reintento, no dos. Ver config/http.php.
        $response = OutboundHttp::to('meta_send')->withToken($token)->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => 'text',
            'text' => ['body' => $text],
        ]);

        if (! $response->successful()) {
            Log::error('Error enviando WhatsApp: '.$response->body());
        }
    }
}
