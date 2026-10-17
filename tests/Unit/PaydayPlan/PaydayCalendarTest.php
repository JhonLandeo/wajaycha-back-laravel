<?php

declare(strict_types=1);

use App\Services\PaydayPlan\PaydayCalendar;
use Carbon\CarbonImmutable;

function limaAt(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when, 'America/Lima');
}

it('computes the period month as the first day of the Lima month, not the UTC one', function () {
    // 2026-11-01 02:00 UTC is still 31 October 21:00 in Lima.
    $now = CarbonImmutable::parse('2026-11-01 02:00:00', 'UTC');

    expect((new PaydayCalendar)->periodMonth($now)->toDateString())->toBe('2026-10-01')
        ->and((new PaydayCalendar)->today($now)->toDateString())->toBe('2026-10-31');
});

it('moves to the next period month once Lima crosses midnight', function () {
    $now = CarbonImmutable::parse('2026-11-01 05:00:00', 'UTC'); // 00:00 Lima

    expect((new PaydayCalendar)->periodMonth($now)->toDateString())->toBe('2026-11-01');
});

it('places the payday date inside the current Lima month', function () {
    expect((new PaydayCalendar)->paydayOn(limaAt('2026-10-17 12:00'), 3)->toDateString())->toBe('2026-10-03')
        ->and((new PaydayCalendar)->paydayOn(limaAt('2026-02-10 12:00'), 28)->toDateString())->toBe('2026-02-28');
});

it('is eligible on payday itself and every day after, never before', function () {
    $calendar = new PaydayCalendar;

    expect($calendar->isEligible(limaAt('2026-10-02 23:59'), 3))->toBeFalse()
        ->and($calendar->isEligible(limaAt('2026-10-03 00:00'), 3))->toBeTrue()
        ->and($calendar->isEligible(limaAt('2026-10-04 09:00'), 3))->toBeTrue()
        ->and($calendar->isEligible(limaAt('2026-10-31 09:00'), 3))->toBeTrue();
});

it('has a payday date in February for day 28', function () {
    $calendar = new PaydayCalendar;

    expect($calendar->isEligible(limaAt('2026-02-27 09:00'), 28))->toBeFalse()
        ->and($calendar->isEligible(limaAt('2026-02-28 09:00'), 28))->toBeTrue();
});

it('judges eligibility on the Lima date even when given a UTC instant', function () {
    // 2026-10-03 03:00 UTC is 2 October 22:00 in Lima: not payday yet.
    expect((new PaydayCalendar)->isEligible(CarbonImmutable::parse('2026-10-03 03:00:00', 'UTC'), 3))->toBeFalse();
});

it('keeps retrying for the grace days after the first attempt and stops after', function () {
    $calendar = new PaydayCalendar;
    $firstAttempt = limaAt('2026-10-03 09:00');

    expect($calendar->isWithinGrace(limaAt('2026-10-04 09:00'), $firstAttempt, 3))->toBeTrue()
        ->and($calendar->isWithinGrace(limaAt('2026-10-06 09:00'), $firstAttempt, 3))->toBeTrue()  // edge: day +3
        ->and($calendar->isWithinGrace(limaAt('2026-10-07 09:00'), $firstAttempt, 3))->toBeFalse(); // day +4
});

it('measures the grace on Lima dates, not on 24 hour spans', function () {
    // First attempt late in the evening: day +3 still ends at Lima midnight of day +3.
    $firstAttempt = limaAt('2026-10-03 23:30');

    expect((new PaydayCalendar)->isWithinGrace(limaAt('2026-10-06 09:00'), $firstAttempt, 3))->toBeTrue()
        ->and((new PaydayCalendar)->isWithinGrace(limaAt('2026-10-07 00:05'), $firstAttempt, 3))->toBeFalse();
});

it('due date of the reminder is the day after the given instant, in Lima', function () {
    $calendar = new PaydayCalendar;

    expect($calendar->reminderDueOn(limaAt('2026-10-03 09:00'))->toDateString())->toBe('2026-10-04')
        ->and($calendar->reminderDueOn(limaAt('2026-10-31 09:00'))->toDateString())->toBe('2026-11-01')
        // 2026-11-01 02:00 UTC is 31 Oct in Lima, so the day after is 1 Nov.
        ->and($calendar->reminderDueOn(CarbonImmutable::parse('2026-11-01 02:00:00', 'UTC'))->toDateString())->toBe('2026-11-01');
});

it('tells whether a period month is the current Lima month', function () {
    $calendar = new PaydayCalendar;

    expect($calendar->isCurrentMonth(CarbonImmutable::parse('2026-10-01'), limaAt('2026-10-17 12:00')))->toBeTrue()
        ->and($calendar->isCurrentMonth(CarbonImmutable::parse('2026-09-01'), limaAt('2026-10-17 12:00')))->toBeFalse()
        ->and($calendar->isCurrentMonth(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01 02:00:00', 'UTC')))->toBeTrue();
});
