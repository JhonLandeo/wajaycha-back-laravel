<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\BlindnessObservation;
use App\DTOs\Coaching\CoachingCause;
use App\DTOs\Coaching\PaceObservation;
use InvalidArgumentException;

/**
 * Turns a claimed observation into the sentence the coach actually says
 * (design.md §6). The shape is always "the fact and its cause, then stop" — no
 * advice, no suggestion, no next step, no question, no buttons.
 *
 * Pure PHP: values in, Spanish strings out. No facades this class does not need,
 * which is what makes it unit-testable without a database (`tests/Unit/Coaching`).
 *
 * D8 (non-negotiable): this class is only ever handed `PaceObservation`,
 * `CoachingCause` and `BlindnessObservation` — none of which carries an
 * `actual_percentage` field or any Pareto bucket share. There is structurally no
 * channel for that number to reach a rendered message. The only percentages this
 * class ever computes are `amount / spent` for one merchant/transaction, matching
 * design.md D8's two permitted percentages.
 *
 * D10: output is plain text. No Markdown/HTML markers are ever emitted, because
 * `TelegramChannel::reply()` posts `sendMessage` without `parse_mode` and
 * `detail_name` is user-controlled content that would make either mode injectable.
 */
final class CoachingMessageComposer
{
    /**
     * Joins every observation the caller decided to speak, in the order given
     * (severity order is the evaluator's concern, not this class's), followed by
     * the blindness sentence last when one was detected (design.md §5.1: "blindness
     * last"). Composition rule 1 (design.md §6): multiple observations join with a
     * blank line between them.
     *
     * @param  PaceObservation[]  $observations
     */
    public function compose(array $observations, ?BlindnessObservation $blindness = null): string
    {
        $messages = array_map($this->composeObservation(...), $observations);

        if ($blindness !== null) {
            $messages[] = $this->composeBlindness($blindness);
        }

        return implode("\n\n", $messages);
    }

    /**
     * One of the three pace/level shapes from design.md §6's table, chosen by
     * `$observation->band` and `$observation->isLumpy`. `$observation->cause` is
     * expected to be a `CoachingCause` or `null` — `null` happens when no cause was
     * ever computed (defensive; design.md D9 makes it structurally near-impossible
     * whenever `spent > 0`, but this class never fabricates one either way).
     */
    public function composeObservation(PaceObservation $observation): string
    {
        $fact = $this->composeFact($observation);
        $cause = $observation->cause instanceof CoachingCause
            ? $this->composeCause($observation, $observation->cause)
            : null;

        return $cause === null ? $fact : "{$fact} {$cause}";
    }

    /**
     * The fourth shape from design.md §6: the one, aggregate blindness sentence.
     */
    public function composeBlindness(BlindnessObservation $blindness): string
    {
        $count = count($blindness->categoryNames);
        $categoryWord = $this->pluralize($count, 'categoría', 'categorías');
        $names = implode(', ', $blindness->categoryNames);
        $total = $this->money($blindness->totalSpent);

        return "No puedo mirar {$count} {$categoryWord} sin presupuesto: {$names}. Este mes registraste {$total} ahí.";
    }

    /**
     * The first sentence every pace/level shape shares: category, spend, budget,
     * day, and either the projection (`projected_over`) or "ya pasaste el
     * presupuesto" (`over_budget`, linear or lumpy — lumpiness never adds a
     * projection, design.md rule "over_budget, lumpy … no projection").
     *
     * Envelope bands leave through {@see composeEnvelopeFact()} before any of
     * that: their sentence is anchored to the year, not to a day of the month,
     * and saying "el día 15" about a yearly budget would invite exactly the
     * monthly reading `budget_period` exists to prevent.
     */
    private function composeFact(PaceObservation $observation): string
    {
        if ($observation->isEnvelope()) {
            return $this->composeEnvelopeFact($observation);
        }

        $spent = $this->money($observation->spent);
        $budget = $this->money($observation->budget);

        // La banda decide la frase, nunca la ausencia de un dato. Un projected_over
        // sin proyeccion es un error de programacion, y caer al texto de la otra banda
        // le diria al usuario que ya se paso cuando no se paso: una mentira con cara de
        // dato, que es exactamente lo que este coach no puede permitirse.
        $status = match ($observation->band) {
            'projected_over' => $observation->projected !== null
                ? "a este ritmo cerrás en {$this->money($observation->projected)}"
                : throw new InvalidArgumentException(
                    "PaceObservation {$observation->subjectKey} llego con banda projected_over y sin proyeccion."
                ),
            'over_budget' => 'ya pasaste el presupuesto',
            default => throw new InvalidArgumentException(
                "PaceObservation {$observation->subjectKey} llego con una banda desconocida: {$observation->band}."
            ),
        };

        return "{$observation->name}: {$spent} de {$budget} el día {$observation->dayOfMonth}, {$status}.";
    }

    /**
     * The two envelope shapes. Both state the year's position against the year's
     * budget, and neither carries a projection — there is no field on the
     * observation that could supply one.
     *
     * `envelope_exceeded` says "ya pasaste el presupuesto anual" only when the
     * YEAR's spend passed the YEAR's budget. That word is the correction this
     * whole change is for: a single consultation against a monthly figure used
     * to produce the same sentence about a budget the user had not exceeded at
     * all, and a coach that cries wolf once is a coach that gets muted.
     */
    private function composeEnvelopeFact(PaceObservation $observation): string
    {
        $spent = $this->money($observation->spent);
        $budget = $this->money($observation->budget);

        $status = match ($observation->band) {
            'envelope_exceeded' => 'ya pasaste el presupuesto anual',
            'envelope_consumed' => $observation->monthsRemaining !== null
                ? sprintf(
                    'llevás el %d%% consumido y %s %d %s',
                    $this->share($observation->spent, $observation->budget),
                    $this->pluralize($observation->monthsRemaining, 'queda', 'quedan'),
                    $observation->monthsRemaining,
                    $this->pluralize($observation->monthsRemaining, 'mes', 'meses'),
                )
                // Same contract as the monthly bands: a band whose defining
                // number is missing is a programming error, and falling back to
                // the other band's wording would tell the user they exceeded a
                // budget they have not exceeded.
                : throw new InvalidArgumentException(
                    "PaceObservation {$observation->subjectKey} llego con banda envelope_consumed y sin meses restantes."
                ),
            default => throw new InvalidArgumentException(
                "PaceObservation {$observation->subjectKey} llego con una banda de sobre desconocida: {$observation->band}."
            ),
        };

        return "{$observation->name}: {$spent} de {$budget} al año, {$status}.";
    }

    /**
     * The second sentence, when a cause is known. The refund guard (design.md §6
     * rule 2) applies identically to both shapes: when the cause's amount exceeds
     * `spent`, the share is omitted and the merchant is still named — a share above
     * 100% is never rendered.
     */
    private function composeCause(PaceObservation $observation, CoachingCause $cause): string
    {
        if ($observation->isEnvelope()) {
            return $this->composeEnvelopeCause($cause);
        }

        $isRefund = $cause->amount > $observation->spent;

        return $observation->isLumpy
            ? $this->composeLumpyCause($observation, $cause, $isRefund)
            : $this->composeLinearCause($observation, $cause, $isRefund);
    }

    /**
     * What moved the envelope this month. Deliberately carries no percentage.
     *
     * Every other cause shape renders `amount / spent`, and for an envelope
     * those two are measured over different windows: the cause query is
     * month-scoped (FinancialCoachingService::causeFor) while `spent` is the
     * year to date. "El 39% son 1 compra" would be a month's purchase divided by
     * a year's spending — a number that is arithmetically valid and means
     * nothing. Stating the amount is honest; stating the share is not.
     */
    private function composeEnvelopeCause(CoachingCause $cause): string
    {
        $amount = $this->money($cause->amount);
        $when = $cause->transactionDate !== null ? " el {$cause->transactionDate->day}" : '';

        return "Este mes fue {$amount} en {$cause->merchantName}{$when}.";
    }

    private function composeLinearCause(PaceObservation $observation, CoachingCause $cause, bool $isRefund): string
    {
        $purchaseWord = $this->pluralize($cause->frequency, 'compra', 'compras');
        $count = "{$cause->frequency} {$purchaseWord}";

        if ($isRefund) {
            return "Fueron {$count} de {$cause->merchantName}.";
        }

        $share = $this->share($cause->amount, $observation->spent);

        return "El {$share}% son {$count} de {$cause->merchantName}.";
    }

    private function composeLumpyCause(PaceObservation $observation, CoachingCause $cause, bool $isRefund): string
    {
        $amount = $this->money($cause->amount);
        // Sin fecha se omite el fragmento entero. Interpolar un null deja "el ." en un
        // mensaje que ve el usuario.
        $when = $cause->transactionDate !== null ? " el {$cause->transactionDate->day}" : '';

        if ($isRefund) {
            return "Fue una sola compra: {$cause->merchantName}, {$amount}{$when}.";
        }

        $share = $this->share($cause->amount, $observation->spent);

        return "El {$share}% es una sola compra: {$cause->merchantName}, {$amount}{$when}.";
    }

    private function share(float $amount, float $spent): int
    {
        return $spent > 0.0 ? (int) round($amount / $spent * 100) : 0;
    }

    private function pluralize(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }

    private function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }
}
