<?php

namespace App\Services;

class TransactionAnalyzer
{
    /**
     * @param string $text
     * @return array<string, string>
     */
    public function analyze(string $text): array
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        $type = 'POS_GENERICO';

        if (preg_match('/^(retiro|disp\.? efect|cajero)/i', $textLower)) {
            $type = 'RETIRO';
        } elseif (preg_match('/^(deposito|abono|transf\.*? a favor)/i', $textLower)) {
            if (!str_contains($textLower, 'plin')) $type = 'DEPOSITO';
        } elseif (preg_match('/^(mant\.|comis\.|itf|estado de cta|seg\.|membresia)/i', $textLower)) {
            $type = 'MANTENIMIENTO';
        } elseif (preg_match('/^(bcp\s*-|tran\.ctas|transf\.)/i', $textLower)) {
            $type = 'TRANSFERENCIA';
        } elseif (preg_match('/(izi\*|niubiz|vendemas)/i', $textLower)) {
            $type = 'POS_NIUBIZ';
        } elseif (preg_match('/(culqi)/i', $textLower)) {
            $type = 'POS_CULQI';
        } elseif (preg_match('/(plin)/i', $textLower)) {
            $type = 'PLIN';
        } elseif (preg_match('/(yape|yq-)/i', $textLower)) {
            $type = 'YAPE';
        }

        if (str_contains($textLower, 'plin')) $type = 'PLIN';

        // 2. LIMPIEZA DE "BASURA"
        $clean = $textLower;
        $garbage = [
            'transf.bco.bbva',
            'tran.ctas.terc.bm',
            'tran.ctas.terc.hk',
            'tra o/cta - agente',
            'abono plin',
            'abon plin',
            'ext plin',
            'pago de impuestos',
            'bcp -',
            'bcp ',
            'yq-izi*',
            'yq-izi',
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

        foreach ($garbage as $trash) {
            $clean = preg_replace('/(^|\s|[\*\-\.])' . preg_quote($trash, '/') . '/i', ' ', $clean);
        }

        // --- MEJORA: Limpieza de enmascaramiento de Yape ---
        // Elimina "***", "**", y secuencias como "M***A" dejando solo letras legibles si es posible
        // O simplemente quitamos todos los asteriscos
        $clean = str_replace('*', '', $clean);

        // Eliminar Fechas
        $clean = preg_replace('/\b(ene|feb|mar|abr|may|jun|jul|ago|set|sep|oct|nov|dic)[a-z]*[\s\-\.]*?(20)?[0-9]{2}\b/i', '', $clean);

        // 3. NORMALIZACIÓN FINAL
        $clean = preg_replace('/[^a-z0-9\s]/', '', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        // Si la limpieza dejó la cadena vacía (ej: "***"), usamos el original
        if (strlen($clean) < 2) $clean = preg_replace('/[^a-z0-9\s]/', '', $textLower);

        return ['type' => $type, 'entity' => $clean, 'sanitized_description' => $textLower];
    }
}
