<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\SpendingHabit;
use App\Enums\SpendingRhythm;

/**
 * Renders the answer to "¿qué es fijo y qué decido yo?".
 *
 * The answer is the SPLIT, not the list. How much of a typical month simply
 * arrives and how much is chosen is the figure that changes what someone does
 * next; the categories underneath are the evidence for it. So the two totals come
 * from every classified category and the bullets are the largest few — the same
 * Pareto shape as {@see MonthOverMonthComposer}, honest for the same reason: the
 * headline is never derived from the bullets.
 *
 * Never returns null. Same two inherited rules: facts rather than instructions
 * (ADR-0009), plain text with `•` bullets (design.md D10).
 */
final class SpendingRhythmComposer
{
    /**
     * @param  SpendingHabit[]  $habits  ordered by monthly average, largest first.
     * @param  int  $months  how many complete months the window covered.
     * @param  int  $maxCategories  how many categories each list names.
     */
    public function compose(array $habits, int $months, int $maxCategories): string
    {
        // Una categoria sin gasto en toda la ventana promedia cero. Es cierto y no
        // es nada, y una linea que dice "S/ 0.00 por mes" solo ocupa lugar.
        $habits = array_values(array_filter($habits, fn (SpendingHabit $h): bool => $h->monthlyAverage > 0.0));

        if ($habits === []) {
            return "No tengo gastos en los últimos {$months} meses completos, así que todavía no puedo separar "
                .'lo fijo de lo que decidís.';
        }

        $fixed = $this->withRhythm($habits, SpendingRhythm::FIXED);
        $chosen = $this->withRhythm($habits, SpendingRhythm::DISCRETIONARY);
        $tooNew = $this->withRhythm($habits, SpendingRhythm::TOO_NEW);

        $fixedTotal = $this->total($fixed);
        $chosenTotal = $this->total($chosen);
        $classifiedTotal = $fixedTotal + $chosenTotal;

        if ($classifiedTotal <= 0.0) {
            return "Mirando los últimos {$months} meses completos, todavía no tengo suficiente historia en ninguna "
                .'categoría como para decir qué es fijo y qué decidís vos. Preguntame de nuevo en un par de meses.';
        }

        $sections = [
            "Mirando los últimos {$months} meses completos, gastás en promedio "
            .$this->money($classifiedTotal).' por mes.',
        ];

        if ($fixed !== []) {
            $sections[] = $this->section(
                'Llega solo — '.$this->money($fixedTotal).', el '.$this->share($fixedTotal, $classifiedTotal).':',
                $fixed,
                $maxCategories,
                'casi siempre igual',
            );
        }

        if ($chosen !== []) {
            $sections[] = $this->section(
                'Lo decidís vos — '.$this->money($chosenTotal).', el '.$this->share($chosenTotal, $classifiedTotal).':',
                $chosen,
                $maxCategories,
                'cambia mes a mes',
            );
        }

        if ($tooNew !== []) {
            // Se nombra en vez de omitirse. Un reparto que se calla una parte del
            // mes se lee como si cubriera todo, y ese es justamente el error que
            // este tercer estado existe para no cometer.
            $sections[] = $this->tooNewSection($tooNew, $maxCategories);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  SpendingHabit[]  $habits
     * @return SpendingHabit[]
     */
    private function withRhythm(array $habits, SpendingRhythm $rhythm): array
    {
        return array_values(array_filter($habits, fn (SpendingHabit $h): bool => $h->is($rhythm)));
    }

    /** @param SpendingHabit[] $habits */
    private function total(array $habits): float
    {
        return array_sum(array_map(fn (SpendingHabit $h): float => $h->monthlyAverage, $habits));
    }

    /** @param SpendingHabit[] $habits */
    private function section(string $heading, array $habits, int $maxCategories, string $qualifier): string
    {
        $lines = array_map(
            fn (SpendingHabit $h): string => "• {$h->name}: {$this->money($h->monthlyAverage)} por mes, {$qualifier}.",
            array_slice($habits, 0, max($maxCategories, 0)),
        );

        return $heading."\n".implode("\n", $lines);
    }

    /** @param SpendingHabit[] $habits */
    private function tooNewSection(array $habits, int $maxCategories): string
    {
        $lines = array_map(
            fn (SpendingHabit $h): string => "• {$h->name}: {$this->money($h->monthlyAverage)} por mes, "
                .$this->history($h->monthsOfHistory).' de historia.',
            array_slice($habits, 0, max($maxCategories, 0)),
        );

        return "Todavía no puedo clasificar estas, les falta historia:\n".implode("\n", $lines);
    }

    private function history(int $months): string
    {
        return $months === 1 ? '1 mes' : "{$months} meses";
    }

    private function share(float $part, float $whole): string
    {
        return round($part / $whole * 100).'%';
    }

    private function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }
}
