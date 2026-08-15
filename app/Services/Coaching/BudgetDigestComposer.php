<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;

/**
 * The morning status board: where every budget stands today.
 *
 * This is NOT the coach, and the difference is the reason it is a separate class
 * rather than another shape inside {@see CoachingMessageComposer}.
 *
 * The coach narrates a CHANGE — a category crossed into a worse band — and
 * `SpokenObservationLedger` guarantees it says each one at most once a month.
 * That guarantee is the whole design: a voice that repeats itself daily stops
 * being read, and the categories most worth hearing about are the ones the
 * reader has already learned to skip.
 *
 * A digest states the CURRENT POSITION, every morning, whether or not anything
 * moved. It therefore never touches the ledger and never claims anything. The
 * two can coexist precisely because they are answering different questions:
 * "what changed?" at 20:00, "where am I standing?" before the day's decisions.
 *
 * Two rules it inherits from the coach unchanged:
 *
 * - **Facts, never instructions** (ADR-0009). "Te quedan S/ 100.00" is a
 *   figure the reader can act on. "No gastes en Comida" is an order, and this
 *   class has no channel through which one could reach a rendered message.
 * - **Plain text** (design.md D10). `TelegramChannel::reply()` posts without
 *   `parse_mode` and category names are user-controlled, so no Markdown or HTML
 *   marker is ever emitted. The bullet is `•`, which is not a marker in either
 *   syntax — a leading `-` or `*` would become one the day someone enables a
 *   parse mode.
 */
final class BudgetDigestComposer
{
    /**
     * Groups the observations into the three questions the owner asked for —
     * what is already over, what is heading there, and what is left of a yearly
     * envelope — and returns null when none of them has an answer.
     *
     * Null, not "todo bien". Silence is a first-class outcome here for the same
     * reason it is in the coach (design.md §6 rule 6): a message that arrives
     * every morning regardless of content becomes wallpaper, and then the one
     * morning it matters looks exactly like the ninety that did not.
     *
     * @param  PaceObservation[]  $observations
     */
    public function compose(array $observations, MonthCursor $cursor): ?string
    {
        $exceeded = $this->withBand($observations, 'over_budget');
        $heading = $this->withBand($observations, 'projected_over');
        $envelopes = array_values(array_filter(
            $observations,
            fn (PaceObservation $o): bool => $o->isEnvelope()
        ));

        if ($exceeded === [] && $heading === [] && $envelopes === []) {
            return null;
        }

        $sections = ["Presupuestos al día {$cursor->day}."];

        if ($exceeded !== []) {
            $sections[] = $this->section('Ya pasaste:', $exceeded, $this->exceededLine(...));
        }

        if ($heading !== []) {
            $sections[] = $this->section('Vas camino a pasarte:', $heading, $this->headingLine(...));
        }

        if ($envelopes !== []) {
            $sections[] = $this->section('Sobres anuales:', $envelopes, $this->envelopeLine(...));
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  PaceObservation[]  $observations
     * @return PaceObservation[]
     */
    private function withBand(array $observations, string $band): array
    {
        return array_values(array_filter(
            $observations,
            fn (PaceObservation $o): bool => $o->band === $band && ! $o->isEnvelope()
        ));
    }

    /**
     * @param  PaceObservation[]  $observations
     * @param  callable(PaceObservation): string  $line
     */
    private function section(string $heading, array $observations, callable $line): string
    {
        return $heading."\n".implode("\n", array_map(
            fn (PaceObservation $o): string => '• '.$line($o),
            $observations,
        ));
    }

    private function exceededLine(PaceObservation $observation): string
    {
        $over = $this->money($observation->spent - $observation->budget);

        return "{$observation->name}: {$this->money($observation->spent)} de "
            ."{$this->money($observation->budget)}, {$over} encima.";
    }

    /**
     * The line that answers "cuánto me queda antes de pasarme". The remaining
     * figure leads and the projection follows, because the first is a number the
     * reader can hold against the next purchase and the second is a forecast.
     */
    private function headingLine(PaceObservation $observation): string
    {
        $remaining = $this->money($observation->budget - $observation->spent);
        $line = "{$observation->name}: {$this->money($observation->spent)} de "
            ."{$this->money($observation->budget)}, te quedan {$remaining}";

        // Defensivo: la banda projected_over sin proyeccion es un error de
        // programacion, pero un parte diario no es el lugar para reventar. La
        // frase se corta antes del pronostico y el dato que importa —cuanto
        // queda— igual llega.
        return $observation->projected !== null
            ? "{$line} y a este ritmo cerrás en {$this->money($observation->projected)}."
            : "{$line}.";
    }

    /**
     * Envelopes read against the year, never against the month — the same
     * anchoring `CoachingMessageComposer::composeEnvelopeFact()` uses, and for
     * the same reason: "el día 15" beside an annual figure invites the monthly
     * reading `budget_period` exists to prevent.
     */
    private function envelopeLine(PaceObservation $observation): string
    {
        $spent = $this->money($observation->spent);
        $budget = $this->money($observation->budget);

        if ($observation->band === 'envelope_exceeded') {
            $over = $this->money($observation->spent - $observation->budget);

            return "{$observation->name}: {$spent} de {$budget} al año, {$over} encima.";
        }

        $remaining = $this->money($observation->budget - $observation->spent);

        return "{$observation->name}: {$spent} de {$budget} al año, te quedan {$remaining}.";
    }

    private function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }
}
