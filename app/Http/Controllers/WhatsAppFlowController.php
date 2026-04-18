<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;

class WhatsAppFlowController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Obtener datos en Base64 y decodificarlos a binario
        $encryptedAesKey = base64_decode($request->input('encrypted_aes_key'));
        $encryptedFlowData = base64_decode($request->input('encrypted_flow_data'));
        $initialVector = base64_decode($request->input('initial_vector'));

        // 2. Cargar la llave privada (asegúrate de que esté en storage/app/private.pem)
        $privateKeyPath = storage_path('app/private.pem');
        Log::info('privateKeyPath', [$privateKeyPath]);

        // Descifrar la llave AES usando phpseclib (Meta exige SHA256)
        $privateKey = PublicKeyLoader::load(file_get_contents($privateKeyPath))
            ->withHash('sha256')
            ->withMGFHash('sha256');

        $decryptedAesKey = $privateKey->decrypt($encryptedAesKey);

        // 3. Descifrar el Flow Data (usando AES-128-GCM nativo de PHP)
        // Meta coloca el "Tag" de autenticación en los últimos 16 bytes
        $tagLength = 16;
        $ciphertext = substr($encryptedFlowData, 0, -$tagLength);
        $tag = substr($encryptedFlowData, -$tagLength);

        $decryptedDataString = openssl_decrypt(
            $ciphertext,
            'aes-128-gcm',
            $decryptedAesKey,
            OPENSSL_RAW_DATA,
            $initialVector,
            $tag
        );

        $decryptedData = json_decode($decryptedDataString, true);

        // --- 4. MANEJAR EL PING ---
        if (isset($decryptedData['action']) && $decryptedData['action'] === 'ping') {

            // Lo que vamos a devolver
            $dataToEncrypt = [
                'data' => [
                    'status' => 'active'
                ]
            ];

            // 5. Cifrar la respuesta
            $jsonResponse = json_encode($dataToEncrypt);

            // ¡REGLA DE META!: El vector inicial de respuesta es el vector original invertido
            $flippedIv = ~$initialVector;

            $responseTag = '';
            $encryptedResponseBytes = openssl_encrypt(
                $jsonResponse,
                'aes-128-gcm',
                $decryptedAesKey,
                OPENSSL_RAW_DATA,
                $flippedIv,
                $responseTag
            );

            // Unimos el texto cifrado con su nuevo tag de autenticación
            $finalEncryptedData = $encryptedResponseBytes . $responseTag;

            // 6. Meta exige que la respuesta sea un STRING en Base64, texto plano, status 200
            return response(base64_encode($finalEncryptedData), 200)
                ->header('Content-Type', 'text/plain');
        }

        // --- Aquí iría el resto de la lógica para tus ingresos/gastos ---
        // if ($decryptedData['action'] === 'data_exchange') { ... }

        // Si no es ping y aún no manejas otra acción, devuelve un error 400 por ahora
        return response('Acción no soportada', 400);
    }
}
