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

    private int $timeLimit = 10000; // Segundos

    public function handle(): void
    {
        $startTime = time();
        Log::info("🏁 [START] JUEZ IA - MODO RACIMO (CLUSTER) ----------------");

        do {
            $pivot = DB::table('details')
                ->whereNull('ai_reviewed_at')
                ->whereNotIn('operation_type', ['YAPE', 'MANTENIMIENTO'])
                ->whereRaw('length(entity_clean) > 2')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
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
                    AND length(description) > 2
                    AND similarity(description, ?) BETWEEN 0.3 AND 1
                LIMIT 15
            ", [$pivot->id, $pivot->description]);


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
            $modelName = 'gemini-2.5-pro';

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
            $reason = $match['reason'] ?? 'Similitud detectada por IA';

            if ($duplicateId != $pivot->id && DB::table('details')->where('id', $duplicateId)->exists()) {
                $this->mergeDetails($pivot->id, $duplicateId, $reason);

                Log::info("✨ [FUSION] ID $duplicateId -> Líder {$pivot->id}. Razón: " . ($match['reason'] ?? 'IA'));
            }
        }

        $this->markAsReviewed($pivot->id);
    }

    private function buildClusterPrompt(string $masterDesc, string $candidatesJson): string
    {
        return <<<EOT
        Actúa como un Analista de Datos Bancarios Experto (Entity Resolution).
        Tu misión es fusionar registros SOLO si se refieren inequívocamente a la misma entidad.

        MAESTRO: "$masterDesc"

        ALGORITMO DE VERIFICACIÓN (STRICT MODE):

        PASO 1: DECODIFICACIÓN Y LIMPIEZA
        - Elimina procesadores: "IZI*", "IZIPAY", "NIUBIZ", "CULQI", "YAPE", "PLIN".
        - Separa palabras pegadas: "IZISAN" -> "SAN".
        - **REGLA DE ORO DE PREFIJOS:** "SAN FERNANDO" es un nombre compuesto indivisible. NO es igual a "FERNANDO".
          - Ej: "SAN JUAN" != "JUAN".
          - Ej: "DON PEPE" != "PEPE".
          - Ej: "MARIA DEL PILAR" != "MARIA".

        PASO 2: DETECCIÓN DE TIPO (PERSONA vs NEGOCIO)
        - Si el MAESTRO parece un negocio (tiene "EIRL", "SAC", "BODEGA", "SAN ...") y el CANDIDATO es claramente una persona con nombre y dos apellidos, **DESCARTA INMEDIATAMENTE**.
        - Caso Real: "IZI*SAN FERNANDO L" (Negocio) vs "FERNANDO LUIS ORMENO MENDEZ" (Persona) -> **FALSO** (Son entidades distintas).

        PASO 3: ANÁLISIS DE LA LETRA FINAL (SUFIJOS)
        - En comercios, una letra suelta al final suele ser ubicación, NO inicial de segundo nombre.
        - "SAN FERNANDO L" vs "FERNANDO LUIS" -> **FALSO** (La 'L' es Lince/Local, no Luis).
        - "SAN FERNANDO L" vs "SAN FERNANDO LINCE" -> **VERDADERO** (L es abreviatura de Lince).

        PASO 4: COMPARACIÓN FONÉTICA
        - Solo si pasaste los filtros anteriores, compara los nombres limpios.

        SALIDA JSON (Solo positivos confirmados):
        {
            "duplicates": [
                { "id": 249, "reason": "SAN FERNANDO L coincide con SAN FERNANDO LINCE (Comercio + Ubicación)" }
            ]
        }
        
        NOTA IMPORTANTE: 
        - Si uno dice "SAN FERNANDO" y el otro solo "FERNANDO", es FALSO.
        - Si el id 155 es "FERNANDO LUIS...", recházalo porque "SAN FERNANDO" no es "FERNANDO".

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

    private function mergeDetails(int $masterId, int $duplicateId, string $reason): void
    {
        DB::transaction(function () use ($masterId, $duplicateId, $reason) {

            // 1. OBTENER DATOS DEL "MÁRTIR" ANTES DE TOCAR NADA
            // Usamos lockForUpdate para asegurar que nadie lo modifique mientras lo leemos
            $duplicateRow = DB::table('details')->where('id', $duplicateId)->lockForUpdate()->first();

            if (!$duplicateRow) return; // Ya no existe (seguridad)

            $txIds = DB::table('transactions')
                ->where('detail_id', $duplicateId)
                ->pluck('id')
                ->toArray();

            $yapeIds = DB::table('transaction_yapes')
                ->where('detail_id', $duplicateId)
                ->pluck('id')
                ->toArray();

            // 2. GUARDAR EN HISTORIAL (AUDITORÍA / BACKUP)
            DB::table('details_merge_history')->insert([
                'original_detail_id'    => $duplicateId,
                'target_detail_id'      => $masterId,
                'original_data'         => json_encode($duplicateRow),
                'moved_transaction_ids' => json_encode($txIds), // Guardamos los IDs
                'moved_yape_ids'        => json_encode($yapeIds), // Guardamos los IDs
                'merge_reason'          => $reason,
                'merged_at'             => now(),
            ]);

            // 3. HERENCIA DE CATEGORÍAS (Tu lógica original mejorada)
            $masterCat = DB::table('categorization_rules')->where('detail_id', $masterId)->value('category_id');
          
            $duplicateCat = DB::table('categorization_rules')->where('detail_id', $duplicateId)->value('category_id');
            $userId = DB::table('categorization_rules')->where('detail_id', $duplicateId)->value('user_id');
            if (!$masterCat && $duplicateCat) {
                DB::table('categorization_rules')->insert([
                    'detail_id' => $masterId,
                    'category_id' => $duplicateCat,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 4. MOVER TRANSACCIONES (Reasignar hijos)
            DB::table('transactions')
                ->where('detail_id', $duplicateId)
                ->update(['detail_id' => $masterId]);

            DB::table('transaction_yapes')
                ->where('detail_id', $duplicateId)
                ->update(['detail_id' => $masterId]);

            // 5. BORRADO FINAL (Ahora es seguro porque tenemos backup en merge_history)
            DB::table('categorization_rules')->where('detail_id', $duplicateId)->delete();
            DB::table('details')->where('id', $duplicateId)->delete();

            Log::info("🛡️ [BACKUP] ID $duplicateId guardado en merge_history antes de eliminar.");
        });
    }
}
