<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\SpendShift;

/**
 * Renders the answer to "¿qué cambió desde el mes pasado?".
 *
 * The headline is the real total movement and the bullets are the categories that
 * explain most of it — the same shape {@see CoachingMessageComposer} uses, for the
 * same reason: the fact, then its cause, then stop. The bullets are ranked and
 * capped, so they deliberately do not add up to the headline. That is a feature
 * of a Pareto reading and a lie only if the headline were derived from them, which
 * is why it is not: the total comes from every row, the bullets from the ones
 * worth naming.
 *
 * **Never returns null**, like {@see DailyAllowanceComposer} and unlike
 * {@see BudgetDigestComposer}. This text exists because someone pressed a button.
 *
 * Same two inherited rules: facts rather than instructions (ADR-0009), and plain
 * text with `•` bullets because `TelegramChannel::reply()` posts without
 * `parse_mode` and category names are user-controlled (design.md D10).
 */
final class MonthOverMonthComposer
{
    /**
     * @param  SpendShift[]  $shifts  already ranked and capped by the comparator.
     * @param  float  $currentTotal  every sol spent in the current window, including
     *                               categories too small to have earned a bullet and
     *                               spending the categoriser has not filed.
     * @param  float  $previousTotal  the same, for the comparable window.
     */
    public function compose(array $shifts, float $currentTotal, float $previousTotal, MonthCursor $cursor): string
    {
        if ($previousTotal <= 0.0) {
            // Sin base no hay comparacion, y decir "gastaste S/ 400 mas" contra un
            // mes vacio es cierto y no significa nada.
            return $currentTotal > 0.0
                ? 'No tengo gastos del mes pasado para comparar. En lo que va de este mes llevás '
                    .$this->money($currentTotal).'.'
                : 'Todavía no tengo gastos de este mes ni del anterior para comparar.';
        }

        $sections = [$this->headline($currentTotal - $previousTotal, $cursor)];

        $rose = array_values(array_filter($shifts, fn (SpendShift $s): bool => $s->rose()));
        $fell = array_values(array_filter($shifts, fn (SpendShift $s): bool => ! $s->rose()));

        if ($shifts === []) {
            $sections[] = 'Ninguna categoría se movió lo suficiente como para contarla.';

            return implode("\n\n", $sections);
        }

        if ($rose !== []) {
            $sections[] = $this->section('Subió:', $rose);
        }

        if ($fell !== []) {
            $sections[] = $this->section('Bajó:', $fell);
        }

        return implode("\n\n", $sections);
    }

    /**
     * The total, plus the span it covers — and, when the two spans are not equal,
     * the fact that they are not.
     *
     * The disclosure is not politeness. On 30 March the comparable window closes at
     * 28 February, so this month gets two more days than the one it is measured
     * against and the total is biased upward by however much those days hold. A
     * reader told the spans differ can discount it; a reader who is not told cannot.
     */
    private function headline(float $delta, MonthCursor $cursor): string
    {
        $span = $this->span($cursor->previousDaysCompared());

        $movement = match (true) {
            $delta > 0.0 => 'gastaste '.$this->money($delta).' más',
            $delta < 0.0 => 'gastaste '.$this->money(abs($delta)).' menos',
            default => 'gastaste exactamente lo mismo',
        };

        $headline = "Comparado con {$span} del mes pasado, {$movement}.";

        if ($cursor->previousDaysCompared() < $cursor->day) {
            $headline .= " El mes pasado fue más corto, así que la comparación cubre {$cursor->previousDaysCompared()} días "
                ."contra los {$cursor->day} que llevás de este.";
        }

        return $headline;
    }

    private function span(int $days): string
    {
        return $days === 1 ? 'el primer día' : "los primeros {$days} días";
    }

    /** @param SpendShift[] $shifts */
    private function section(string $heading, array $shifts): string
    {
        return $heading."\n".implode("\n", array_map(
            fn (SpendShift $s): string => '• '.$this->line($s),
            $shifts,
        ));
    }

    /**
     * A category that appeared or disappeared gets its own sentence.
     *
     * "S/ 220.00 contra S/ 0.00" is arithmetically the same statement and reads as
     * a comparison between two amounts, which is not what happened: one of them is
     * not a smaller number, it is the absence of the habit. Starting to spend on
     * something, and stopping, are the two most legible findings this question can
     * produce, and collapsing them into a subtraction throws that away.
     */
    private function line(SpendShift $shift): string
    {
        if ($shift->isNew()) {
            return "{$shift->name}: {$this->money($shift->current)}, y el mes pasado no gastaste nada acá.";
        }

        if ($shift->isGone()) {
            return "{$shift->name}: nada este mes, contra {$this->money($shift->previous)} del mes pasado.";
        }

        $direction = $shift->rose() ? 'más' : 'menos';

        return "{$shift->name}: {$this->money($shift->current)} contra {$this->money($shift->previous)}, "
            .$this->money(abs($shift->delta))." {$direction}.";
    }

    private function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }
}
