<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\ProcessWhatsAppImage;
use Illuminate\Http\Response;

class WhatsAppController extends Controller
{
    public function verify(Request $request): Response
    {
        $verifyToken = config('services.whatsapp.verify_token');
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        $body = $request->all();

        if (isset($body['object']) && $body['object'] === 'whatsapp_business_account') {
            $message = $body['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

            if ($message) {
                $from = $message['from'];

                // CASO A: ES TEXTO
                if ($message['type'] === 'text') {
                    $text = $message['text']['body'];
                    ProcessWhatsAppMessage::dispatch($text, $from);
                }

                // CASO B: ES UNA IMAGEN (YAPE / PLIN)
                elseif ($message['type'] === 'image') {
                    $imageId = $message['image']['id'];
                    // Creamos un nuevo Job para imágenes
                    ProcessWhatsAppImage::dispatch($imageId, $from);
                }
            }
        }

        // Siempre devolvemos 200 OK de inmediato
        return response('EVENT_RECEIVED', 200);
    }
}
