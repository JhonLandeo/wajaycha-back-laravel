<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\DailyAllowance;
use App\DTOs\Coaching\MonthCursor;

/**
 * Renders the answer to "¿cuánto puedo gastar hoy?".
 *
 * **Never returns null**, and that is the one line separating this class from
 * {@see BudgetDigestComposer}, which returns null constantly. The digest speaks
 * unprompted every morning, so silence is how it avoids becoming wallpaper. This
 * text only exists because someone pressed a button asking for it, and a question
 * that gets no reply reads as a broken bot. Every branch below ends in a sentence,
 * including the branch where there is nothing to divide.
 *
 * Two rules inherited unchanged from the rest of the coaching voice:
 *
 * - **Facts, never instructions** (ADR-0009). "Podés gastar S/ 42.00 por día" is a
 *   figure derived from the reader's own budget and the calendar. "Gastá menos en
 *   Comida" is an order, and nothing here has a path to producing one.
 * - **Plain text** (design.md D10). `TelegramChannel::reply()` posts without
 *   `parse_mode` and category names are user-controlled, so the bullet is `•` —
 *   neither a Markdown nor an HTML marker, unlike a leading `-` or `*`.
 */
final class DailyAllowanceComposer
{
    /**
     * @param  DailyAllowance[]  $allowances  already ordered by the calculator.
     */
    public function compose(array $allowances, MonthCursor $cursor): string
    {
        $daysLeft = $cursor->daysLeft();
        $days = $this->days($daysLeft);

        if ($allowances === []) {
            // No es un fallo ni un silencio: es la respuesta. Y dice cual es el
            // paso siguiente sin ordenarlo, porque sin un presupuesto mensual no
            // hay ningun numero honesto que devolver.
            return 'No tenés presupuestos mensuales cargados, así que no hay un límite diario que calcular. '
                .'Ponéle un monto mensual a una categoría de gasto y esta pregunta empieza a tener respuesta.';
        }

        $withRoom = array_values(array_filter($allowances, fn (DailyAllowance $a): bool => $a->hasRoom()));
        $spent = array_values(array_filter($allowances, fn (DailyAllowance $a): bool => ! $a->hasRoom()));

        if ($withRoom === []) {
            $sections = ["Quedan {$days} de mes y ya no queda margen en ningún presupuesto mensual."];
            $sections[] = $this->section('Pasados:', $spent, $this->overLine(...));

            return implode("\n\n", $sections);
        }

        $total = array_sum(array_map(fn (DailyAllowance $a): float => $a->perDay, $withRoom));

        // "mensuales" no es relleno: los sobres anuales no entran en esta cuenta
        // y el lector tiene que poder ver que no entran, sin que se lo expliquen.
        $sections = [
            "Quedan {$days} de mes. Podés gastar hoy {$this->money($total)} "
            .'repartidos entre tus presupuestos mensuales:',
        ];

        $sections[] = $this->section('', $withRoom, $this->roomLine(...));

        if ($spent !== []) {
            $sections[] = $this->section('Estos ya no tienen margen:', $spent, $this->overLine(...));
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  DailyAllowance[]  $allowances
     * @param  callable(DailyAllowance): string  $line
     */
    private function section(string $heading, array $allowances, callable $line): string
    {
        $body = implode("\n", array_map(
            fn (DailyAllowance $a): string => '• '.$line($a),
            $allowances,
        ));

        return $heading === '' ? $body : $heading."\n".$body;
    }

    /**
     * The daily figure leads and the total follows, because the daily figure is
     * the one the reader can hold against the thing they are about to buy — which
     * is the only reason anyone asks this question standing in a shop.
     */
    private function roomLine(DailyAllowance $allowance): string
    {
        return "{$allowance->name}: {$this->money($allowance->perDay)} por día, "
            ."{$this->money($allowance->remaining)} hasta fin de mes.";
    }

    private function overLine(DailyAllowance $allowance): string
    {
        $over = $this->money(abs($allowance->remaining));

        return "{$allowance->name}: {$this->money($allowance->spent)} de "
            ."{$this->money($allowance->budget)}, {$over} encima.";
    }

    /** "1 día" and "11 días" — the plural is the sort of thing nobody reports and everybody notices. */
    private function days(int $daysLeft): string
    {
        return $daysLeft === 1 ? '1 día' : "{$daysLeft} días";
    }

    private function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }
}
