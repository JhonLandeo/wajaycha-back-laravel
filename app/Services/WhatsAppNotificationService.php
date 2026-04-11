<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function sendTextMessage(string $phoneNumber, string $text): void
    {
        $token = config('services.whatsapp.access_token');
        $phoneId = config('services.whatsapp.phone_id');

        $response = Http::withToken($token)->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => 'text',
            'text' => ['body' => $text]
        ]);

        if (!$response->successful()) {
            Log::error("Error enviando WhatsApp: " . $response->body());
        }
    }
}
