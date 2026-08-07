<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\Models\CoachingObservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The spoken-observation memory that lets the scheduled sweep and the
 * capture-time check share one voice without any per-user job serialisation
 * (design.md D6).
 *
 * `claim()` reuses the exact claim-by-INSERT pattern already established three
 * times in this codebase (`ChannelUpdateDeduplicator::claim`,
 * `ChannelIdentityLinker::linkWhatsApp`, `ChannelLinkTokenRedeemer::redeem`): a
 * "have I seen this?" query followed by an insert leaves a window where two
 * concurrent callers both read nothing and both decide to speak, which is
 * exactly the duplicate the unique index exists to prevent. The unique index on
 * `(user_id, period_month, subject_key, band)` is the arbiter — this class only
 * decides "never speak downward" via `highestBandFor()`, and that decision is
 * explicitly NOT correctness-critical: under a race the worst outcome is one
 * extra message that was genuinely warranted, never a duplicate band.
 */
class SpokenObservationLedger
{
    private const BAND_SEVERITY = [
        'projected_over' => 1,
        'over_budget' => 2,
    ];

    /**
     * Claims a band by inserting it. Returns true the first time a
     * (user, period_month, subject_key, band) is spoken, false for every replay —
     * by this caller or by whichever concurrent caller already claimed it.
     */
    public function claim(
        int $userId,
        CarbonImmutable $periodMonth,
        string $subjectKey,
        string $band,
        ?int $categoryId,
        bool $isLumpy,
        float $budgetAmount,
        float $spentAmount,
        ?float $projectedAmount,
        int $dayOfMonth,
        string $entryPoint,
    ): bool {
        try {
            // Savepoint propio: en PostgreSQL una violacion de constraint aborta
            // la transaccion que la contiene, asi que sin esto atrapar la
            // excepcion dejaria inservible cualquier transaccion que nos
            // envuelva (el mismo defecto de forma que ya se corrigio tres veces
            // en este codebase).
            DB::transaction(fn () => CoachingObservation::create([
                'user_id' => $userId,
                'period_month' => self::normalisePeriod($periodMonth),
                'subject_key' => $subjectKey,
                'category_id' => $categoryId,
                'band' => $band,
                'is_lumpy' => $isLumpy,
                'budget_amount' => $budgetAmount,
                'spent_amount' => $spentAmount,
                'projected_amount' => $projectedAmount,
                'day_of_month' => $dayOfMonth,
                'entry_point' => $entryPoint,
                'spoken_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * The highest-severity band already spoken this month for a subject, or null
     * when nothing has been spoken yet.
     *
     * The bands form a strict severity ladder (design.md D6):
     * `projected_over` (1) < `over_budget` (2). `blind` is not comparable — it is
     * only ever claimed once per (user, period_month, 'blindness'), so its
     * presence, not its rank, is what matters, and it is returned as-is.
     */
    public function highestBandFor(int $userId, CarbonImmutable $periodMonth, string $subjectKey): ?string
    {
        $bands = CoachingObservation::query()
            ->where('user_id', $userId)
            ->where('period_month', self::normalisePeriod($periodMonth))
            ->where('subject_key', $subjectKey)
            ->pluck('band');

        if ($bands->isEmpty()) {
            return null;
        }

        return $bands->reduce(
            fn (?string $highest, string $band): string => $highest === null || $this->severity($band) > $this->severity($highest)
                ? $band
                : $highest,
            null,
        );
    }

    /**
     * Marks the given observations as delivered: `reply()` returned without
     * throwing.
     *
     * This is NOT proof of delivery (design.md D7) — `TelegramChannel::reply()`
     * logs and swallows non-2xx responses and returns void. It only separates
     * "the worker died between claim and send" from "the send returned", which
     * is the operator-queryable signal a row with `spoken_at` set and
     * `delivered_at` NULL represents.
     *
     * @param  int[]  $observationIds
     */
    public function confirmDelivered(array $observationIds): void
    {
        if ($observationIds === []) {
            return;
        }

        CoachingObservation::query()
            ->whereIn('id', $observationIds)
            ->update(['delivered_at' => now()]);
    }

    /**
     * Collapses any instant in a month to that month's first day.
     *
     * The unique index is the only thing standing between the owner and a
     * duplicate message, and it can only bind if every write for the same month
     * produces the same `period_month`. Leaving that to caller discipline would
     * make the guarantee a convention; doing it here makes it structural.
     */
    private static function normalisePeriod(CarbonImmutable $periodMonth): string
    {
        return $periodMonth->startOfMonth()->toDateString();
    }

    private function severity(string $band): int
    {
        return self::BAND_SEVERITY[$band] ?? 0;
    }
}
