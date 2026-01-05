<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Data\GenerationConfig;
use Gemini\Enums\ResponseMimeType;

class SmartMergeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $timeLimit = 100; // Segundos

    public function handle(): void
    {
        $startTime = time();
        Log::info("🏁 [START] JUEZ IA - MODO RACIMO (CLUSTER) ----------------");

        do {
            $pivot = DB::table('details')
                ->whereNull('ai_reviewed_at')
                ->where('operation_type', '!=', 'YAPE')
                ->where('operation_type', '!=', 'MANTENIMIENTO')
                ->whereRaw('length(entity_clean) > 2')
                ->orderBy('id', 'desc')
                ->first();

            if (!$pivot) {
                Log::info("🛑 [STOP] No quedan registros líderes por revisar.");
                break;
            }

            $candidates = DB::select("
                SELECT id, description, entity_clean
                FROM details
                WHERE 
                    id != ? 
                    AND length(entity_clean) > 2
                    AND similarity(entity_clean, ?) BETWEEN 0.3 AND 1
                LIMIT 15
            ", [$pivot->id, $pivot->entity_clean]);


            if (empty($candidates)) {
                $this->markAsReviewed($pivot->id);
                Log::info("⏩ [SKIP] Líder ID {$pivot->id} es único. Avanzando...");
            } else {
                Log::info("🔍 [CLUSTER] Líder ID {$pivot->id} ('{$pivot->description}') vs " . count($candidates) . " candidatos.");
                Log::info('candidates: ' . var_export($candidates, true));
                $this->processCluster($pivot, $candidates);
            }
        } while (time() - $startTime < $this->timeLimit);
        Log::info("⏱️ [TIEMPO] Transcurrido: " . (time() - $startTime) . "s");

        Log::info("🏁 [END] Trabajo finalizado.");
    }

    private function processCluster($pivot, array $candidates): void
    {
        $candidatesData = [];
        foreach ($candidates as $cand) {
            $candidatesData[] = [
                'id' => $cand->id,
                'desc' => $cand->description
            ];
        }

        $prompt = $this->buildClusterPrompt($pivot->description, json_encode($candidatesData));

        try {
            $modelName = 'gemini-2.0-flash';

            $response = Gemini::generativeModel($modelName)
                ->withGenerationConfig(
                    new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON),
                )
                ->generateContent($prompt);

            Log::info("🤖 [IA RESPUESTA] " . $response->text());

            $jsonText = str_replace(['```json', '```'], '', $response->text());
            $decisions = json_decode($jsonText, true);
        } catch (\Exception $e) {
            Log::error("❌ [API ERROR] " . $e->getMessage());
            return;
        }

        // ------------------------------------------------------------
        // PROCESAMIENTO DE RESPUESTA
        // ------------------------------------------------------------
        $duplicates = $decisions['duplicates'] ?? [];

        Log::info("📥 [IA] Encontró " . count($duplicates) . " duplicados para el líder.");

        foreach ($duplicates as $match) {
            $duplicateId = $match['id'];

            if ($duplicateId != $pivot->id && DB::table('details')->where('id', $duplicateId)->exists()) {
                $this->mergeDetails($pivot->id, $duplicateId);

                Log::info("✨ [FUSION] ID $duplicateId -> Líder {$pivot->id}. Razón: " . ($match['reason'] ?? 'IA'));
            }
        }

        $this->markAsReviewed($pivot->id);
    }

    private function buildClusterPrompt(string $masterDesc, string $candidatesJson): string
    {
        return <<<EOT
        Actúa como un Motor de Comparación de Textos Bancarios (Strict Logic Mode).
        Compara un MAESTRO contra CANDIDATOS.

        MAESTRO: "$masterDesc"

        ALGORITMO DE VERIFICACIÓN (Ejecuta en orden estricto):

        PASO 1: LIMPIEZA
        - Ignora: "PLIN", "YQ-", "IZI*", "CULQI", "NIUBIZ".
        - "PLIN - Juan Manuel" -> "Juan Manuel".

        PASO 2: PRIMER NOMBRE (EL FILTRO MAYOR)
        - Si el primer nombre limpio es distinto ("Ada" vs "America"), **FALSO**.
        - Si es igual ("Juan" vs "Juan"), CONTINÚA al Paso 3.

        PASO 3: SEGUNDO NOMBRE / INICIAL (EL DETALLE MORTAL ⛔)
        - Aquí es donde NO debes fallar. Busca conflictos en el segundo nombre o inicial.
        - **Caso A (Conflicto de Iniciales):** "Juan P." vs "Juan M." -> **FALSO** (Pedro no es Manuel).
        - **Caso B (Conflicto Nombre-Inicial):** "Juan P." vs "Juan Manuel" -> **FALSO** (P no es M).
        - **Caso C (Coincidencia):** "Juan P." vs "Juan Pedro" -> **VERDADERO** (P es Pedro).
        - **Caso D (Ausencia):** "Juan Gomez" (sin inicial) vs "Juan P. Gomez" -> **VERDADERO** (Se asume falta de datos).

        PASO 4: APELLIDOS
        - Si pasó el Paso 3, verifica que los apellidos coincidan fonéticamente o por truncamiento.

        SALIDA JSON (Solo los que sobreviven al Paso 3):
        {
            "duplicates": [
                { "id": 37, "reason": "Juan P. coincide con Juan PEDRO (P=Pedro)" }
            ]
        }
        
        NOTA: Si el maestro es 'Juan P.' y el candidato es 'Juan M.', NO LO AGREGUES.

        CANDIDATOS:
        $candidatesJson
        EOT;
    }

    private function markAsReviewed(int $id): void
    {
        DB::table('details')
            ->where('id', $id)
            ->update([
                'ai_reviewed_at' => now(),
                'ai_verdict' => DB::raw("COALESCE(ai_verdict, 'DISTINCT')")
            ]);
    }

    private function mergeDetails(int $masterId, int $duplicateId): void
    {
        DB::transaction(function () use ($masterId, $duplicateId) {
            // 1. Heredar Categoría si el maestro tiene
            $masterCat = DB::table('categorization_rules')->where('detail_id', $masterId)->value('category_id');
            $updateData = ['detail_id' => $masterId];
            if ($masterCat) $updateData['category_id'] = $masterCat;

            // 2. Mover data
            DB::table('transactions')->where('detail_id', $duplicateId)->update($updateData);

            $yapeData = $updateData;
            $yapeData['updated_at'] = now();
            DB::table('transaction_yapes')->where('detail_id', $duplicateId)->update($yapeData);

            // 3. Borrar duplicado
            DB::table('categorization_rules')->where('detail_id', $duplicateId)->delete();
            DB::table('details')->where('id', $duplicateId)->delete();
        });
    }
}
