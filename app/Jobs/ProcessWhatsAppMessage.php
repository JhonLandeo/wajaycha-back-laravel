<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $text;
    protected $from;

    public function __construct($text, $from)
    {
        $this->text = $text;
        $this->from = $from;
    }

    public function handle()
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $systemPrompt = 'Eres un asistente financiero experto... (Tu prompt aquí)';
        $finalPrompt = $systemPrompt . "\n\nTexto a analizar: \"" . $this->text . "\"";

        // Usamos el cliente HTTP nativo de Laravel
        $response = Http::post($url, [
            'contents' => [
                ['parts' => [['text' => $finalPrompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($jsonString) {
                $transactionData = json_decode($jsonString, true);

                Transaction::create([
                    'amount' => $transactionData['amount'],
                    'destination' => $transactionData['destination'],
                    'origin' => $transactionData['origin'],
                    'type_transaction' => $transactionData['type_transaction'],
                    'date_operation' => $transactionData['date_operation'],
                    'message' => $transactionData['message'],
                ]);

                Log::info("✅ Transacción guardada desde WhatsApp: " . $transactionData['amount']);
            }
        } else {
            Log::error("❌ Error de Gemini: " . $response->body());
        }
    }
}
