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

class SmartMergeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::info("--- INICIANDO JUEZ IA (Modo Asimétrico + Sin Yape) ---");

        $candidates = DB::select("
            WITH new_incoming AS (
                SELECT id, description, entity_clean, operation_type
                FROM details 
                WHERE ai_reviewed_at IS NULL
                AND operation_type != 'YAPE'
                AND length(entity_clean) > 2
                LIMIT 50
            )
            SELECT 
                c1.id as id_old, c1.description as desc_old, 
                c2.id as id_new, c2.description as desc_new
            FROM details c1
            JOIN new_incoming c2 ON c1.id < c2.id
            WHERE 
                c1.operation_type = c2.operation_type
                AND c1.operation_type != 'YAPE'
                AND (
                    similarity(c1.entity_clean, c2.entity_clean) BETWEEN 0.45 AND 0.95
                )
            ORDER BY c2.id DESC
            LIMIT 15
        ");

        if (empty($candidates)) {
            Log::info("No hay nuevos candidatos pendientes (excluyendo Yapes).");
            return;
        }

        $pairs = [];
        foreach ($candidates as $row) {
            $pairs[] = [
                'id' => "{$row->id_old}|{$row->id_new}",
                'A' => $row->desc_old,
                'B' => $row->desc_new
            ];
        }

        $prompt = $this->buildExpertPrompt(json_encode($pairs));

        try {
            $modelName = 'gemini-1.5-flash';
            $response = Gemini::generativeModel($modelName)->generateContent($prompt);
            $jsonText = str_replace(['```json', '```'], '', $response->text());
            $decisions = json_decode($jsonText, true);
        } catch (\Exception $e) {
            Log::error("Gemini Error: " . $e->getMessage());
            return;
        }

        foreach ($candidates as $row) {
            $key = "{$row->id_old}|{$row->id_new}";
            $shouldMerge = false;

            if (isset($decisions['matches'])) {
                foreach ($decisions['matches'] as $match) {
                    if ($match['id'] === $key && $match['is_duplicate'] === true) {
                        $shouldMerge = true;
                        break;
                    }
                }
            }

            if ($shouldMerge) {
                $this->mergeDetails($row->id_old, $row->id_new);
                Log::info("FUSIONADO: '{$row->desc_new}' -> '{$row->desc_old}'");
            } else {
                DB::table('details')
                    ->where('id', $row->id_new)
                    ->update(['ai_reviewed_at' => now(), 'ai_verdict' => 'DISTINCT']);
            }
        }
    }

    /**
     * El Prompt Maestro ajustado a tus datos peruanos
     */
    private function buildExpertPrompt(string $jsonData): string
    {
        return <<<EOT
        Eres un experto auditor de datos bancarios de Perú. Tu objetivo es deduplicar descripciones de transacciones.
        Recibirás pares de textos (A y B). Decide si se refieren a la misma entidad/persona.

        REGLAS DE ORO (Estrictas):
        1. **Nombres Enmascarados:** Si uno tiene asteriscos o está cortado (ej: "Rosa Ney*", "Juan P.", "Lidia Meg*") y el otro es el nombre completo que coincide al inicio (ej: "Rosa E. Neyra A.", "Juan Perez", "Lidia Mego"), **SON DUPLICADOS (true)**.
        2. **Variaciones de Nombres:** "Juan M. Gomez Q." es igual a "Juan Manuel Gomez Quispe". (true)
        3. **Negocios con Prefijos:** "IZI*BAGUETERIA SOLANGE" es igual a "BAGUETERIA SOLANGE EIRL". Ignora "IZI", "NIUBIZ", "CULQI". (true)
        4. **Yape/Plin:** - Si tienen el mismo nombre (o variación), son true.
        - Si tienen números de teléfono DIFERENTES visibles (ej: "Yape a 999" vs "Yape a 888"), son **FALSE**.
        5. **Mantenimiento:** "MANT. CUENTA SET24" es el mismo concepto que "MANT. CUENTA AGO24". (true)

        FORMATO DE SALIDA (JSON Puro):
        {
            "matches": [
                { "id": "id|id", "is_duplicate": true, "reason": "Nombre enmascarado coincide" },
                { "id": "id|id", "is_duplicate": false, "reason": "Apellidos distintos" }
            ]
        }

        DATOS A ANALIZAR:
        $jsonData
        EOT;
    }

    private function mergeDetails(int $masterId, int $duplicateId): void
    {
        DB::transaction(function () use ($masterId, $duplicateId) {
            DB::table('transactions')->where('detail_id', $duplicateId)->update(['detail_id' => $masterId]);
            DB::table('transaction_yapes')->where('detail_id', $duplicateId)->update(['detail_id' => $masterId]);

            DB::table('categorization_rules')->where('detail_id', $duplicateId)->delete();

            DB::table('details')->where('id', $duplicateId)->delete();

            DB::table('details')->where('id', $masterId)
                ->update(['ai_reviewed_at' => now(), 'ai_verdict' => 'MERGED']);
        });
    }
}
