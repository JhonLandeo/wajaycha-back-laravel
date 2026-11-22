<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a recurring charge arrives.
 *
 * Derived and never stored: nothing in the schema says "monthly". The detector
 * reads the spacing between charges and this enum names what it found.
 *
 * Only three rhythms are recognised on purpose. A charge that arrives every two
 * months, or every 45 days, is a real pattern but not one this reading can call
 * with the same confidence; naming it would invite a list that is half guesses.
 */
enum RecurringCadence: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case ANNUAL = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'Semanal',
            self::MONTHLY => 'Mensual',
            self::ANNUAL => 'Anual',
        };
    }

    /**
     * Typical days between two charges. A month is 30 and a year 365 because
     * both are used as a ratio, not as a calendar: the detector compares the
     * observed spacing against these, and a fixed reference keeps two runs over
     * the same data identical.
     */
    public function nominalDays(): int
    {
        return match ($this) {
            self::WEEKLY => 7,
            self::MONTHLY => 30,
            self::ANNUAL => 365,
        };
    }

    /**
     * Charges required before this rhythm can be claimed. An annual charge only
     * appears twice in a two-year window, so demanding three would make annual
     * subscriptions undetectable by construction.
     */
    public function minOccurrences(): int
    {
        return match ($this) {
            self::ANNUAL => 2,
            self::WEEKLY, self::MONTHLY => 3,
        };
    }

    /**
     * How late the next charge may be before the pattern is treated as dead
     * rather than merely unsettled.
     *
     * Without this, a subscription cancelled eight months ago still has twelve
     * monthly charges in a two-year window and would be reported as something
     * the user still pays. A yearly plan gets more room because its renewal
     * drifts with the calendar; a weekly one gets less because two missed weeks
     * is already a different habit.
     */
    public function graceDays(): int
    {
        return match ($this) {
            self::WEEKLY => 15,
            self::MONTHLY => 15,
            self::ANNUAL => 60,
        };
    }
}
