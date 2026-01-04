<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Detail;
class BackfillSmartDetails extends Command
{
    protected $signature = 'details:backfill-smart';

    protected $description = 'Clasifica transacciones, limpia nombres y repara Yapes enmascarados';

    public function handle(): void
    {
        $this->info('Iniciando reparación y clasificación inteligente...');

        $query = Detail::where('operation_type', 'UNKNOWN')
            ->orWhereNull('operation_type')
            ->orWhereNull('entity_clean');
        $total = $query->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(1000, function ($details) use ($bar) {
            foreach ($details as $detail) {

                $features = $this->analyze($detail->description);

                DB::table('details')
                    ->where('id', $detail->id)
                    ->update([
                        'operation_type' => $features['type'],
                        'entity_clean'   => $features['entity']
                    ]);

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('¡Proceso terminado con éxito!');
    }

    /**
     * Analiza una descripción y extrae sus características limpias.
     * @param string $text
     * @return array<string, string|null> ['type' => string, 'entity' => string|null]
     */
    private function analyze(string $text): array
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        $type = 'POS_GENERICO';

        // ---------------------------------------------------------
        // 1. CLASIFICACIÓN DE TIPO (El orden importa MUCHO)
        // ---------------------------------------------------------

        // A. Operaciones Bancarias Internas
        if (preg_match('/^(retiro|disp\.? efect|cajero)/i', $textLower)) {
            $type = 'RETIRO';
        } elseif (preg_match('/^(deposito|abono|transf\.*? a favor)/i', $textLower)) {
            // Cuidado: ABON PLIN debe ser PLIN, no DEPOSITO. Verificamos eso abajo.
            if (!str_contains($textLower, 'plin')) {
                $type = 'DEPOSITO';
            }
        } elseif (preg_match('/^(mant\.|comis\.|itf|estado de cta|seg\.|membresia)/i', $textLower)) {
            $type = 'MANTENIMIENTO';
        }

        // B. Transferencias Directas (BCP, Interbancarias)
        elseif (preg_match('/^(bcp\s*-|tran\.ctas|transf\.)/i', $textLower)) {
            $type = 'TRANSFERENCIA';
        }

        // C. Pasarelas de Pago (Niubiz, Izi, Culqi)
        // PRIORIDAD ALTA: Detectar IZI antes que YAPE para evitar que "YQ-IZI" sea Yape.
        elseif (preg_match('/(izi\*|niubiz|vendemas)/i', $textLower)) {
            $type = 'POS_NIUBIZ';
        } elseif (preg_match('/(culqi)/i', $textLower)) {
            $type = 'POS_CULQI';
        }

        // D. Billeteras Digitales (Yape/Plin)
        // Permitimos prefijos como "ABON PLIN", "EXT PLIN"
        elseif (preg_match('/(plin)/i', $textLower)) {
            $type = 'PLIN';
        }
        // Yape normal o YQ (pero asegurando que no sea IZI, que ya pasó arriba)
        elseif (preg_match('/(yape|yq-)/i', $textLower)) {
            $type = 'YAPE';
        }

        // Corregir caso borde: Si capturó "ABON PLIN" como DEPOSITO, forzar a PLIN
        if (str_contains($textLower, 'plin')) {
            $type = 'PLIN';
        }

        // ---------------------------------------------------------
        // 2. LIMPIEZA DE "BASURA" (Entity Clean)
        // ---------------------------------------------------------
        $clean = $textLower;

        // Lista de prefijos a eliminar (Ordenados por longitud para borrar los más largos primero)
        $garbage = [
            // Prefijos compuestos largos
            'transf.bco.bbva',
            'tran.ctas.terc.bm',
            'tran.ctas.terc.hk',
            'tra o/cta - agente',
            'abono plin',
            'abon plin',
            'ext plin',
            'pago de impuestos',
            'bcp -',
            'bcp ', // Elimina "BCP -"
            'yq-izi*',
            'yq-izi', // Elimina prefijo doble
            'culqi qr mm*',
            'culqi qr',
            'culqi*',
            'izi*',
            'dlc*',
            'ebn*',
            'ext ebn*',
            'pago yape a',
            'pago yape de',
            'pago yape',
            'pago cred yape',
            'cobro credito yape',
            'retiro ag',
            'retiro cajero',
            'retiro efectivo',
            'deposito ag',
            'deposito efectivo',
            'google *',
            'facebk *',
            'yq-',
            'yape'
        ];

        // Borrado manual de frases específicas
        foreach ($garbage as $trash) {
            // Usamos str_replace o preg_replace simple para velocidad
            // Agregamos ^ o espacios para no borrar palabras a la mitad
            $clean = preg_replace('/(^|\s|[\*\-\.])' . preg_quote($trash, '/') . '/i', ' ', $clean);
        }

        // Eliminar Fechas (ENE24, SET25)
        $clean = preg_replace('/\b(ene|feb|mar|abr|may|jun|jul|ago|set|sep|oct|nov|dic)[a-z]*[\s\-\.]*?(20)?[0-9]{2}\b/i', '', $clean);

        // 3. NORMALIZACIÓN FINAL
        // Esto convierte "Rosa Ney*" en "rosa ney" y "IZI*BAGUETERIA" en "bagueteria"
        // Elimina todo lo que no sea letra o número.
        $clean = preg_replace('/[^a-z0-9\s]/', '', $clean);

        // Quitar espacios extra y trim
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        // Si quedó vacío (ej: "TRAN.CTAS.TERC.BM" se borró todo), intentar recuperar algo o dejar nulo
        if (strlen($clean) < 2) {
            // Si es una transferencia vacía, a veces no hay nada que hacer, es mejor NULL
            // para que no haga match con otro NULL.
            $clean = null;
        }

        return ['type' => $type, 'entity' => $clean];
    }
}
